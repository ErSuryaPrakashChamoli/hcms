<?php

use App\Domain\Lifecycle\Enums\LifecycleState;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| Streamline S1 (owner 2026-11-05: "see if every function is working"): every page of the panel, and the list and create
| page of every register, opens without a server error for a tenant administrator holding every permission, and every
| page an employee may open opens for an employee. Pages that need a record or a query parameter are opened by the
| feature tests of their module; here a page may answer 403 or redirect, never 500.
*/

/** @return list<array{0: string, 1: string}> [label, url] of every page and register screen without a route parameter */
function everyPanelUrl(): array
{
    $panel = Filament::getPanel('admin');
    $panel->boot();
    $urls = [];
    foreach ($panel->getPages() as $page) {
        /** @var class-string<Page> $page */
        if (rescue(fn () => $page::canAccess(), false, false)) {
            $urls[] = [class_basename($page), $page::getUrl()];
        }
    }
    foreach ($panel->getResources() as $resource) {
        /** @var class-string<resource> $resource */
        foreach (['index', 'create'] as $name) {
            if (! $resource::hasPage($name)) {
                continue;
            }
            $allowed = $name === 'index' ? rescue(fn () => $resource::canAccess(), false, false) : rescue(fn () => $resource::canAccess() && $resource::canCreate(), false, false);
            if ($allowed) {
                $urls[] = [class_basename($resource).' '.$name, $resource::getUrl($name)];
            }
        }
    }

    return $urls;
}

/** @return list<string> the screens that answered with a server error, with the error's first line */
function serverErrors($test, array $urls): array
{
    $failed = [];
    foreach ($urls as [$label, $url]) {
        $response = $test->get($url);
        if ($response->getStatusCode() >= 500) {
            $message = strtok((string) ($response->exception?->getMessage() ?? 'HTTP '.$response->getStatusCode()), "\n");
            $failed[] = "{$label}: {$message}";
        }
    }

    return $failed;
}

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
});

it('opens every page and register for an administrator with every permission', function () {
    $admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($admin);
    $urls = everyPanelUrl();

    expect(count($urls))->toBeGreaterThan(300)
        ->and(serverErrors($this, $urls))->toBe([]);
});

it('opens every page an employee may open', function () {
    $employee = employeeWithUser(null, ['employee.self', 'leave.apply', 'attendance.regularise', 'payroll.payslip', 'payroll.request', 'servicedesk.request', 'kb.view', 'communication.view', 'task.view', 'task.act']);
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $this->actingAs($employee->user);
    $urls = everyPanelUrl();

    expect(count($urls))->toBeGreaterThan(10)
        ->and(serverErrors($this, $urls))->toBe([]);
});
