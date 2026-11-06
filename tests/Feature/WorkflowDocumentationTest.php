<?php

use App\Services\WorkflowDocumentation\MarkdownDocumentRepository;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->withoutVite();

    $this->docsDirectory = 'storage/framework/testing/workflow-docs-'.uniqid();
    File::ensureDirectoryExists(base_path($this->docsDirectory));

    config([
        'workflow-docs.enabled' => true,
        'workflow-docs.source_directory' => $this->docsDirectory,
        'workflow-docs.cache.enabled' => false,
        'workflow-docs.documents' => [],
    ]);
});

afterEach(function (): void {
    File::deleteDirectory(base_path($this->docsDirectory));
});

/**
 * Write a Markdown file into the temporary docs directory.
 */
function writeDoc(string $relativePath, string $contents): string
{
    $path = base_path(test()->docsDirectory.'/'.$relativePath);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);

    return $path;
}

it('renders the portal with the CourtFlex logo, favicon and a link to the API reference', function (): void {
    writeDoc('guide.md', "# Guide\n\nHello.");

    $response = $this->get('/docs-work-flow');

    $response->assertOk();
    $response->assertSee('CourtFlex Backend Workflows');
    $response->assertSee('<link rel="icon" type="image/png" href="'.asset('images/courtflex_logo.png').'">', false);
    $response->assertSee('<img src="'.asset('images/courtflex_logo.png').'" alt="CourtFlex" class="brand-logo">', false);
    $response->assertSee('href="'.route('api-docs').'"', false);
});

it('returns 404 when the workflow documentation is disabled', function (): void {
    config(['workflow-docs.enabled' => false]);

    $this->get('/docs-work-flow')->assertNotFound();
});

it('discovers Markdown files in nested directories and ignores other files', function (): void {
    writeDoc('overview.md', "# Overview\n");
    writeDoc('workflows/Booking_Flow.md', "# Booking flow\n");
    writeDoc('notes.txt', 'Not Markdown');
    writeDoc('.hidden.md', "# Hidden\n");
    writeDoc('.private/secret.md', "# Secret\n");

    $slugs = collect($this->get('/docs-work-flow')->viewData('documentation')['documents'])->pluck('slug');

    expect($slugs->sort()->values()->all())->toBe(['overview', 'workflows/booking-flow']);
});

it('returns a nested document with its headings as JSON', function (): void {
    writeDoc('workflows/booking.md', "# Booking\n\n## Create a booking\n\nSteps.\n\n### Availability\n\nRules.");

    $response = $this->getJson('/docs-work-flow/documents/workflows/booking');

    $response->assertOk();
    $response->assertJsonPath('data.title', 'Booking');
    $response->assertJsonPath('data.headings', [
        ['id' => 'h-create-a-booking', 'text' => 'Create a booking', 'level' => 2],
        ['id' => 'h-availability', 'text' => 'Availability', 'level' => 3],
    ]);
});

it('never loads files outside the discovered documents', function (string $slug): void {
    writeDoc('guide.md', "# Guide\n");
    File::put(base_path($this->docsDirectory.'/secret.txt'), 'APP_KEY=base64:secret');

    $this->getJson('/docs-work-flow/documents/'.$slug)->assertNotFound();
})->with([
    'parent directory traversal' => '..%2F..%2F.env',
    'encoded backslash traversal' => '..%5C..%5C.env',
    'environment file' => '.env',
    'non-Markdown file in the docs directory' => 'secret',
    'unknown document' => 'does-not-exist',
    'absolute path' => '%2Fetc%2Fpasswd',
]);

it('escapes raw HTML and removes unsafe links from Markdown', function (): void {
    writeDoc('unsafe.md', "# Unsafe\n\n<script>alert('xss')</script>\n\n[Click](javascript:alert(1)) <img src=x onerror=alert(1)>");

    $html = $this->getJson('/docs-work-flow/documents/unsafe')->json('data.html');

    expect($html)
        ->toContain('&lt;script&gt;')
        ->not->toContain("<script>alert('xss')</script>")
        ->not->toContain('javascript:')
        ->not->toContain('<img src=x onerror');
});

it('turns links to other Markdown files into portal links and marks broken ones', function (): void {
    writeDoc('workflows/payments.md', "# Payments\n");
    writeDoc('workflows/booking.md', "# Booking\n\nSee [payments](payments.md#verify) and [missing](nope.md).");

    $html = $this->getJson('/docs-work-flow/documents/workflows/booking')->json('data.html');

    expect($html)
        ->toContain('href="#/doc/workflows/payments?section=h-verify"')
        ->toContain('class="doc-link-broken"')
        ->toContain('Unresolved link: workflows/nope.md');
});

it('converts local file links into source references without exposing absolute paths', function (): void {
    $absolute = 'file:///'.str_replace('\\', '/', base_path('app/Models/User.php'));
    writeDoc('architecture.md', "# Architecture\n\nSee [User model]({$absolute}).");

    $html = $this->getJson('/docs-work-flow/documents/architecture')->json('data.html');

    expect($html)
        ->toContain('<code class="source-ref is-verified" title="Source: app/Models/User.php">app/Models/User.php</code>')
        ->not->toContain('file:')
        ->not->toContain(str_replace('\\', '/', base_path()));
});

it('marks source references as verified or unresolved', function (): void {
    writeDoc('refs.md', "# Refs\n\n`app/Models/User.php`, `app/Models/Missing.php`, `BookingAvailabilityService` and `ImaginaryService`.");

    $references = collect($this->getJson('/docs-work-flow/documents/refs')->json('data.references'))->pluck('verified', 'path');

    expect($references->all())->toBe([
        'app/Models/User.php' => true,
        'app/Models/Missing.php' => false,
        'app/Services/BookingAvailabilityService.php' => true,
        'ImaginaryService' => false,
    ]);
});

it('redacts passwords and tokens from rendered documents', function (): void {
    writeDoc('setup.md', "# Setup\n\n- Admin: `admin@example.test` (password: `Secret123!`)\n\n```json\n{\"password\": \"Hunter2!xyz\"}\n```\n\n| Email | Password |\n| --- | --- |\n| a@example.test | `Pa55word!` |");

    $html = $this->getJson('/docs-work-flow/documents/setup')->json('data.html');

    expect($html)
        ->not->toContain('Secret123!')
        ->not->toContain('Hunter2!xyz')
        ->not->toContain('Pa55word!')
        ->toContain('[redacted]');
});

it('reads status and category from front matter and rejects unknown statuses', function (): void {
    writeDoc('known.md', "---\ntitle: Known flow\ncategory: Booking management\nstatus: Implemented\nsources:\n  - app/Models/User.php\n  - app/Models/Missing.php\n---\n# Ignored heading\n");
    writeDoc('unknown.md', "---\nstatus: Finished\n---\n# Unknown\n");

    $known = $this->getJson('/docs-work-flow/documents/known')->json('data');
    $unknown = $this->getJson('/docs-work-flow/documents/unknown')->json('data');

    expect($known)
        ->title->toBe('Known flow')
        ->category->toBe('Booking management')
        ->status->toBe('Implemented')
        ->sources->toBe([
            ['path' => 'app/Models/User.php', 'verified' => true],
            ['path' => 'app/Models/Missing.php', 'verified' => false],
        ]);
    expect($unknown['status'])->toBe('Needs Verification');
});

it('shows discrepancy notes configured for a document', function (): void {
    writeDoc('ARCHITECTURE.md', "# Architecture\n");
    config(['workflow-docs.documents' => ['ARCHITECTURE.md' => ['status' => 'Needs Verification', 'notes' => ['Policies are never called.']]]]);

    $this->getJson('/docs-work-flow/documents/architecture')
        ->assertOk()
        ->assertJsonPath('data.notes', ['Policies are never called.']);
});

it('serves fresh content after a cached document changes', function (): void {
    config(['workflow-docs.cache.enabled' => true]);
    $path = writeDoc('changing.md', "# First version\n");
    $this->getJson('/docs-work-flow/documents/changing')->assertJsonPath('data.title', 'First version');

    File::put($path, "# Second version\n");
    touch($path, time() + 60);
    clearstatcache();
    $response = $this->getJson('/docs-work-flow/documents/changing');

    $response->assertJsonPath('data.title', 'Second version');
});

it('lists only existing source files in the verified workflow guides', function (): void {
    config(['workflow-docs.source_directory' => 'docs']);

    $sources = collect(app(MarkdownDocumentRepository::class)->all())
        ->filter(fn (array $document): bool => str_starts_with($document['slug'], 'workflows/'))
        ->flatMap(fn (array $document): array => $document['sources']);

    expect($sources)->not->toBeEmpty();
    expect($sources->reject(fn (array $source): bool => $source['verified'])->pluck('path')->all())->toBe([]);
});
