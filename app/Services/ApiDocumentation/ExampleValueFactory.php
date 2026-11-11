<?php

namespace App\Services\ApiDocumentation;

use Illuminate\Support\Str;

/**
 * Builds sanitized, illustrative example values. Values are derived from
 * field names, validation rules and casts — never from database records.
 */
class ExampleValueFactory
{
    public const string PLACEHOLDER_TOKEN = 'YOUR_ACCESS_TOKEN';

    /**
     * Example value for a request field described by validation rules.
     *
     * @param  list<string>  $rules
     */
    public function forRequestField(string $field, string $type, array $rules): mixed
    {
        $name = $this->leafName($field);

        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'in:')) {
                $options = str_getcsv(substr($rule, 3), escape: '\\');

                return $this->castOption(trim($options[0] ?? '', '"'), $type);
            }
        }

        return match ($type) {
            'boolean' => true,
            'integer' => $this->integerFor($name, $rules),
            'number' => $this->numberFor($name, $rules),
            'array' => [],
            'file' => null,
            'date' => $this->dateFor($name, $rules),
            default => $this->stringFor($name, $rules),
        };
    }

    /**
     * Example value for a response field, using the field name and the
     * source expression that produces it (casts, date formatting, etc.).
     */
    public function forResponseField(string $field, string $expression): mixed
    {
        $name = strtolower($field);
        $expression = strtolower(str_replace(' ', '', $expression));

        return match (true) {
            in_array($name, ['access_token', 'token', 'plain_text_token'], true) => self::PLACEHOLDER_TOKEN,
            $name === 'token_type' => 'Bearer',
            $name === 'expires_in' => null,
            in_array($name, ['permissions', 'available_roles', 'roles', 'abilities'], true) => [],
            (bool) preg_match('/^\$(?!this\b)\w+$/', $expression) => $this->stringFor($name, []) === 'string' ? null : $this->stringFor($name, []),
            str_contains($name, 'statistics'), $name === 'settings' => null,
            in_array($name, ['latitude', 'lat'], true) => 14.5547,
            in_array($name, ['longitude', 'lng'], true) => 121.0244,
            str_contains($expression, '(bool)'), str_contains($expression, '!==null'), str_contains($expression, '===null'),
            (bool) preg_match('/^(is|has|can|should)_/', $name), $name === 'success', $name === 'email_verified' => $name === 'is_system_admin' ? false : true,
            str_contains($expression, '(float)'), (bool) preg_match('/(amount|rate|price|fee|total_revenue|revenue|balance)$/', $name) => 1500.0,
            str_contains($expression, '(int)'), $name === 'id', str_ends_with($name, '_id'), (bool) preg_match('/(^max_|count|total|points|wins|losses|rank|position|capacity|max_players|per_page|current_page|last_page|score.*|matches_played|rating)$/', $name) => $this->integerFor($name, []),
            str_contains($expression, 'toisostring'), str_ends_with($name, '_at') => '2026-01-15T09:00:00.000000Z',
            str_contains($expression, 'todatestring'), str_ends_with($name, '_date'), $name === 'date' => '2026-01-15',
            default => $this->stringFor($name, []),
        };
    }

    /**
     * Get the last segment of a dotted field name.
     */
    protected function leafName(string $field): string
    {
        $segments = array_values(array_filter(explode('.', $field), fn (string $segment): bool => $segment !== '*'));

        return strtolower(end($segments) ?: $field);
    }

    /**
     * @param  list<string>  $rules
     */
    protected function integerFor(string $name, array $rules): int
    {
        $minimum = $this->ruleNumber($rules, 'min');

        return match (true) {
            $minimum !== null && $minimum > 1 => (int) $minimum,
            str_contains($name, 'per_page') => 15,
            str_contains($name, 'max_players'), str_contains($name, 'capacity') => 16,
            str_contains($name, 'duration') => 60,
            default => 1,
        };
    }

    /**
     * @param  list<string>  $rules
     */
    protected function numberFor(string $name, array $rules): float
    {
        $minimum = $this->ruleNumber($rules, 'min');

        return match (true) {
            str_contains($name, 'lat') => 14.5547,
            str_contains($name, 'lng'), str_contains($name, 'long') => 121.0244,
            $minimum !== null && $minimum > 0 => (float) $minimum,
            default => 500.0,
        };
    }

    /**
     * @param  list<string>  $rules
     */
    protected function dateFor(string $name, array $rules): string
    {
        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'date_format:')) {
                return now()->startOfDay()->addHours(9)->format(substr($rule, 12));
            }
        }

        $start = now()->addDays(7)->startOfDay()->addHours(9);

        if (str_contains($name, 'end')) {
            return $start->addHours(2)->format('Y-m-d H:i:s');
        }

        return str_contains($name, 'date') && ! str_contains($name, 'time')
            ? $start->format('Y-m-d')
            : $start->format('Y-m-d H:i:s');
    }

    /**
     * @param  list<string>  $rules
     */
    protected function stringFor(string $name, array $rules): string
    {
        if (in_array('email', $rules, true) || str_contains($name, 'email')) {
            return 'user@example.com';
        }

        if (in_array('url', $rules, true)) {
            return 'https://example.com';
        }

        $value = match (true) {
            str_contains($name, 'password') => 'Example-Passw0rd!',
            $name === 'id_token' => 'GOOGLE_ID_TOKEN',
            str_contains($name, 'token') => 'EXAMPLE_TOKEN',
            str_contains($name, 'phone') || str_contains($name, 'mobile') => '+639170000000',
            str_contains($name, 'slug') => 'sample-slug',
            str_contains($name, 'reference') => 'CF-AB12CD34',
            str_contains($name, 'status') => 'active',
            str_contains($name, 'role') => 'player',
            in_array($name, ['avatar', 'logo', 'image', 'photo', 'proof_url', 'proof_path'], true) || str_ends_with($name, '_url') => 'https://example.com/storage/sample.jpg',
            str_contains($name, 'name') => $name === 'name' ? 'Sample Name' : Str::headline($name).' sample',
            str_contains($name, 'address') => '123 Sample Street',
            str_contains($name, 'city') => 'Sample City',
            str_contains($name, 'currency') => 'PHP',
            str_contains($name, 'method') => 'gcash',
            str_contains($name, 'time') => '09:00',
            str_contains($name, 'description') || str_contains($name, 'notes') || str_contains($name, 'reason') || str_contains($name, 'message') => 'Sample text',
            default => 'string',
        };

        $maximum = $this->ruleNumber($rules, 'max');

        return $maximum !== null && $maximum > 0 ? Str::limit($value, (int) $maximum, '') : $value;
    }

    /**
     * Cast an "in:" option to the field type.
     */
    protected function castOption(string $option, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $option,
            'number' => (float) $option,
            'boolean' => filter_var($option, FILTER_VALIDATE_BOOLEAN),
            default => $option,
        };
    }

    /**
     * Get the numeric argument of a rule such as "min:3".
     *
     * @param  list<string>  $rules
     */
    protected function ruleNumber(array $rules, string $name): ?float
    {
        foreach ($rules as $rule) {
            if (str_starts_with($rule, $name.':') && is_numeric(substr($rule, strlen($name) + 1))) {
                return (float) substr($rule, strlen($name) + 1);
            }
        }

        return null;
    }
}
