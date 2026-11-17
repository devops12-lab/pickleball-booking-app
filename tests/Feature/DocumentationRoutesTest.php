<?php

use App\Services\WorkflowDocumentation\MarkdownDocumentRepository;

beforeEach(function (): void {
    $this->withoutVite();

    config([
        'workflow-docs.enabled' => true,
        'workflow-docs.source_directory' => 'docs',
        'workflow-docs.cache.enabled' => false,
    ]);
});

test('api documentation portal returns 200', function (): void {
    config(['api-docs.enabled' => true, 'api-docs.cache.enabled' => false]);

    $this->get('/docs-api-endpoints')->assertOk();
});

test('data model portal returns 200', function (): void {
    config(['data-model-docs.enabled' => true, 'data-model-docs.cache.enabled' => false]);

    $this->get('/docs-data-model')->assertOk();
});

test('workflow documentation portal returns 200', function (): void {
    $this->get('/docs-work-flow')->assertOk();
});

test('new workflow docs are all discoverable', function (string $slug, string $expectedTitle): void {
    $documents = collect(app(MarkdownDocumentRepository::class)->all());
    $doc = $documents->firstWhere('slug', 'workflows/'.$slug);

    expect($doc)->not->toBeNull();
    expect($doc['title'])->toBe($expectedTitle);
})->with([
    ['staff-management', 'Staff management'],
    ['subscriptions', 'Subscriptions'],
    ['advertisements', 'Advertisements'],
    ['admin-configuration', 'Admin configuration'],
    ['guest-bookings', 'Guest bookings'],
]);

test('all new workflow docs have only verified source references', function (): void {
    $newDocs = ['workflows/staff-management', 'workflows/subscriptions', 'workflows/advertisements', 'workflows/admin-configuration', 'workflows/guest-bookings'];

    $unverified = collect(app(MarkdownDocumentRepository::class)->all())
        ->filter(fn (array $doc): bool => in_array($doc['slug'], $newDocs, true))
        ->flatMap(fn (array $doc): array => $doc['sources'])
        ->reject(fn (array $source): bool => $source['verified']);

    expect($unverified->pluck('path')->all())->toBe([]);
});
