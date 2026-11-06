<?php

namespace App\Services\ApiDocumentation;

use Illuminate\Support\Facades\Cache;

/**
 * Builds the payload rendered by the API documentation portal.
 */
class ApiDocumentationService
{
    public function __construct(
        protected EndpointDiscoveryService $discovery,
    ) {}

    /**
     * Get the documentation payload for the given application root URL.
     *
     * @return array{
     *     meta: array<string, mixed>,
     *     modules: list<array{name: string, count: int}>,
     *     endpoints: list<array<string, mixed>>
     * }
     */
    public function build(string $rootUrl): array
    {
        $documentation = $this->documentation();
        $prefix = '/'.trim((string) config('api-docs.prefix'), '/');
        $rootUrl = rtrim($rootUrl, '/');

        $documentation['meta'] = [
            'title' => config('api-docs.title'),
            'subtitle' => config('api-docs.subtitle'),
            'version' => config('api-docs.version'),
            'root_url' => $rootUrl,
            'base_url' => $rootUrl.$prefix,
            'prefix' => $prefix,
            'try_it' => (bool) config('api-docs.try_it.enabled'),
            'token_expiration_minutes' => config('sanctum.expiration') !== null ? (int) config('sanctum.expiration') : null,
            'endpoint_count' => count($documentation['endpoints']),
        ];

        return $documentation;
    }

    /**
     * Get the cached, URL-independent part of the documentation.
     *
     * @return array{modules: list<array{name: string, count: int}>, endpoints: list<array<string, mixed>>}
     */
    protected function documentation(): array
    {
        if (! config('api-docs.cache.enabled')) {
            return $this->discover();
        }

        return Cache::remember(
            'api-docs:'.$this->discovery->fingerprint(),
            (int) config('api-docs.cache.ttl', 3600),
            fn (): array => $this->discover(),
        );
    }

    /**
     * Discover endpoints and group them into sidebar modules.
     *
     * @return array{modules: list<array{name: string, count: int}>, endpoints: list<array<string, mixed>>}
     */
    protected function discover(): array
    {
        $order = array_values(array_unique(array_values((array) config('api-docs.modules', []))));
        $endpoints = $this->discovery->endpoints();

        $endpoints = collect($endpoints)
            ->map(function (array $endpoint) {
                $endpoint['try_it'] = (bool) config('api-docs.try_it.enabled') && ! $endpoint['internal'];

                return $endpoint;
            })
            ->sortBy([
                fn (array $a, array $b): int => $this->position($order, $a['module']) <=> $this->position($order, $b['module']),
                fn (array $a, array $b): int => $this->areaRank($a) <=> $this->areaRank($b),
                fn (array $a, array $b): int => $a['resource'] <=> $b['resource'],
            ])
            ->values();

        $modules = $endpoints
            ->groupBy('module')
            ->map(fn ($items, string $name): array => ['name' => $name, 'count' => $items->count()])
            ->values()
            ->all();

        return ['modules' => $modules, 'endpoints' => $endpoints->all()];
    }

    /**
     * Get the configured position of a module ("Other" goes last).
     *
     * @param  list<string>  $order
     */
    protected function position(array $order, string $module): int
    {
        $position = array_search($module, $order, true);

        return $position === false ? PHP_INT_MAX : $position;
    }

    /**
     * Keep the configured portal order inside the Authentication module.
     *
     * @param  array<string, mixed>  $endpoint
     */
    protected function areaRank(array $endpoint): int
    {
        $position = array_search($endpoint['area'], array_values((array) config('api-docs.areas', [])), true);

        return $position === false ? PHP_INT_MAX : $position;
    }
}
