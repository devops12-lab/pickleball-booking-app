<?php

namespace App\Services\ApiDocumentation;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Discovers documentable API endpoints from the registered Laravel routes.
 */
class EndpointDiscoveryService
{
    public function __construct(
        protected Router $router,
        protected SourceInspector $source,
        protected RequestInspector $requests,
        protected ResponseInspector $responses,
        protected RateLimitInspector $rateLimits,
    ) {}

    /**
     * Get the routes that may appear in the documentation.
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        $prefix = trim((string) config('api-docs.prefix'), '/');

        return array_values(array_filter(
            $this->router->getRoutes()->getRoutes(),
            fn (Route $route): bool => ($route->uri() === $prefix || str_starts_with($route->uri(), $prefix.'/'))
                && ! $this->isHidden($route)
                && ($this->isInternal($route) === false || config('api-docs.show_internal')),
        ));
    }

    /**
     * Get a fingerprint of the documentable route table, used for caching.
     */
    public function fingerprint(): string
    {
        $signature = array_map(fn (Route $route): array => [
            $route->methods(),
            $route->uri(),
            $route->getName(),
            $route->getActionName(),
            $route->gatherMiddleware(),
        ], $this->routes());

        return md5(serialize([$signature, config('api-docs')]));
    }

    /**
     * Describe every documentable endpoint.
     *
     * @return list<array<string, mixed>>
     */
    public function endpoints(): array
    {
        return array_map(fn (Route $route): array => $this->describe($route), $this->routes());
    }

    /**
     * Determine whether a route is marked as internal.
     */
    public function isInternal(Route $route): bool
    {
        return Str::is((array) config('api-docs.internal_routes', []), $route->uri());
    }

    /**
     * Determine whether a route must never be documented.
     */
    protected function isHidden(Route $route): bool
    {
        $patterns = (array) config('api-docs.hidden_routes', []);

        return Str::is($patterns, $route->uri())
            || ($route->getName() !== null && Str::is($patterns, $route->getName()));
    }

    /**
     * Describe a single endpoint.
     *
     * @return array<string, mixed>
     */
    protected function describe(Route $route): array
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD']));
        $httpMethod = $methods[0] ?? 'GET';
        $uri = '/'.ltrim($route->uri(), '/');
        $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
        $controller = $this->controllerMethod($route);
        $docblock = $controller !== null ? $this->source->docblock($controller) : ['summary' => null, 'description' => null];
        $segments = explode('/', trim(Str::after($route->uri(), trim((string) config('api-docs.prefix'), '/')), '/'));
        $areaKey = $segments[0] ?? '';
        $resource = $this->resourceSegment($segments);
        $auth = $this->authentication($middleware);
        $tenantScoped = in_array('tenant.access', $middleware, true);
        $tenantAware = $tenantScoped || in_array('tenant.context', $middleware, true) || in_array('tenant.identify', $middleware, true);

        $formRequest = $controller !== null ? $this->requests->formRequestClass($controller) : null;
        $rules = $formRequest !== null ? $this->requests->formRequestRules($formRequest) : null;
        $rulesInferred = $formRequest === null || $rules !== null;
        $rules ??= [];

        if ($controller !== null) {
            $rules = array_merge($this->requests->inlineRules($controller), $rules);
        }

        $readInputs = $controller !== null ? $this->requests->readInputs($controller) : [];
        $fields = $this->requests->fields($rules);
        $isQueryMethod = in_array($httpMethod, ['GET', 'DELETE'], true);
        $bodyFields = $isQueryMethod ? [] : $fields;
        $queryParameters = $isQueryMethod ? $fields : [];

        foreach ($readInputs as $input) {
            if (! collect($fields)->contains('name', $input['name']) && ($isQueryMethod || $input['name'] === 'per_page' || $input['name'] === 'page')) {
                $queryParameters[] = [
                    'name' => $input['name'],
                    'type' => $input['type'],
                    'required' => 'optional',
                    'nullable' => true,
                    'rules' => $input['default'] !== null ? ['default: '.$input['default']] : [],
                    'example' => $input['default'] ?? null,
                ];
            }
        }

        $response = $controller !== null ? $this->responses->describe($controller) : null;
        $isFileUpload = collect($bodyFields)->contains('type', 'file');

        return [
            'id' => $this->identifier($route, $httpMethod),
            'title' => $docblock['summary'] ?? Str::headline(($route->getActionMethod() === 'Closure' ? '' : $route->getActionMethod()).' '.$resource),
            'description' => $docblock['description'],
            'method' => $httpMethod,
            'methods' => $methods,
            'uri' => $uri,
            'route_name' => $route->getName(),
            'module' => $this->module($route),
            'area' => config('api-docs.areas.'.$areaKey, Str::headline($areaKey)),
            'resource' => Str::headline($resource),
            'internal' => $this->isInternal($route),
            'destructive' => $httpMethod === 'DELETE' || Str::contains((string) $route->getActionMethod(), ['destroy', 'revoke', 'delete', 'cancel']),
            'authentication' => $auth,
            'roles' => $this->middlewareArguments($middleware, ['role']),
            'permissions' => $this->middlewareArguments($middleware, ['permission', 'staff.permission']),
            'requires_active_account' => in_array('account.active', $middleware, true),
            'tenant' => $tenantAware ? ['required' => $tenantScoped] : null,
            'middleware' => $middleware,
            'rate_limits' => array_values(array_filter(array_map(fn (string $item): ?array => $this->rateLimits->describe($item), $middleware))),
            'headers' => $this->headers($auth['required'], $tenantAware, $tenantScoped, $bodyFields !== [], $isFileUpload),
            'path_parameters' => $this->pathParameters($route),
            'query_parameters' => $queryParameters,
            'body' => [
                'content_type' => $isFileUpload ? 'multipart/form-data' : 'application/json',
                'fields' => $bodyFields,
                'example' => $this->requests->examplePayload($bodyFields) ?: null,
                'rules_inferred' => $rulesInferred,
            ],
            'response' => $this->responseDocumentation($response, $auth['required'], $middleware, $fields, $tenantScoped, $route),
            'implementation' => config('api-docs.show_implementation') ? [
                'controller' => $controller !== null
                    ? Str::after($controller->getDeclaringClass()->getName(), (string) config('api-docs.namespaces.controllers')).'@'.$controller->getName()
                    : null,
                'form_request' => $formRequest !== null ? Str::after($formRequest, (string) config('api-docs.namespaces.requests')) : null,
                'resource' => $response['resource'] ?? null
                    ? class_basename($response['resource']['class']).($response['resource']['collection'] ? ' (collection)' : '')
                    : null,
            ] : null,
        ];
    }

    /**
     * Get the reflected controller method for a route.
     */
    protected function controllerMethod(Route $route): ?ReflectionMethod
    {
        $controller = $route->getControllerClass();
        $method = $route->getActionMethod();

        if ($controller === null || ! method_exists($controller, $method)) {
            return null;
        }

        try {
            return new ReflectionMethod($controller, $method);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Pick the sidebar module for a route.
     */
    protected function module(Route $route): string
    {
        foreach ((array) config('api-docs.modules', []) as $pattern => $module) {
            if (Str::is($pattern, $route->uri())) {
                return $module;
            }
        }

        return 'Other';
    }

    /**
     * Get the functional resource segment, e.g. "bookings" or "auth".
     *
     * @param  list<string>  $segments
     */
    protected function resourceSegment(array $segments): string
    {
        foreach (array_slice($segments, 1) as $segment) {
            if ($segment !== '' && ! str_starts_with($segment, '{')) {
                return $segment;
            }
        }

        return $segments[0] ?? 'general';
    }

    /**
     * Build a stable identifier for an endpoint.
     */
    protected function identifier(Route $route, string $method): string
    {
        return $route->getName() ?? Str::slug(strtolower($method).' '.str_replace(['/', '{', '}'], ' ', $route->uri()));
    }

    /**
     * Describe the authentication requirement from middleware.
     *
     * @param  list<string>  $middleware
     * @return array{required: bool, guard: ?string, scheme: ?string}
     */
    protected function authentication(array $middleware): array
    {
        foreach ($middleware as $item) {
            if ($item === 'auth' || str_starts_with($item, 'auth:')) {
                $guard = str_contains($item, ':') ? Str::after($item, ':') : (string) config('auth.defaults.guard');

                return [
                    'required' => true,
                    'guard' => $guard,
                    'scheme' => $guard === 'sanctum' ? 'Bearer token (Laravel Sanctum personal access token)' : 'Guard: '.$guard,
                ];
            }
        }

        return ['required' => false, 'guard' => null, 'scheme' => null];
    }

    /**
     * Get the arguments of parameterized middleware such as "role:owner".
     *
     * @param  list<string>  $middleware
     * @param  list<string>  $names
     * @return list<string>
     */
    protected function middlewareArguments(array $middleware, array $names): array
    {
        $arguments = [];

        foreach ($middleware as $item) {
            [$name, $parameters] = array_pad(explode(':', $item, 2), 2, null);

            if (in_array($name, $names, true) && $parameters !== null) {
                array_push($arguments, ...explode(',', $parameters));
            }
        }

        return array_values(array_unique($arguments));
    }

    /**
     * Describe the request headers.
     *
     * @return list<array{name: string, value: string, required: bool, description: string}>
     */
    protected function headers(bool $authenticated, bool $tenantAware, bool $tenantRequired, bool $hasBody, bool $isFileUpload): array
    {
        $headers = [
            ['name' => 'Accept', 'value' => 'application/json', 'required' => true, 'description' => 'Always request JSON responses.'],
        ];

        if ($hasBody) {
            $headers[] = $isFileUpload
                ? ['name' => 'Content-Type', 'value' => 'multipart/form-data', 'required' => true, 'description' => 'File uploads are sent as multipart form data.']
                : ['name' => 'Content-Type', 'value' => 'application/json', 'required' => true, 'description' => 'The request body is JSON.'];
        }

        if ($authenticated) {
            $headers[] = ['name' => 'Authorization', 'value' => 'Bearer '.ExampleValueFactory::PLACEHOLDER_TOKEN, 'required' => true, 'description' => 'Personal access token returned by a login endpoint.'];
        }

        if ($tenantAware) {
            $headers[] = [
                'name' => 'X-Tenant-ID',
                'value' => '1',
                'required' => $tenantRequired,
                'description' => 'Tenant ID or slug that selects the facility account.'.($tenantRequired ? ' Requests without a tenant are rejected.' : ''),
            ];
        }

        return $headers;
    }

    /**
     * Describe the URI parameters and their route-model bindings.
     *
     * @return list<array{name: string, type: string, required: bool, description: string, example: string}>
     */
    protected function pathParameters(Route $route): array
    {
        $bindings = [];

        foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType) {
                $bindings[$parameter->getName()] = $type->getName();
            }
        }

        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $model = $bindings[$name] ?? null;
            $key = $route->bindingFieldFor($name);

            if ($model !== null && $key === null) {
                try {
                    $key = (new $model)->getRouteKeyName();
                } catch (Throwable) {
                    $key = 'id';
                }
            }

            $isNumeric = $model !== null && in_array($key, ['id', null], true);

            $parameters[] = [
                'name' => $name,
                'type' => $isNumeric ? 'integer' : 'string',
                'required' => ! str_contains($route->uri(), '{'.$name.'?}'),
                'description' => $model !== null
                    ? Str::headline(class_basename($model)).' '.($key === 'id' || $key === null ? 'ID' : $key)
                    : Str::headline($name),
                'example' => $isNumeric ? '1' : 'example',
            ];
        }

        return $parameters;
    }

    /**
     * Assemble the success and error responses for an endpoint.
     *
     * @param  list<string>  $middleware
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    protected function responseDocumentation(?array $response, bool $authenticated, array $middleware, array $fields, bool $tenantScoped, Route $route): array
    {
        $success = null;

        if ($response !== null && $response['found']) {
            $success = ['success' => true, 'message' => $response['message'], 'data' => $response['inferred'] ? $response['data'] : null];

            if ($response['meta'] !== null) {
                $success['meta'] = $response['meta'];
            }
        }

        $errors = [];
        $add = function (int $status, string $message, array $errorBag = []) use (&$errors): void {
            $key = $status.'|'.$message;
            $errors[$key] ??= ['status' => $status, 'body' => ['success' => false, 'message' => $message, 'errors' => (object) $errorBag]];
        };

        if ($authenticated) {
            $add(401, 'Unauthenticated.');
        }

        if (in_array('account.active', $middleware, true)) {
            $add(403, 'Your account has been suspended or is not active. Please contact support.');
        }

        if ($tenantScoped) {
            $add(400, 'No tenant context provided for this operation.');
            $add(403, 'You do not have administrative or staff access to this facility tenant.');
        }

        $roles = $this->middlewareArguments($middleware, ['role']);

        if ($roles !== []) {
            $add(403, 'Unauthorized: You do not possess the required role ['.implode(', ', $roles).'] for this API endpoint.');
        }

        foreach ($this->middlewareArguments($middleware, ['permission', 'staff.permission']) as $permission) {
            $add(403, 'You lack the required permission ['.$permission.'] for this action.');
        }

        if ($route->signatureParameters(['subClass' => UrlRoutable::class]) !== []) {
            $add(404, 'The requested resource was not found.');
        }

        foreach ($response['errors'] ?? [] as $error) {
            $add($error['status'], $error['message']);
        }

        if ($fields !== []) {
            $add(422, 'The given data was invalid.', $this->requests->validationErrors($fields));
        }

        if (collect($middleware)->contains(fn (string $item): bool => str_starts_with($item, 'throttle:'))) {
            $add(429, 'Too Many Attempts.');
        }

        $errors = array_values($errors);
        usort($errors, fn (array $a, array $b): int => $a['status'] <=> $b['status']);

        return [
            'status' => $response['status'] ?? 200,
            'paginated' => $response['paginated'] ?? false,
            'example' => $success,
            'data_documented' => $success !== null && $response['inferred'],
            'errors' => $errors,
        ];
    }
}
