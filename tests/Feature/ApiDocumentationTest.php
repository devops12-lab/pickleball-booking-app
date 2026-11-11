<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->withoutVite();

    config([
        'api-docs.enabled' => true,
        'api-docs.show_internal' => false,
        'api-docs.cache.enabled' => false,
    ]);
});

/**
 * Get the endpoint list passed to the documentation view.
 *
 * @return Collection<int, array<string, mixed>>
 */
function documentedEndpoints($response): Collection
{
    return collect($response->viewData('documentation')['endpoints']);
}

it('renders the portal with the CourtFlex logo as logo and favicon', function (): void {
    $response = $this->get('/docs-api-endpoints');

    $response->assertOk();
    $response->assertSee('CourtFlex API Documentation');
    $response->assertSee('<link rel="icon" type="image/png" href="'.asset('images/courtflex_logo.png').'">', false);
    $response->assertSee('<img src="'.asset('images/courtflex_logo.png').'" alt="CourtFlex" class="brand-logo">', false);
});

it('returns 404 when the documentation is disabled', function (): void {
    config(['api-docs.enabled' => false]);

    $this->get('/docs-api-endpoints')->assertNotFound();
});

it('documents only routes under the API prefix', function (): void {
    $endpoints = documentedEndpoints($this->get('/docs-api-endpoints'));

    expect($endpoints->pluck('uri')->all())
        ->toContain('/api/v1/players/bookings', '/api/v1/public/facilities')
        ->not->toContain('/', '/up', '/sanctum/csrf-cookie', '/docs-api-endpoints')
        ->each->toStartWith('/api/v1/');
});

it('hides internal admin routes unless internal routes are enabled', function (): void {
    $hidden = documentedEndpoints($this->get('/docs-api-endpoints'));

    config(['api-docs.show_internal' => true]);
    $shown = documentedEndpoints($this->get('/docs-api-endpoints'));

    expect($hidden->pluck('uri'))->not->toContain('/api/v1/admin/tenants');
    expect($shown->firstWhere('uri', '/api/v1/admin/tenants'))
        ->not->toBeNull()
        ->internal->toBeTrue()
        ->try_it->toBeFalse();
});

it('never documents routes listed as hidden', function (): void {
    config(['api-docs.hidden_routes' => ['players.bookings.*']]);

    $endpoints = documentedEndpoints($this->get('/docs-api-endpoints'));

    expect($endpoints->pluck('route_name'))
        ->not->toContain('players.bookings.store', 'players.bookings.index')
        ->toContain('players.dashboard');
});

it('documents a new API route without any manual changes', function (): void {
    config(['api-docs.cache.enabled' => true]);
    $this->get('/docs-api-endpoints');

    Route::middleware('api')->get('api/v1/sandbox/ping', fn () => ['pong' => true])->name('sandbox.ping');
    app('router')->getRoutes()->refreshNameLookups();
    $endpoints = documentedEndpoints($this->get('/docs-api-endpoints'));

    expect($endpoints->firstWhere('route_name', 'sandbox.ping'))
        ->not->toBeNull()
        ->method->toBe('GET')
        ->module->toBe('Other');
});

it('describes the booking endpoint from its route, form request and resource', function (): void {
    $endpoint = documentedEndpoints($this->get('/docs-api-endpoints'))->firstWhere('route_name', 'players.bookings.store');

    expect($endpoint)
        ->title->toBe('Reserve a court')
        ->method->toBe('POST')
        ->module->toBe('Players')
        ->roles->toBe(['player'])
        ->and($endpoint['authentication']['required'])->toBeTrue()
        ->and($endpoint['authentication']['guard'])->toBe('sanctum')
        ->and(collect($endpoint['body']['fields'])->pluck('name')->all())->toBe(['court_id', 'starts_at', 'ends_at', 'notes'])
        ->and(collect($endpoint['body']['fields'])->firstWhere('name', 'court_id')['required'])->toBe('required')
        ->and(collect($endpoint['rate_limits'])->pluck('summary')->all())->toBe([
            '120 requests / minute authenticated, 60 requests / minute guest',
            '30 requests / minute authenticated, 10 requests / minute guest',
        ])
        ->and($endpoint['response']['status'])->toBe(201)
        ->and($endpoint['response']['example']['message'])->toBe('Booking created. Please submit payment.')
        ->and(array_keys($endpoint['response']['example']['data']))->toContain('booking_reference', 'total_amount', 'status')
        ->and(collect($endpoint['response']['errors'])->pluck('status')->unique()->values()->all())->toBe([401, 403, 422, 429])
        ->and($endpoint['implementation'])->toBe([
            'controller' => 'Players\\BookingController@store',
            'form_request' => 'Bookings\\StoreBookingRequest',
            'resource' => 'BookingResource',
        ]);
});

it('marks public endpoints as not requiring authentication', function (): void {
    $endpoint = documentedEndpoints($this->get('/docs-api-endpoints'))->firstWhere('route_name', 'public.courts.availability');

    expect($endpoint['authentication']['required'])->toBeFalse()
        ->and(collect($endpoint['headers'])->pluck('name')->all())->not->toContain('Authorization')
        ->and(collect($endpoint['query_parameters'])->pluck('name')->all())->toBe(['starts_at', 'ends_at'])
        ->and($endpoint['path_parameters'][0])->toMatchArray(['name' => 'court', 'type' => 'integer', 'description' => 'Court ID']);
});

it('requires the tenant header on tenant scoped endpoints', function (): void {
    $endpoint = documentedEndpoints($this->get('/docs-api-endpoints'))->firstWhere('route_name', 'owners.facilities.index');

    expect($endpoint['tenant'])->toBe(['required' => true])
        ->and(collect($endpoint['headers'])->firstWhere('name', 'X-Tenant-ID')['required'])->toBeTrue();
});

it('builds the base URL from the requesting host', function (): void {
    $response = $this->get('https://api.courtflex.example/docs-api-endpoints');

    expect($response->viewData('documentation')['meta']['base_url'])->toBe('https://api.courtflex.example/api/v1');
});

it('uses the configured base URL instead of the requesting host', function (): void {
    config(['api-docs.base_url' => 'https://staging.courtflex.example/']);

    $response = $this->get('http://10.0.0.5/docs-api-endpoints');

    expect($response->viewData('documentation')['meta'])
        ->base_url->toBe('https://staging.courtflex.example/api/v1')
        ->root_url->toBe('https://staging.courtflex.example');
});

it('does not expose secrets or real tokens', function (): void {
    $response = $this->get('/docs-api-endpoints');

    $response->assertOk();
    $response->assertDontSee((string) config('app.key'), false);
    $response->assertDontSee(base_path(), false);
    $response->assertSee('YOUR_ACCESS_TOKEN');
});
