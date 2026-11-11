<?php

namespace App\Services\WorkflowDocumentation;

use Closure;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Renders Markdown to safe HTML with league/commonmark (bundled with Laravel).
 *
 * Raw HTML is escaped, unsafe link schemes are dropped, links to other
 * Markdown files become in-portal links, links to local files become
 * plain source references, and project paths in inline code are checked.
 */
class MarkdownRenderer
{
    protected ?MarkdownParser $parser = null;

    protected ?HtmlRenderer $htmlRenderer = null;

    /**
     * @var array<string, string>|null
     */
    protected ?array $classIndex = null;

    /**
     * Render a Markdown document.
     *
     * @param  string  $relativePath  Path of the document inside the docs directory.
     * @param  Closure(string): ?string  $resolveSlug  Maps a docs-relative .md path to a document slug.
     * @return array{html: string, title: ?string, summary: ?string, headings: list<array{id: string, text: string, level: int}>, sections: list<array{id: ?string, heading: ?string, text: string}>, references: list<array{path: string, verified: bool}>}
     */
    public function render(string $markdown, string $relativePath, string $slug, Closure $resolveSlug): array
    {
        $document = $this->parser()->parse($markdown);
        $references = [];

        $this->rewriteNodes($document, $relativePath, $slug, $resolveSlug, $references);

        $html = $this->htmlRenderer()->renderDocument($document)->getContent();
        $html = str_replace(['<table>', '</table>'], ['<div class="table-wrap"><table>', '</table></div>'], $html);

        return [
            'html' => $html,
            'title' => $this->firstHeading($document, 1),
            'summary' => $this->firstParagraph($document),
            'headings' => $this->headings($document),
            'sections' => $this->sections($document),
            'references' => array_values($references),
        ];
    }

    protected function parser(): MarkdownParser
    {
        return $this->parser ??= new MarkdownParser($this->environment());
    }

    protected function htmlRenderer(): HtmlRenderer
    {
        return $this->htmlRenderer ??= new HtmlRenderer($this->environment());
    }

    protected function environment(): Environment
    {
        static $environment = null;

        if ($environment !== null) {
            return $environment;
        }

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'heading_permalink' => [
                'apply_id_to_heading' => true,
                'insert' => 'none',
                'id_prefix' => 'h',
                'fragment_prefix' => 'h',
                'min_heading_level' => 1,
                'max_heading_level' => 4,
            ],
            'external_link' => [
                'internal_hosts' => [],
                'open_in_new_window' => true,
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);
        $environment->addExtension(new ExternalLinkExtension);

        return $environment;
    }

    /**
     * Rewrite links, images and inline code in place.
     *
     * @param  array<string, array{path: string, verified: bool}>  $references
     */
    protected function rewriteNodes(Document $document, string $relativePath, string $slug, Closure $resolveSlug, array &$references): void
    {
        $links = [];
        $images = [];
        $codes = [];

        foreach ($document->iterator() as $node) {
            match (true) {
                $node instanceof Link => $links[] = $node,
                $node instanceof Image => $images[] = $node,
                $node instanceof Code => $codes[] = $node,
                default => null,
            };
        }

        foreach ($links as $link) {
            $this->rewriteLink($link, $relativePath, $slug, $resolveSlug, $references);
        }

        foreach ($images as $image) {
            // Only remote HTTPS images are shown; local files are not served.
            if (! str_starts_with($image->getUrl(), 'https://')) {
                $image->replaceWith(new Text('[Image: '.($this->textOf($image) ?: basename($image->getUrl())).']'));
            } else {
                $image->data->set('attributes/loading', 'lazy');
                $image->data->set('attributes/referrerpolicy', 'no-referrer');
            }
        }

        foreach ($codes as $code) {
            $this->markReference($code, $references);
        }
    }

    /**
     * @param  array<string, array{path: string, verified: bool}>  $references
     */
    protected function rewriteLink(Link $link, string $relativePath, string $slug, Closure $resolveSlug, array &$references): void
    {
        $url = trim($link->getUrl());

        // External links, and links to pages on this site such as /docs-api-endpoints.
        if ($url === '' || preg_match('#^(https?:|mailto:)#i', $url) || preg_match('#^/(?!/)#', $url)) {
            return;
        }

        // Any other scheme (javascript:, data:, vbscript: ...) keeps only the link text.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) && ! preg_match('#^file:#i', $url)) {
            $this->unwrap($link);

            return;
        }

        if (str_starts_with($url, '#')) {
            $link->setUrl($this->documentUrl($slug, substr($url, 1)));

            return;
        }

        [$target, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        $target = rawurldecode($target);
        $docsDirectory = str_replace('\\', '/', trim((string) config('workflow-docs.source_directory', 'docs'), '/'));

        if (preg_match('#^file:#i', $target)) {
            $projectPath = $this->projectRelativePath($target);

            if ($projectPath === null) {
                $this->unwrap($link);

                return;
            }

            $target = $projectPath;
            $docsRelative = str_starts_with($target, $docsDirectory.'/') ? substr($target, strlen($docsDirectory) + 1) : null;
        } else {
            $docsRelative = $this->normalize(dirname($relativePath).'/'.$target);
            $target = $docsRelative === null ? $target : $docsDirectory.'/'.$docsRelative;
        }

        if ($docsRelative !== null && str_ends_with(strtolower($docsRelative), '.md')) {
            $targetSlug = $resolveSlug($docsRelative);

            if ($targetSlug !== null) {
                $link->setUrl($this->documentUrl($targetSlug, $fragment));

                return;
            }

            $link->setUrl('#');
            $link->data->set('attributes/class', 'doc-link-broken');
            $link->data->set('attributes/title', 'Unresolved link: '.$docsRelative);

            return;
        }

        // A link to a local file or folder: show it as a source reference.
        $reference = new Code($target === null ? '' : trim($target, '/'));
        $link->replaceWith($reference);
        $this->markReference($reference, $references);
    }

    /**
     * Mark inline code that names a project file or class as a source reference.
     *
     * @param  array<string, array{path: string, verified: bool}>  $references
     */
    protected function markReference(Code $code, array &$references): void
    {
        $literal = trim($code->getLiteral());
        $roots = implode('|', array_map('preg_quote', (array) config('workflow-docs.source_roots', [])));
        $path = null;

        if ($roots !== '' && preg_match('#^(?:'.$roots.')/[\w./\-]+$#', $literal) && ! str_contains($literal, '..')) {
            $path = rtrim($literal, '/');
        } elseif (preg_match('/^App(?:\\\\\w+)+$/', $literal)) {
            $path = 'app/'.str_replace('\\', '/', substr($literal, 4)).'.php';
        } elseif (preg_match('/^[A-Z]\w*(?:Service|Controller|Policy|Request|Resource|Middleware|Seeder)$/', $literal)) {
            $path = $this->classIndex()[$literal] ?? 'unresolved:'.$literal;
        }

        if ($path === null) {
            return;
        }

        $verified = ! str_starts_with($path, 'unresolved:') && file_exists(base_path($path));
        $displayPath = str_starts_with($path, 'unresolved:') ? $literal : $path;

        $code->data->set('attributes/class', 'source-ref '.($verified ? 'is-verified' : 'is-unresolved'));
        $code->data->set('attributes/title', $verified ? 'Source: '.$displayPath : 'Unresolved: '.$displayPath.' was not found in the codebase');
        $references[$displayPath] = ['path' => $displayPath, 'verified' => $verified];
    }

    /**
     * Map class basenames in app/ and database/ to their relative paths (no files are executed).
     *
     * @return array<string, string>
     */
    protected function classIndex(): array
    {
        if ($this->classIndex !== null) {
            return $this->classIndex;
        }

        $this->classIndex = [];

        foreach (['app', 'database/seeders', 'database/factories'] as $directory) {
            $path = base_path($directory);

            if (! is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relative = $directory.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($path) + 1));
                    $this->classIndex[$file->getBasename('.php')] ??= $relative;
                }
            }
        }

        return $this->classIndex;
    }

    protected function documentUrl(string $slug, ?string $fragment): string
    {
        $fragment = $fragment !== null && $fragment !== '' ? Str::slug(rawurldecode($fragment)) : null;

        return '#/doc/'.$slug.($fragment ? '?section=h-'.$fragment : '');
    }

    /**
     * Convert a file:// URL into a path relative to the project, if it is inside it.
     */
    protected function projectRelativePath(string $url): ?string
    {
        $path = str_replace('\\', '/', preg_replace('#^file:/*#i', '', $url));
        $base = str_replace('\\', '/', base_path());
        $base = ltrim($base, '/');

        if (! str_starts_with(strtolower($path), strtolower($base))) {
            return null;
        }

        $relative = $this->normalize(substr($path, strlen($base)));

        return $relative === null || $relative === '' ? null : $relative;
    }

    /**
     * Resolve "." and ".." segments. Returns null if the path escapes its root.
     */
    protected function normalize(string $path): ?string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    protected function unwrap(Node $node): void
    {
        foreach ($node->children() as $child) {
            $node->insertBefore($child);
        }

        $node->detach();
    }

    /**
     * @return list<array{id: string, text: string, level: int}>
     */
    protected function headings(Document $document): array
    {
        $headings = [];

        foreach ($document->iterator() as $node) {
            if ($node instanceof Heading && $node->getLevel() >= 2 && $node->getLevel() <= 3) {
                $headings[] = [
                    'id' => (string) $node->data->get('attributes/id', ''),
                    'text' => $this->textOf($node),
                    'level' => $node->getLevel(),
                ];
            }
        }

        return $headings;
    }

    /**
     * Split the document into searchable sections, one per heading.
     *
     * @return list<array{id: ?string, heading: ?string, text: string}>
     */
    protected function sections(Document $document): array
    {
        $sections = [['id' => null, 'heading' => null, 'text' => '']];

        foreach ($document->children() as $block) {
            if ($block instanceof Heading && $block->getLevel() <= 3) {
                $sections[] = ['id' => (string) $block->data->get('attributes/id', ''), 'heading' => $this->textOf($block), 'text' => ''];

                continue;
            }

            if ($block instanceof Paragraph || $block instanceof ListBlock || $block instanceof Table) {
                $current = &$sections[count($sections) - 1];
                $current['text'] = Str::limit(trim($current['text'].' '.$this->textOf($block)), 600, '');
                unset($current);
            }
        }

        return array_values(array_filter($sections, fn (array $section): bool => $section['heading'] !== null || $section['text'] !== ''));
    }

    protected function firstHeading(Document $document, int $level): ?string
    {
        foreach ($document->iterator() as $node) {
            if ($node instanceof Heading && $node->getLevel() === $level) {
                return $this->textOf($node);
            }
        }

        return null;
    }

    protected function firstParagraph(Document $document): ?string
    {
        foreach ($document->children() as $block) {
            if ($block instanceof Paragraph) {
                $text = $this->textOf($block);

                return $text === '' ? null : Str::limit($text, 220);
            }
        }

        return null;
    }

    protected function textOf(Node $node): string
    {
        $text = '';

        foreach ($node->iterator() as $child) {
            if ($child instanceof StringContainerInterface && ! $child instanceof AbstractBlock) {
                $text .= $child->getLiteral().' ';
            }
        }

        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
