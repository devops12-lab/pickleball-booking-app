<?php

namespace App\Services\DataModelDocumentation;

use Illuminate\Support\Str;

class MigrationParserService
{
    /**
     * Parse all migration files and return a merged table schema map.
     *
     * @return array<string, array{name: string, columns: list<array{name: string, type: string, nullable: bool, default: string|null, indexed: bool, unique: bool}>, indexes: list<array{columns: list<string>, type: string, name: string|null}>, foreign_keys: list<array{column: string, references: string, on: string, onDelete: string|null, onUpdate: string|null}>}>
     */
    public function parse(string $migrationsPath): array
    {
        $tables = [];

        $files = glob($migrationsPath.'/*.php');
        sort($files);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->extractSchemaBlocks($content, $tables);
        }

        return $tables;
    }

    /**
     * Extract all Schema::create and Schema::table blocks from a migration file.
     *
     * @param  array<string, array<string, mixed>>  $tables
     */
    private function extractSchemaBlocks(string $content, array &$tables): void
    {
        // Match Schema::create('table', function (Blueprint $table) { ... })
        // and Schema::table('table', function (Blueprint $table) { ... })
        preg_match_all(
            '/Schema::(create|table)\(\s*[\'"]([a-z_]+)[\'"]\s*,\s*function\s*\(\s*Blueprint\s+\$\w+\s*\)(?:\s*:\s*void)?\s*\{(.*?)\}\s*\)/s',
            $content,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $action = $match[1]; // 'create' or 'table'
            $tableName = $match[2];
            $body = $match[3];

            if (! isset($tables[$tableName])) {
                $tables[$tableName] = [
                    'name' => $tableName,
                    'columns' => [],
                    'indexes' => [],
                    'foreign_keys' => [],
                ];
            }

            $this->parseColumns($body, $tables[$tableName]);
            $this->parseStandaloneIndexes($body, $tables[$tableName]);
            $this->parseStandaloneForeignKeys($body, $tables[$tableName]);
        }
    }

    /**
     * Parse column definitions from a schema block body.
     *
     * @param  array<string, mixed>  $table
     */
    private function parseColumns(string $body, array &$table): void
    {
        // Split into individual statements (lines ending with ;)
        $statements = preg_split('/;\s*$/m', $body);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            // Match column-producing methods: $table->type('name'...) or $table->type()
            // Covers: id(), timestamps(), softDeletes(), rememberToken(), morphs('name'), etc.
            if (! preg_match('/\$\w+->([\w]+)\(/', $statement, $methodMatch)) {
                continue;
            }

            $method = $methodMatch[1];

            // Skip non-column statements
            if (in_array($method, ['index', 'unique', 'primary', 'foreign', 'dropColumn', 'dropForeign', 'dropIndex', 'dropUnique', 'dropPrimary', 'renameColumn', 'comment'], true)) {
                continue;
            }

            $columns = $this->methodToColumns($method, $statement);

            foreach ($columns as $column) {
                $column['nullable'] = $column['nullable'] || str_contains($statement, '->nullable()');
                $column['unique'] = $column['unique'] || (bool) preg_match('/->unique\(/', $statement);
                $column['indexed'] = $column['indexed'] || $column['unique'] || (bool) preg_match('/->index\(/', $statement);

                // Extract default value
                if (preg_match('/->default\(([^)]+)\)/', $statement, $defaultMatch)) {
                    $column['default'] = $this->cleanDefaultValue($defaultMatch[1]);
                }

                // Extract foreign key from constrained() or references()->on()
                $this->extractInlineForeignKey($method, $column['name'], $statement, $table);

                // Merge column (later migrations override earlier ones for the same column)
                $existingIndex = null;
                foreach ($table['columns'] as $i => $existing) {
                    if ($existing['name'] === $column['name']) {
                        $existingIndex = $i;
                        break;
                    }
                }

                if ($existingIndex !== null) {
                    $table['columns'][$existingIndex] = array_merge($table['columns'][$existingIndex], $column);
                } else {
                    $table['columns'][] = $column;
                }
            }
        }
    }

    /**
     * Convert a Blueprint method call to column definition(s).
     *
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, indexed: bool, unique: bool}>
     */
    private function methodToColumns(string $method, string $statement): array
    {
        // Extract the first string argument (column name)
        $name = null;
        if (preg_match('/\$\w+->\w+\(\s*[\'"]([a-z_]+)[\'"]/', $statement, $nameMatch)) {
            $name = $nameMatch[1];
        }

        $base = ['nullable' => false, 'default' => null, 'indexed' => false, 'unique' => false];

        // Convenience methods that produce multiple columns
        return match ($method) {
            'id' => [array_merge($base, ['name' => 'id', 'type' => 'bigIncrements', 'indexed' => true, 'unique' => true])],
            'timestamps' => [
                array_merge($base, ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true]),
                array_merge($base, ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true]),
            ],
            'softDeletes' => [array_merge($base, ['name' => 'deleted_at', 'type' => 'timestamp', 'nullable' => true])],
            'rememberToken' => [array_merge($base, ['name' => 'remember_token', 'type' => 'string(100)', 'nullable' => true])],
            'morphs', 'nullableMorphs' => $name ? [
                array_merge($base, ['name' => $name.'_type', 'type' => 'string', 'nullable' => $method === 'nullableMorphs', 'indexed' => true]),
                array_merge($base, ['name' => $name.'_id', 'type' => 'unsignedBigInteger', 'nullable' => $method === 'nullableMorphs', 'indexed' => true]),
            ] : [],
            'uuidMorphs', 'nullableUuidMorphs' => $name ? [
                array_merge($base, ['name' => $name.'_type', 'type' => 'string', 'nullable' => $method === 'nullableUuidMorphs', 'indexed' => true]),
                array_merge($base, ['name' => $name.'_id', 'type' => 'uuid', 'nullable' => $method === 'nullableUuidMorphs', 'indexed' => true]),
            ] : [],
            'foreignId' => $name ? [array_merge($base, ['name' => $name, 'type' => 'unsignedBigInteger', 'indexed' => true])] : [],
            'foreignUuid' => $name ? [array_merge($base, ['name' => $name, 'type' => 'uuid', 'indexed' => true])] : [],
            default => $name ? [array_merge($base, ['name' => $name, 'type' => $this->resolveType($method, $statement)])] : [],
        };
    }

    /**
     * Resolve the display type from a Blueprint method and its arguments.
     */
    private function resolveType(string $method, string $statement): string
    {
        // Extract size/precision arguments for types that accept them
        if (preg_match('/\$\w+->\w+\(\s*[\'"][a-z_]+[\'"],\s*(\d+)(?:\s*,\s*(\d+))?\s*\)/', $statement, $argsMatch)) {
            if (isset($argsMatch[2])) {
                return $method.'('.$argsMatch[1].','.$argsMatch[2].')';
            }

            // Only show length for string/char types (not for all types)
            if (in_array($method, ['string', 'char'], true) && (int) $argsMatch[1] !== 255) {
                return $method.'('.$argsMatch[1].')';
            }
        }

        return $method;
    }

    /**
     * Extract inline foreign key constraints from column definitions.
     *
     * @param  array<string, mixed>  $table
     */
    private function extractInlineForeignKey(string $method, string $column, string $statement, array &$table): void
    {
        if (! in_array($method, ['foreignId', 'foreignUuid'], true)) {
            return;
        }

        $fk = [
            'column' => $column,
            'references' => 'id',
            'on' => '',
            'onDelete' => null,
            'onUpdate' => null,
        ];

        // ->constrained('table') or ->constrained() (infers from column name)
        if (preg_match('/->constrained\(\s*(?:[\'"]([a-z_]+)[\'"])?\s*\)/', $statement, $constrainedMatch)) {
            $fk['on'] = ! empty($constrainedMatch[1])
                ? $constrainedMatch[1]
                : Str::plural(Str::beforeLast($column, '_id'));
        } else {
            // No constrained() — no foreign key
            return;
        }

        // On delete actions
        if (str_contains($statement, '->cascadeOnDelete()')) {
            $fk['onDelete'] = 'cascade';
        } elseif (str_contains($statement, '->nullOnDelete()')) {
            $fk['onDelete'] = 'set null';
        } elseif (str_contains($statement, '->restrictOnDelete()')) {
            $fk['onDelete'] = 'restrict';
        }

        // On update actions
        if (str_contains($statement, '->cascadeOnUpdate()')) {
            $fk['onUpdate'] = 'cascade';
        }

        // Avoid duplicate foreign keys
        foreach ($table['foreign_keys'] as $existing) {
            if ($existing['column'] === $fk['column']) {
                return;
            }
        }

        $table['foreign_keys'][] = $fk;
    }

    /**
     * Parse standalone index/unique/primary declarations.
     *
     * @param  array<string, mixed>  $table
     */
    private function parseStandaloneIndexes(string $body, array &$table): void
    {
        // $table->index(['col1', 'col2'], 'index_name') or $table->index(['col1', 'col2'])
        // Also handles ->unique([...]) and ->primary([...])
        preg_match_all(
            '/\$\w+->(index|unique|primary)\(\s*\[([^\]]+)\](?:\s*,\s*[\'"]([a-z_]+)[\'"])?\s*\)/',
            $body,
            $indexMatches,
            PREG_SET_ORDER,
        );

        foreach ($indexMatches as $match) {
            $type = $match[1];
            $columnsRaw = $match[2];
            $indexName = $match[3] ?? null;

            // Extract column names from the array
            preg_match_all('/[\'"]([a-z_]+)[\'"]/', $columnsRaw, $colNames);
            $columns = $colNames[1];

            if (empty($columns)) {
                continue;
            }

            $table['indexes'][] = [
                'columns' => $columns,
                'type' => $type,
                'name' => $indexName,
            ];

            // Mark individual columns as indexed/unique
            if ($type === 'unique' || $type === 'primary') {
                foreach ($table['columns'] as &$col) {
                    if (in_array($col['name'], $columns, true)) {
                        $col['indexed'] = true;
                        if (count($columns) === 1) {
                            $col['unique'] = true;
                        }
                    }
                }
                unset($col);
            }
        }

        // Also handle single-column standalone index/unique: $table->index('col_name', 'name')
        preg_match_all(
            '/\$\w+->(index|unique|primary)\(\s*[\'"]([a-z_]+)[\'"](?:\s*,\s*[\'"]([a-z_]+)[\'"])?\s*\)/',
            $body,
            $singleMatches,
            PREG_SET_ORDER,
        );

        foreach ($singleMatches as $match) {
            $type = $match[1];
            $column = $match[2];
            $indexName = $match[3] ?? null;

            $table['indexes'][] = [
                'columns' => [$column],
                'type' => $type,
                'name' => $indexName,
            ];
        }
    }

    /**
     * Parse standalone foreign key declarations.
     *
     * @param  array<string, mixed>  $table
     */
    private function parseStandaloneForeignKeys(string $body, array &$table): void
    {
        // $table->foreign('col')->references('id')->on('table')
        preg_match_all(
            '/\$\w+->foreign\(\s*[\'"]([a-z_]+)[\'"]\s*\)\s*->references\(\s*[\'"]([a-z_]+)[\'"]\s*\)\s*->on\(\s*[\'"]([a-z_]+)[\'"]\s*\)([^;]*)/s',
            $body,
            $fkMatches,
            PREG_SET_ORDER,
        );

        foreach ($fkMatches as $match) {
            $fk = [
                'column' => $match[1],
                'references' => $match[2],
                'on' => $match[3],
                'onDelete' => null,
                'onUpdate' => null,
            ];

            $rest = $match[4];
            if (preg_match('/->onDelete\(\s*[\'"](\w+\s?\w*)[\'"]/', $rest, $delMatch)) {
                $fk['onDelete'] = $delMatch[1];
            }
            if (str_contains($rest, '->cascadeOnDelete()')) {
                $fk['onDelete'] = 'cascade';
            }
            if (str_contains($rest, '->nullOnDelete()')) {
                $fk['onDelete'] = 'set null';
            }

            // Avoid duplicates
            $exists = false;
            foreach ($table['foreign_keys'] as $existing) {
                if ($existing['column'] === $fk['column']) {
                    $exists = true;
                    break;
                }
            }

            if (! $exists) {
                $table['foreign_keys'][] = $fk;
            }
        }
    }

    /**
     * Clean a default value extracted from ->default(...).
     */
    private function cleanDefaultValue(string $value): string
    {
        $value = trim($value);

        // Remove surrounding quotes
        if (preg_match('/^[\'"](.*)[\'"]\s*$/', $value, $m)) {
            return $m[1];
        }

        // PHP constants
        return match (strtolower($value)) {
            'true' => 'true',
            'false' => 'false',
            'null' => 'null',
            default => $value,
        };
    }
}
