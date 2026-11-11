<?php

namespace App\Services\WorkflowDocumentation;

use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Discovers and loads Markdown documents from the configured docs directory.
 *
 * Documents are only ever looked up by slug in the discovered index, so a
 * URL can never be turned into a filesystem path. Files outside the docs
 * directory, symlinks, hidden files and non-Markdown files are ignored.
 */
class MarkdownDocumentRepository
{
    /**
     * @var array<string, array{slug: string, relative_path: string, absolute_path: string, modified_at: int, size: int}>|null
     */
    protected ?array $files = null;

    public function __construct(
        protected MarkdownRenderer $renderer,
    ) {}

    /**
     * Get the real path of the docs directory.
     */
    public function basePath(): ?string
    {
        $path = realpath(base_path((string) config('workflow-docs.source_directory', 'docs')));

        return $path !== false && is_dir($path) ? $path : null;
    }

    /**
     * Discover Markdown files, keyed by slug.
     *
     * @return array<string, array{slug: string, relative_path: string, absolute_path: string, modified_at: int, size: int}>
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $this->files = [];
        $base = $this->basePath();

        if ($base === null) {
            return $this->files;
        }

        $directory = new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS);
        $filtered = new RecursiveCallbackFilterIterator($directory, function (SplFileInfo $file): bool {
            return ! str_starts_with($file->getFilename(), '.') && ! $file->isLink();
        });

        $iterator = new RecursiveIteratorIterator($filtered);
        $iterator->setMaxDepth((int) config('workflow-docs.max_depth', 4));

        foreach ($iterator as $file) {
            if (! $this->isAllowedFile($file, $base)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getRealPath(), strlen($base) + 1));
            $slug = $this->slugFor($relative);

            $this->files[$slug] ??= [
                'slug' => $slug,
                'relative_path' => $relative,
                'absolute_path' => $file->getRealPath(),
                'modified_at' => $file->getMTime(),
                'size' => $file->getSize(),
            ];
        }

        ksort($this->files);

        return $this->files;
    }

    /**
     * Get every document without its HTML (used for navigation and search).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values(array_map(function (array $document): array {
            unset($document['html']);

            return $document;
        }, array_map(fn (array $file): array => $this->load($file), $this->files())));
    }

    /**
     * Find a document by slug, including its rendered HTML.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        $file = $this->files()[$slug] ?? null;

        return $file === null ? null : $this->load($file);
    }

    /**
     * Map a docs-relative Markdown path to its slug, if the document exists.
     */
    public function slugForPath(string $relativePath): ?string
    {
        $slug = $this->slugFor($relativePath);

        return isset($this->files()[$slug]) ? $slug : null;
    }

    /**
     * Get a fingerprint of all documents, used to cache the index.
     */
    public function fingerprint(): string
    {
        return md5(serialize([
            array_map(fn (array $file): array => [$file['relative_path'], $file['modified_at'], $file['size']], $this->files()),
            config('workflow-docs'),
        ]));
    }

    /**
     * Build a URL-safe slug from a relative path, e.g. "workflows/Booking_Flow.md" → "workflows/booking-flow".
     */
    public function slugFor(string $relativePath): string
    {
        $withoutExtension = preg_replace('/\.md$/i', '', str_replace('\\', '/', $relativePath));

        return implode('/', array_map(
            fn (string $segment): string => Str::slug(str_replace('_', '-', $segment)),
            array_filter(explode('/', $withoutExtension), fn (string $segment): bool => $segment !== ''),
        ));
    }

    protected function isAllowedFile(SplFileInfo $file, string $base): bool
    {
        $realPath = $file->getRealPath();

        return $file->isFile()
            && ! $file->isLink()
            && strtolower($file->getExtension()) === 'md'
            && $realPath !== false
            && str_starts_with($realPath, $base.DIRECTORY_SEPARATOR)
            && $file->getSize() <= (int) config('workflow-docs.max_file_size', 512 * 1024);
    }

    /**
     * Load and render one document (cached by modification time and size).
     *
     * @param  array{slug: string, relative_path: string, absolute_path: string, modified_at: int, size: int}  $file
     * @return array<string, mixed>
     */
    protected function load(array $file): array
    {
        $build = fn (): array => $this->build($file);

        if (! config('workflow-docs.cache.enabled', true)) {
            return $build();
        }

        $key = 'workflow-docs:'.md5(serialize([$file['relative_path'], $file['modified_at'], $file['size'], array_keys($this->files()), config('workflow-docs')]));

        return Cache::remember($key, (int) config('workflow-docs.cache.ttl', 86400), $build);
    }

    /**
     * @param  array{slug: string, relative_path: string, absolute_path: string, modified_at: int, size: int}  $file
     * @return array<string, mixed>
     */
    protected function build(array $file): array
    {
        $raw = (string) file_get_contents($file['absolute_path']);
        [$frontMatter, $body] = $this->splitFrontMatter($raw);
        $manifest = (array) (((array) config('workflow-docs.documents', []))[$file['relative_path']] ?? []);
        $meta = array_merge($manifest, $frontMatter);

        $rendered = $this->renderer->render(
            $this->redact($body),
            $file['relative_path'],
            $file['slug'],
            fn (string $path): ?string => $this->slugForPath($path),
        );

        $statuses = (array) config('workflow-docs.statuses', []);
        $status = in_array($meta['status'] ?? null, $statuses, true) ? $meta['status'] : 'Needs Verification';
        $directory = str_contains($file['relative_path'], '/') ? Str::headline(dirname($file['relative_path'])) : null;

        return [
            'slug' => $file['slug'],
            'title' => (string) ($meta['title'] ?? $rendered['title'] ?? Str::headline(pathinfo($file['relative_path'], PATHINFO_FILENAME))),
            'summary' => $meta['summary'] ?? $rendered['summary'],
            'category' => (string) ($meta['category'] ?? $directory ?? 'Other documentation'),
            'status' => $status,
            'order' => (int) ($meta['order'] ?? 100),
            'source' => trim((string) config('workflow-docs.source_directory', 'docs'), '/').'/'.$file['relative_path'],
            'updated_at' => date('Y-m-d', $file['modified_at']),
            'notes' => array_values((array) ($meta['notes'] ?? [])),
            'sources' => $this->verifySources((array) ($meta['sources'] ?? [])),
            'references' => $rendered['references'],
            'headings' => $rendered['headings'],
            'sections' => $rendered['sections'],
            'html' => $rendered['html'],
        ];
    }

    /**
     * Read a simple YAML-style front matter block ("key: value" and "key: [a, b]" or "- item" lists).
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function splitFrontMatter(string $raw): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        if (! preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $raw, $match)) {
            return [[], $raw];
        }

        $data = [];
        $listKey = null;

        foreach (preg_split('/\R/', $match[1]) as $line) {
            if (preg_match('/^\s+-\s+(.+)$/', $line, $item) && $listKey !== null) {
                $data[$listKey][] = $this->scalar($item[1]);

                continue;
            }

            if (! preg_match('/^([A-Za-z_][\w-]*):\s*(.*)$/', $line, $pair)) {
                continue;
            }

            $key = $pair[1];
            $value = trim($pair[2]);
            $listKey = null;

            if ($value === '') {
                $data[$key] = [];
                $listKey = $key;
            } elseif (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $data[$key] = array_values(array_filter(array_map(fn (string $part) => $this->scalar($part), explode(',', substr($value, 1, -1))), fn ($part) => $part !== ''));
            } else {
                $data[$key] = $this->scalar($value);
            }
        }

        return [$data, $match[2]];
    }

    protected function scalar(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Remove credentials and tokens before rendering.
     */
    protected function redact(string $markdown): string
    {
        foreach ((array) config('workflow-docs.redactions', []) as $pattern => $replacement) {
            $markdown = (string) preg_replace($pattern, $replacement, $markdown);
        }

        return $markdown;
    }

    /**
     * Check that each listed source file exists in the project.
     *
     * @param  array<int, string>  $sources
     * @return list<array{path: string, verified: bool}>
     */
    protected function verifySources(array $sources): array
    {
        $roots = (array) config('workflow-docs.source_roots', []);

        return array_values(array_map(function (string $path) use ($roots): array {
            $path = trim(str_replace('\\', '/', $path), '/');
            $allowed = ! str_contains($path, '..') && in_array(strtok($path, '/'), $roots, true);

            return ['path' => $path, 'verified' => $allowed && file_exists(base_path($path))];
        }, array_filter($sources, 'is_string')));
    }
}
