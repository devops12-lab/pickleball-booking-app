<?php

namespace App\Services\ApiDocumentation;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Reads the limits of named rate limiters registered with RateLimiter::for().
 */
class RateLimitInspector
{
    /**
     * @var array<string, array|null>
     */
    protected array $resolved = [];

    /**
     * Describe a "throttle:..." middleware value.
     *
     * @return array{name: string, guest: ?string, authenticated: ?string, summary: string}|null
     */
    public function describe(string $middleware): ?array
    {
        if (! str_starts_with($middleware, 'throttle:')) {
            return null;
        }

        $arguments = explode(',', substr($middleware, 9));
        $name = $arguments[0];

        if (is_numeric($name)) {
            $limit = $name.' requests / '.(($arguments[1] ?? 1) == 1 ? 'minute' : ($arguments[1].' minutes'));

            return ['name' => $limit, 'guest' => $limit, 'authenticated' => $limit, 'summary' => $limit];
        }

        return $this->resolved[$name] ??= $this->resolve($name);
    }

    /**
     * Evaluate a named limiter for a guest and an authenticated request.
     *
     * @return array{name: string, guest: ?string, authenticated: ?string, summary: string}|null
     */
    protected function resolve(string $name): ?array
    {
        $limiter = RateLimiter::limiter($name);

        if ($limiter === null) {
            return ['name' => $name, 'guest' => null, 'authenticated' => null, 'summary' => 'Named limiter "'.$name.'"'];
        }

        $guest = $this->evaluate($limiter, Request::create('/', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']));

        $authenticatedRequest = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $authenticatedRequest->setUserResolver(fn (): User => (new User)->forceFill(['id' => 0]));
        $authenticated = $this->evaluate($limiter, $authenticatedRequest);

        $summary = match (true) {
            $guest === $authenticated => (string) $guest,
            $guest === null => $authenticated.' (authenticated)',
            $authenticated === null => $guest.' (guest)',
            default => $authenticated.' authenticated, '.$guest.' guest',
        };

        return ['name' => $name, 'guest' => $guest, 'authenticated' => $authenticated, 'summary' => $summary];
    }

    /**
     * Run the limiter callback and format the resulting limit.
     */
    protected function evaluate(callable $limiter, Request $request): ?string
    {
        try {
            $limits = Arr::wrap($limiter($request));
        } catch (Throwable) {
            return null;
        }

        $descriptions = [];

        foreach ($limits as $limit) {
            if ($limit instanceof Limit) {
                $descriptions[] = $this->format($limit);
            }
        }

        return $descriptions === [] ? null : implode(' + ', $descriptions);
    }

    /**
     * Format a limit as "5 requests / minute".
     */
    protected function format(Limit $limit): string
    {
        $period = match ($limit->decaySeconds) {
            1 => 'second',
            60 => 'minute',
            3600 => 'hour',
            86400 => 'day',
            default => $limit->decaySeconds.' seconds',
        };

        return $limit->maxAttempts.' requests / '.$period;
    }
}
