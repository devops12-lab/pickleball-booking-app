<?php

namespace App\Services\WorkflowDocumentation;

use Illuminate\Support\Facades\Cache;

/**
 * Builds the navigation, search index and pages for the workflow portal.
 */
class WorkflowDocumentationService
{
    public function __construct(
        protected MarkdownDocumentRepository $documents,
    ) {}

    /**
     * Get the page payload: navigation, search index and the first document.
     *
     * @return array{meta: array<string, mixed>, categories: list<array<string, mixed>>, documents: list<array<string, mixed>>, initial: ?array<string, mixed>}
     */
    public function build(): array
    {
        $index = $this->index();
        $first = $index['documents'][0]['slug'] ?? null;

        return [
            'meta' => [
                'title' => config('workflow-docs.title'),
                'subtitle' => config('workflow-docs.subtitle'),
                'document_count' => count($index['documents']),
                'statuses' => array_values((array) config('workflow-docs.statuses', [])),
                'document_url' => url('/'.trim((string) config('workflow-docs.path'), '/').'/documents'),
            ],
            'categories' => $index['categories'],
            'documents' => $index['documents'],
            'initial' => $first !== null ? $this->document($first) : null,
        ];
    }

    /**
     * Get one document with its HTML, or null if it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function document(string $slug): ?array
    {
        $document = $this->documents->find($slug);

        if ($document === null) {
            return null;
        }

        unset($document['sections']);

        return $document;
    }

    /**
     * Get the ordered document index and sidebar categories.
     *
     * @return array{categories: list<array<string, mixed>>, documents: list<array<string, mixed>>}
     */
    protected function index(): array
    {
        $build = function (): array {
            $order = array_values((array) config('workflow-docs.categories', []));
            $position = fn (string $category): int => ($found = array_search($category, $order, true)) === false ? PHP_INT_MAX : $found;

            $documents = collect($this->documents->all())
                ->sortBy([
                    fn (array $a, array $b): int => $position($a['category']) <=> $position($b['category']),
                    fn (array $a, array $b): int => $a['order'] <=> $b['order'],
                    fn (array $a, array $b): int => strcmp($a['title'], $b['title']),
                ])
                ->values();

            $categories = $documents
                ->groupBy('category')
                ->map(fn ($items, string $name): array => [
                    'name' => $name,
                    'count' => $items->count(),
                    'documents' => $items->map(fn (array $document): array => [
                        'slug' => $document['slug'],
                        'title' => $document['title'],
                        'status' => $document['status'],
                    ])->values()->all(),
                ])
                ->values()
                ->all();

            return ['categories' => $categories, 'documents' => $documents->all()];
        };

        if (! config('workflow-docs.cache.enabled', true)) {
            return $build();
        }

        return Cache::remember('workflow-docs:index:'.$this->documents->fingerprint(), (int) config('workflow-docs.cache.ttl', 86400), $build);
    }
}
