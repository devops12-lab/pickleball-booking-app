<?php

namespace App\Services\DataModelDocumentation;

use Illuminate\Support\Str;

class ColumnDescriptionService
{
    /**
     * Return a human-readable description for a column.
     *
     * Resolution order: config override → convention pattern → type fallback.
     *
     * @param  list<array{column: string, references: string, on: string}>  $foreignKeys
     */
    public function describe(string $table, string $column, string $type, array $foreignKeys): string
    {
        // 1. Config override — "table.column" key
        $override = config("data-model-docs.column_descriptions.{$table}.{$column}");
        if ($override) {
            return $override;
        }

        // 2. Convention-based descriptions
        $convention = $this->fromConvention($table, $column, $type, $foreignKeys);
        if ($convention) {
            return $convention;
        }

        // 3. Type-based fallback
        return $this->fromType($type);
    }

    /**
     * Derive a description from column naming conventions.
     *
     * @param  list<array{column: string, references: string, on: string}>  $foreignKeys
     */
    private function fromConvention(string $table, string $column, string $type, array $foreignKeys): ?string
    {
        // Primary key
        if ($column === 'id' && in_array($type, ['bigIncrements', 'id', 'increments', 'uuid', 'ulid'], true)) {
            return 'Primary key';
        }

        // Foreign key columns — use FK metadata for the referenced table
        if (str_ends_with($column, '_id')) {
            $fk = collect($foreignKeys)->firstWhere('column', $column);
            if ($fk) {
                return 'Foreign key to '.$fk['on'].'.'.$fk['references'];
            }

            $referenced = Str::plural(Str::beforeLast($column, '_id'));

            return 'References '.$referenced;
        }

        // Polymorphic morph columns
        if (str_ends_with($column, '_type') && in_array($type, ['string', 'varchar'], true)) {
            $morphName = Str::beforeLast($column, '_type');

            return 'Polymorphic type for '.$morphName.' relation';
        }

        // Exact-match well-known columns
        $exactMatches = [
            'created_at' => 'Timestamp when the record was created',
            'updated_at' => 'Timestamp when the record was last updated',
            'deleted_at' => 'Soft delete timestamp',
            'email' => 'Email address',
            'email_verified_at' => 'Timestamp when email was verified',
            'password' => 'Hashed password',
            'remember_token' => 'Session remember token',
            'name' => 'Display name',
            'title' => 'Title',
            'slug' => 'URL-friendly identifier',
            'status' => 'Current status',
            'type' => 'Type classification',
            'description' => 'Free-text description',
            'notes' => 'Additional notes',
            'body' => 'Main content body',
            'content' => 'Content text',
            'uuid' => 'Universally unique identifier',
            'ulid' => 'Universally unique lexicographically sortable identifier',
            'metadata' => 'JSON metadata',
            'settings' => 'JSON settings',
            'config' => 'JSON configuration',
            'payload' => 'Serialized payload data',
            'token' => 'Security token',
            'ip_address' => 'IP address',
            'user_agent' => 'Browser user agent string',
            'locale' => 'Locale code',
            'timezone' => 'Timezone identifier',
            'currency' => 'Currency code',
            'phone' => 'Phone number',
            'phone_number' => 'Phone number',
            'address' => 'Street address',
            'city' => 'City name',
            'state' => 'State or province',
            'country' => 'Country name or code',
            'zip' => 'ZIP or postal code',
            'postal_code' => 'Postal code',
            'latitude' => 'Geographic latitude',
            'longitude' => 'Geographic longitude',
            'avatar' => 'Profile avatar image',
            'color' => 'Color value',
            'priority' => 'Priority level',
            'position' => 'Sort position or order',
            'sort_order' => 'Display sort order',
            'order' => 'Display order',
            'level' => 'Level or tier',
            'attempts' => 'Number of attempts',
            'quantity' => 'Quantity count',
            'duration' => 'Duration in minutes',
            'exception' => 'Exception or error details',
            'connection' => 'Queue connection name',
            'queue' => 'Queue name',
            'reserved_at' => 'Timestamp when the job was reserved',
            'available_at' => 'Timestamp when the job becomes available',
        ];

        if (isset($exactMatches[$column])) {
            return $exactMatches[$column];
        }

        // Prefix patterns: is_*, has_*
        if (str_starts_with($column, 'is_')) {
            $rest = Str::of(Str::after($column, 'is_'))->replace('_', ' ')->toString();

            return 'Whether the record is '.$rest;
        }

        if (str_starts_with($column, 'has_')) {
            $rest = Str::of(Str::after($column, 'has_'))->replace('_', ' ')->toString();

            return 'Whether the record has '.$rest;
        }

        if (str_starts_with($column, 'can_')) {
            $rest = Str::of(Str::after($column, 'can_'))->replace('_', ' ')->toString();

            return 'Whether the record can '.$rest;
        }

        // Suffix patterns
        if (str_ends_with($column, '_at') && $column !== 'created_at' && $column !== 'updated_at' && $column !== 'deleted_at') {
            $event = Str::of(Str::beforeLast($column, '_at'))->replace('_', ' ')->toString();

            return 'Timestamp when '.$event;
        }

        if (str_ends_with($column, '_count')) {
            $relation = Str::of(Str::beforeLast($column, '_count'))->replace('_', ' ')->toString();

            return 'Cached count of '.$relation;
        }

        if (str_ends_with($column, '_url')) {
            $resource = Str::of(Str::beforeLast($column, '_url'))->replace('_', ' ')->toString();

            return 'URL to '.$resource;
        }

        if (str_ends_with($column, '_path')) {
            $resource = Str::of(Str::beforeLast($column, '_path'))->replace('_', ' ')->toString();

            return 'File path to '.$resource;
        }

        if (str_ends_with($column, '_token')) {
            $purpose = Str::of(Str::beforeLast($column, '_token'))->replace('_', ' ')->toString();

            return 'Security token for '.$purpose;
        }

        if (str_ends_with($column, '_key')) {
            $purpose = Str::of(Str::beforeLast($column, '_key'))->replace('_', ' ')->toString();

            return 'Key for '.$purpose;
        }

        if (str_ends_with($column, '_code')) {
            $purpose = Str::of(Str::beforeLast($column, '_code'))->replace('_', ' ')->toString();

            return ucfirst($purpose).' code';
        }

        // Monetary columns
        $monetaryPatterns = ['amount', 'fee', 'price', 'cost', 'total', 'balance', 'rate'];
        foreach ($monetaryPatterns as $pattern) {
            if ($column === $pattern || str_ends_with($column, '_'.$pattern)) {
                $label = Str::of($column)->replace('_', ' ')->title()->toString();

                return $label.' (monetary value)';
            }
        }

        // Max/min columns
        if (str_starts_with($column, 'max_') || str_starts_with($column, 'min_')) {
            $prefix = str_starts_with($column, 'max_') ? 'Maximum' : 'Minimum';
            $rest = Str::of(Str::after($column, str_starts_with($column, 'max_') ? 'max_' : 'min_'))->replace('_', ' ')->toString();

            return $prefix.' '.$rest;
        }

        return null;
    }

    /**
     * Generate a fallback description from the column type.
     */
    private function fromType(string $type): string
    {
        return match (true) {
            in_array($type, ['string', 'varchar', 'char'], true) => 'Text value',
            in_array($type, ['text', 'mediumText', 'longText'], true) => 'Long text value',
            in_array($type, ['integer', 'int', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger', 'unsignedBigInteger', 'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger', 'unsignedMediumInteger'], true) => 'Integer value',
            in_array($type, ['decimal', 'float', 'double', 'unsignedDecimal'], true) => 'Numeric value',
            in_array($type, ['boolean', 'bool'], true) => 'Boolean flag',
            in_array($type, ['date'], true) => 'Date value',
            in_array($type, ['dateTime', 'dateTimeTz', 'timestamp', 'timestampTz'], true) => 'Date and time value',
            in_array($type, ['time', 'timeTz'], true) => 'Time value',
            in_array($type, ['json', 'jsonb'], true) => 'JSON data',
            in_array($type, ['binary', 'blob'], true) => 'Binary data',
            in_array($type, ['enum'], true) => 'Enumerated value',
            in_array($type, ['uuid', 'ulid'], true) => 'Unique identifier',
            default => 'Column value',
        };
    }
}
