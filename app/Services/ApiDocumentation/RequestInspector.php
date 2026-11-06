<?php

namespace App\Services\ApiDocumentation;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use ReflectionMethod;
use ReflectionNamedType;
use Stringable;
use Throwable;

/**
 * Describes request input: Form Request rules, inline $request->validate()
 * rules and query values read from the request in a controller method.
 */
class RequestInspector
{
    public function __construct(
        protected SourceInspector $source,
        protected ExampleValueFactory $examples,
    ) {}

    /**
     * Find the Form Request class type-hinted on a controller method.
     *
     * @return class-string<FormRequest>|null
     */
    public function formRequestClass(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * Get validation rules from a Form Request without running validation.
     *
     * @param  class-string<FormRequest>  $class
     * @return array<string, list<string>>|null
     */
    public function formRequestRules(string $class): ?array
    {
        try {
            $request = new $class;

            if (! method_exists($request, 'rules')) {
                return [];
            }

            $rules = app()->call([$request, 'rules']);
        } catch (Throwable) {
            return null;
        }

        return is_array($rules) ? $this->normalizeRules($rules) : null;
    }

    /**
     * Get the rules passed to an inline $request->validate([...]) call.
     *
     * @return array<string, list<string>>
     */
    public function inlineRules(ReflectionMethod $method): array
    {
        $source = $this->source->source($method);

        if ($source === null) {
            return [];
        }

        $tokens = $this->source->tokenize($source);
        $rules = [];

        foreach ($this->source->callIndexes($tokens, 'validate') as $openIndex) {
            if (($tokens[$openIndex + 1]->text ?? null) !== '[') {
                continue;
            }

            $array = $this->source->arrayAt($tokens, $openIndex + 1);

            foreach ($array['entries'] ?? [] as $entry) {
                if ($entry['key'] === null) {
                    continue;
                }

                $rules[$entry['key']] = $this->rulesFromNode($entry['value']);
            }
        }

        return $rules;
    }

    /**
     * Get query/body values read directly from the request, e.g.
     * $request->integer('per_page', 15) or $request->query('status').
     *
     * @return list<array{name: string, type: string, default: ?string}>
     */
    public function readInputs(ReflectionMethod $method): array
    {
        $source = $this->source->source($method) ?? '';
        $inputs = [];

        preg_match_all(
            '/\$request->(query|input|integer|boolean|string|float|date|filled|has|get|str|enum)\(\s*[\'"]([\w.]+)[\'"]\s*(?:,\s*([^)]+))?\)/',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $name = $match[2];

            if (isset($inputs[$name])) {
                continue;
            }

            $inputs[$name] = [
                'name' => $name,
                'type' => match ($match[1]) {
                    'integer' => 'integer',
                    'boolean' => 'boolean',
                    'float' => 'number',
                    'date' => 'date',
                    default => 'string',
                },
                'default' => isset($match[3]) ? trim($match[3], " '\"") : null,
            ];
        }

        return array_values($inputs);
    }

    /**
     * Turn normalized rules into documented fields.
     *
     * @param  array<string, list<string>>  $rules
     * @return list<array{name: string, type: string, required: string, nullable: bool, rules: list<string>, example: mixed}>
     */
    public function fields(array $rules): array
    {
        $fields = [];

        foreach ($rules as $name => $fieldRules) {
            $type = $this->typeFor($fieldRules, $rules, (string) $name);

            $fields[] = [
                'name' => (string) $name,
                'type' => $type,
                'required' => $this->requirement($fieldRules),
                'nullable' => in_array('nullable', $fieldRules, true),
                'rules' => $fieldRules,
                'example' => $this->examples->forRequestField((string) $name, $type, $fieldRules),
            ];

            if (in_array('confirmed', $fieldRules, true)) {
                $fields[] = [
                    'name' => $name.'_confirmation',
                    'type' => $type,
                    'required' => $this->requirement($fieldRules),
                    'nullable' => false,
                    'rules' => ['same:'.$name],
                    'example' => $this->examples->forRequestField((string) $name, $type, $fieldRules),
                ];
            }
        }

        return $fields;
    }

    /**
     * Build an example payload from documented fields.
     *
     * @param  list<array{name: string, type: string, example: mixed}>  $fields
     * @return array<string, mixed>
     */
    public function examplePayload(array $fields): array
    {
        $payload = [];

        foreach ($fields as $field) {
            if ($field['type'] === 'file') {
                continue;
            }

            $path = str_replace('.*', '.0', $field['name']);

            if (Arr::has($payload, $path) && is_array(Arr::get($payload, $path))) {
                continue;
            }

            Arr::set($payload, $path, $field['example']);
        }

        return $payload;
    }

    /**
     * Build the 422 validation error example for the given fields.
     *
     * @param  list<array{name: string, required: string}>  $fields
     * @return array<string, list<string>>
     */
    public function validationErrors(array $fields): array
    {
        $errors = [];

        foreach ($fields as $field) {
            if ($field['required'] === 'required' && ! str_contains($field['name'], '*')) {
                $errors[$field['name']] = ['The '.str_replace(['_', '.'], ' ', $field['name']).' field is required.'];
            }

            if (count($errors) === 2) {
                break;
            }
        }

        if ($errors === [] && $fields !== []) {
            $name = $fields[0]['name'];
            $errors[$name] = ['The '.str_replace(['_', '.', '*'], [' ', ' ', ''], $name).' field must be a valid value.'];
        }

        return $errors;
    }

    /**
     * Normalize Laravel rules into readable strings.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, list<string>>
     */
    protected function normalizeRules(array $rules): array
    {
        $normalized = [];

        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_string($fieldRules) ? explode('|', $fieldRules) : Arr::wrap($fieldRules);

            $normalized[(string) $field] = array_values(array_map(fn (mixed $rule): string => match (true) {
                is_string($rule) => $rule,
                $rule instanceof Closure => 'custom rule',
                $rule instanceof Stringable, method_exists($rule, '__toString') => (string) $rule,
                is_object($rule) => class_basename($rule),
                default => (string) $rule,
            }, $fieldRules));
        }

        return $normalized;
    }

    /**
     * Read rule strings from a parsed array node (inline validation).
     *
     * @return list<string>
     */
    protected function rulesFromNode(array $node): array
    {
        if ($node['type'] === 'expr') {
            $literal = $this->source->stringLiteral($node['tokens']);

            return $literal !== null ? explode('|', $literal) : [trim($node['text'])];
        }

        return array_map(function (array $entry): string {
            $value = $entry['value'];

            if ($value['type'] === 'expr') {
                return $this->source->stringLiteral($value['tokens']) ?? str_replace(' ', '', $value['text']);
            }

            return 'array';
        }, $node['entries']);
    }

    /**
     * Infer a JSON type from rules.
     *
     * @param  list<string>  $rules
     * @param  array<string, list<string>>  $allRules
     */
    protected function typeFor(array $rules, array $allRules, string $name): string
    {
        $names = array_map(fn (string $rule): string => strtolower(strtok($rule, ':')), $rules);

        return match (true) {
            (bool) array_intersect($names, ['file', 'image', 'mimes', 'mimetypes', 'file']) => 'file',
            (bool) array_intersect($names, ['boolean', 'bool', 'accepted', 'declined']) => 'boolean',
            (bool) array_intersect($names, ['integer', 'int']) => 'integer',
            (bool) array_intersect($names, ['numeric', 'decimal']) => 'number',
            in_array('array', $names, true) || array_key_exists($name.'.*', $allRules) => 'array',
            (bool) array_intersect($names, ['date', 'date_format', 'before', 'after', 'after_or_equal', 'before_or_equal']) => 'date',
            default => 'string',
        };
    }

    /**
     * Describe whether a field is required.
     *
     * @param  list<string>  $rules
     */
    protected function requirement(array $rules): string
    {
        foreach ($rules as $rule) {
            if ($rule === 'required') {
                return 'required';
            }

            if (str_starts_with($rule, 'required_')) {
                return 'conditional';
            }
        }

        return 'optional';
    }
}
