<?php

namespace App\Services\DataModelDocumentation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DataModelDocumentationService
{
    public function __construct(
        private MigrationParserService $parser,
        private ColumnDescriptionService $descriptionService,
    ) {}

    /**
     * Build the full documentation payload.
     *
     * @return array{meta: array<string, mixed>, domains: list<array<string, mixed>>}
     */
    public function build(): array
    {
        if (config('data-model-docs.cache.enabled')) {
            return Cache::remember(
                'data-model-docs:payload',
                config('data-model-docs.cache.ttl', 3600),
                fn (): array => $this->compile(),
            );
        }

        return $this->compile();
    }

    /**
     * Compile the documentation from parsed migrations.
     *
     * @return array{meta: array<string, mixed>, domains: list<array<string, mixed>>}
     */
    private function compile(): array
    {
        $tables = $this->parser->parse(config('data-model-docs.migrations_path'));
        $domainConfig = config('data-model-docs.domains', []);

        // Build a lookup: table name → domain label
        $tableToDomain = [];
        foreach ($domainConfig as $domain => $tableNames) {
            foreach ($tableNames as $tableName) {
                $tableToDomain[$tableName] = $domain;
            }
        }

        // Group tables into domains
        $domainGroups = [];
        foreach ($tables as $tableName => $tableData) {
            $domain = $tableToDomain[$tableName] ?? 'Other';
            $domainGroups[$domain][] = $this->formatTable($tableData);
        }

        // Preserve config domain ordering, then append "Other" if present
        $domains = [];
        foreach (array_keys($domainConfig) as $domainLabel) {
            if (isset($domainGroups[$domainLabel])) {
                $domains[] = [
                    'name' => $domainLabel,
                    'slug' => Str::slug($domainLabel),
                    'tables' => $domainGroups[$domainLabel],
                ];
            }
        }

        if (isset($domainGroups['Other'])) {
            $domains[] = [
                'name' => 'Other',
                'slug' => 'other',
                'tables' => $domainGroups['Other'],
            ];
        }

        $totalColumns = collect($tables)->sum(fn (array $t): int => count($t['columns']));

        return [
            'meta' => [
                'title' => config('data-model-docs.title'),
                'subtitle' => config('data-model-docs.subtitle'),
                'logo' => config('data-model-docs.logo'),
                'domain_count' => count($domains),
                'table_count' => count($tables),
                'column_count' => $totalColumns,
            ],
            'domains' => $domains,
        ];
    }

    /**
     * Format a table for the view payload.
     *
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    private function formatTable(array $table): array
    {
        $columns = array_map(function (array $col) use ($table): array {
            $col['description'] = $this->descriptionService->describe(
                $table['name'],
                $col['name'],
                $col['type'],
                $table['foreign_keys'],
            );

            return $col;
        }, $table['columns']);

        return [
            'name' => $table['name'],
            'slug' => Str::slug($table['name']),
            'column_count' => count($columns),
            'columns' => $columns,
            'indexes' => $table['indexes'],
            'foreign_keys' => $table['foreign_keys'],
        ];
    }
}
