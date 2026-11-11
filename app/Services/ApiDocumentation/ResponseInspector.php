<?php

namespace App\Services\ApiDocumentation;

use Illuminate\Http\Resources\Json\JsonResource;
use PhpToken;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Infers the success and error responses of a controller method by reading
 * its successResponse()/errorResponse() calls and the API Resources they use.
 */
class ResponseInspector
{
    protected const int MAX_DEPTH = 3;

    public function __construct(
        protected SourceInspector $source,
        protected ExampleValueFactory $examples,
    ) {}

    /**
     * Describe the responses of a controller method.
     *
     * @return array{
     *     status: int,
     *     message: ?string,
     *     data: mixed,
     *     meta: ?array<string, mixed>,
     *     paginated: bool,
     *     resource: ?array{class: string, collection: bool},
     *     inferred: bool,
     *     found: bool,
     *     errors: list<array{status: int, message: string}>
     * }
     */
    public function describe(ReflectionMethod $method): array
    {
        $description = [
            'status' => Response::HTTP_OK,
            'message' => null,
            'data' => null,
            'meta' => null,
            'paginated' => false,
            'resource' => null,
            'inferred' => false,
            'found' => false,
            'errors' => [],
        ];

        $source = $this->source->source($method);

        if ($source === null) {
            return $description;
        }

        $tokens = $this->source->tokenize($source);
        $class = $method->getDeclaringClass();
        $description['paginated'] = str_contains($source, '->paginate(');
        $description['errors'] = $this->errorCalls($tokens);

        $openIndex = $this->source->lastCallIndex($tokens, 'successResponse');

        if ($openIndex === null) {
            return $description;
        }

        $arguments = $this->source->callArguments($tokens, $openIndex);
        $named = $arguments['named'];
        $positional = $arguments['positional'];

        $dataTokens = $named['data'] ?? $positional[0] ?? null;
        $messageTokens = $named['message'] ?? $positional[1] ?? null;
        $codeTokens = $named['code'] ?? $positional[2] ?? null;
        $metaTokens = $named['meta'] ?? $positional[3] ?? null;

        $description['message'] = $messageTokens !== null
            ? ($this->source->readableString($messageTokens) ?? $this->firstLiteral($messageTokens))
            : 'Operation completed successfully.';
        $description['found'] = true;
        $description['status'] = $codeTokens !== null ? ($this->statusCode($codeTokens) ?? Response::HTTP_OK) : Response::HTTP_OK;

        if ($dataTokens === null) {
            $description['data'] = [];
            $description['inferred'] = true;
        } else {
            $resolved = $this->resolveData($dataTokens, $tokens, $class);

            if ($resolved !== null) {
                $description['data'] = $resolved['example'];
                $description['resource'] = $resolved['resource'];
                $description['inferred'] = true;
            }
        }

        if ($metaTokens !== null) {
            $meta = $this->source->node($metaTokens);

            if ($meta['type'] === 'array') {
                $description['meta'] = $this->exampleFromArray($meta, $class, 0);
            }
        }

        return $description;
    }

    /**
     * Resolve the "data" argument into an example value.
     *
     * @param  list<PhpToken>  $dataTokens
     * @param  list<PhpToken>  $methodTokens
     * @return array{example: mixed, resource: ?array{class: string, collection: bool}}|null
     */
    protected function resolveData(array $dataTokens, array $methodTokens, ReflectionClass $class): ?array
    {
        if (count($dataTokens) === 1 && $dataTokens[0]->is(T_VARIABLE)) {
            $assignment = $this->source->assignment($methodTokens, $dataTokens[0]->text);

            if ($assignment === null) {
                return null;
            }

            $builder = $this->builderCall($assignment, $class);

            if ($builder !== null) {
                return ['example' => $builder, 'resource' => null];
            }

            $dataTokens = $assignment;
        }

        $resource = $this->resourceReference($dataTokens, $class);

        if ($resource !== null) {
            $example = $this->resourceExample($resource['class'], 0);

            if ($example === null) {
                return ['example' => null, 'resource' => $resource];
            }

            return ['example' => $resource['collection'] ? [$example] : $example, 'resource' => $resource];
        }

        $node = $this->source->node($dataTokens);

        if ($node['type'] === 'array') {
            return ['example' => $this->exampleFromArray($node, $class, 0), 'resource' => null];
        }

        $text = trim($node['text']);

        if ($text === 'null') {
            return ['example' => null, 'resource' => null];
        }

        return null;
    }

    /**
     * Follow "$this->service->method(...)" to the array literal it returns,
     * when the service is an application class typed on the controller.
     *
     * @param  list<PhpToken>  $tokens
     */
    protected function builderCall(array $tokens, ReflectionClass $class): ?array
    {
        $text = implode('', array_map(fn (PhpToken $token): string => $token->text, $tokens));

        if (! preg_match('/^\$this->(\w+)->(\w+)\(/', $text, $match) || ! $class->hasProperty($match[1])) {
            return null;
        }

        $type = $class->getProperty($match[1])->getType();

        if (! $type instanceof ReflectionNamedType || ! str_starts_with($type->getName(), 'App\\') || ! method_exists($type->getName(), $match[2])) {
            return null;
        }

        $builder = new ReflectionMethod($type->getName(), $match[2]);
        $array = $this->source->returnedArray($builder);

        return $array !== null ? $this->exampleFromArray($array, $builder->getDeclaringClass(), 0) : null;
    }

    /**
     * Detect "new XResource(...)" or "XResource::collection(...)".
     *
     * @param  list<PhpToken>  $tokens
     * @return array{class: class-string<JsonResource>, collection: bool}|null
     */
    protected function resourceReference(array $tokens, ReflectionClass $context): ?array
    {
        $text = implode('', array_map(fn (PhpToken $token): string => $token->is([T_NEW]) ? 'new ' : $token->text, $tokens));

        if (preg_match('/(?:new\s+([\w\\\\]+)\(|([\w\\\\]+)::collection\()/', $text, $match)) {
            $name = ($match[1] ?? '') !== '' ? $match[1] : $match[2];
            $resolved = $this->source->resolveClass($name, $context);

            if ($resolved !== null && is_subclass_of($resolved, JsonResource::class)) {
                return ['class' => $resolved, 'collection' => ($match[1] ?? '') === ''];
            }
        }

        return null;
    }

    /**
     * Build an example object from a resource's toArray() array literal.
     *
     * @param  class-string<JsonResource>  $class
     */
    protected function resourceExample(string $class, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        try {
            $method = new ReflectionMethod($class, 'toArray');
        } catch (Throwable) {
            return null;
        }

        if ($method->getDeclaringClass()->getName() !== (new ReflectionClass($class))->getName()) {
            return null;
        }

        $array = $this->source->returnedArray($method);

        return $array !== null ? $this->exampleFromArray($array, $method->getDeclaringClass(), $depth + 1) : null;
    }

    /**
     * Turn a parsed array node into an example value.
     */
    protected function exampleFromArray(array $node, ReflectionClass $context, int $depth): array
    {
        $example = [];

        foreach ($node['entries'] as $entry) {
            $value = $this->exampleFromNode((string) $entry['key'], $entry['value'], $context, $depth);

            if ($entry['key'] === null) {
                $example[] = $value;
            } else {
                $example[$entry['key']] = $value;
            }
        }

        return $example;
    }

    /**
     * Turn a value node into an example value.
     */
    protected function exampleFromNode(string $key, array $node, ReflectionClass $context, int $depth): mixed
    {
        if ($node['type'] === 'array') {
            return $this->exampleFromArray($node, $context, $depth);
        }

        $literal = $this->source->stringLiteral($node['tokens']);

        if ($literal !== null) {
            return $literal;
        }

        if (count($node['tokens']) === 1) {
            $text = strtolower($node['tokens'][0]->text);

            if (in_array($text, ['true', 'false', 'null'], true)) {
                return match ($text) {
                    'true' => true,
                    'false' => false,
                    'null' => null,
                };
            }

            if ($node['tokens'][0]->is([T_LNUMBER, T_DNUMBER])) {
                return $node['tokens'][0]->text + 0;
            }
        }

        $resource = $this->resourceReference($node['tokens'], $context);

        if ($resource !== null) {
            $nested = $this->resourceExample($resource['class'], $depth) ?? [];

            return $resource['collection'] ? [$nested] : $nested;
        }

        foreach ($node['tokens'] as $index => $token) {
            if ($token->is(T_RETURN) && ($node['tokens'][$index + 1]->text ?? null) === '[') {
                $nested = $this->source->arrayAt($node['tokens'], $index + 1);

                if ($nested !== null) {
                    $item = $this->exampleFromArray($nested, $context, $depth);

                    return str_contains($node['text'], 'map') || str_contains($node['text'], 'collect') ? [$item] : $item;
                }
            }
        }

        return $this->examples->forResponseField($key, $node['text']);
    }

    /**
     * Collect errorResponse() calls with their status and message.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{status: int, message: string}>
     */
    protected function errorCalls(array $tokens): array
    {
        $errors = [];

        foreach ($this->source->callIndexes($tokens, 'errorResponse') as $openIndex) {
            $arguments = $this->source->callArguments($tokens, $openIndex);
            $messageTokens = $arguments['named']['message'] ?? $arguments['positional'][0] ?? null;
            $codeTokens = $arguments['named']['code'] ?? $arguments['positional'][2] ?? null;

            $message = $messageTokens !== null ? $this->source->readableString($messageTokens) : null;
            $status = $codeTokens !== null ? $this->statusCode($codeTokens) : Response::HTTP_BAD_REQUEST;

            $errors[] = [
                'status' => $status ?? Response::HTTP_BAD_REQUEST,
                'message' => $message ?? 'Unable to complete the request.',
            ];
        }

        return $errors;
    }

    /**
     * Get the first string literal in an expression (e.g. a ternary).
     *
     * @param  list<PhpToken>  $tokens
     */
    protected function firstLiteral(array $tokens): ?string
    {
        foreach ($tokens as $token) {
            $literal = $this->source->literalText($token);

            if ($literal !== null) {
                return $literal;
            }
        }

        return null;
    }

    /**
     * Resolve "Response::HTTP_CREATED" or "201" into a status code.
     *
     * @param  list<PhpToken>  $tokens
     */
    protected function statusCode(array $tokens): ?int
    {
        $text = implode('', array_map(fn (PhpToken $token): string => $token->text, $tokens));

        if (is_numeric($text)) {
            return (int) $text;
        }

        if (preg_match('/^(?:\\\\?[\w\\\\]*Response)::(HTTP_\w+)$/', $text, $match) && defined(Response::class.'::'.$match[1])) {
            return (int) constant(Response::class.'::'.$match[1]);
        }

        return null;
    }
}
