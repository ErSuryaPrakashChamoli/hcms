<?php

use App\Domain\Communication\Services\Communications;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Filament\Pages\AnnouncementsFeed;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| QA security: an announcement's body is Markdown written by whoever prepares communications, and it is shown to its
| whole audience. Raw HTML and javascript: links in it must reach readers as text, never as live markup (stored XSS
| would act as every reader, administrators included).
*/

it('shows an announcement\'s raw HTML and script links as text in the readers\' feed', function () {
    $tenant = provisionTenant('Alpha');
    actAsTenant($tenant);
    $author = tenantUser($tenant, ['communication.manage']);
    $announcement = app(Communications::class)->create([
        'title' => 'Town hall',
        'type' => array_key_first(config('peopleos.communication.types')),
        'body' => "**Town hall** on Friday.\n\n<img src=x onerror=\"alert(document.cookie)\">\n\n[Open](javascript:alert(1))\n\n<script>alert(2)</script>",
    ], $author);
    // Published to everyone (the approval route is not what is under test here).
    $announcement->forceFill(['status' => 'published', 'publish_at' => now()->subMinute()])->saveQuietly();

    $reader = forceLifecycle(employeeWithUser(null, ['employee.self', 'communication.view']), LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $this->actingAs($reader->user);
    $html = $this->get(AnnouncementsFeed::getUrl())->assertOk()->getContent();

    expect($html)->toContain('<strong>Town hall</strong>')
        ->not->toContain('<img src="x" onerror')
        ->not->toContain('<img src=x onerror')
        ->not->toContain('href="javascript:')
        ->not->toContain('<script>alert(2)</script>');
});
