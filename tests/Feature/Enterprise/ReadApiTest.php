<?php

use App\Domain\Analytics\Models\Report;
use App\Domain\Integration\Services\ApiKeys;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->employee = activeEmployee();
    $issued = app(ApiKeys::class)->issue('Integration', ['employees.read', 'leave.read', 'assets.read', 'reports.run', 'workflows.read']);
    $this->token = $issued['plaintext'];
    actAsTenant(null);
});

it('serves scoped, paginated read endpoints and shared reports', function () {
    $h = ['X-Api-Key' => $this->token];
    $this->getJson('/api/v1/employees?employed=1', $h)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.employee_code', $this->employee->employee_code);
    $this->getJson('/api/v1/employees/'.$this->employee->id, $h)->assertOk()->assertJsonPath('data.lifecycle_state', 'active');
    $this->getJson('/api/v1/leave/balances', $h)->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/assets', $h)->assertOk();
    $this->getJson('/api/v1/workflows/instances', $h)->assertOk();
    $this->getJson('/api/v1/payroll/payslips', $h)->assertStatus(403); // scope not granted
    $this->getJson('/api/v1/employees')->assertStatus(401);

    $report = Report::query()->where('name', 'Headcount by department')->first();
    $this->getJson("/api/v1/reports/{$report->id}/run", $h)->assertOk()->assertJsonPath('data.grouped', true)->assertJsonPath('data.total', 1);
    actAsTenant($this->tenant);
    $report->update(['is_shared' => false]);
    actAsTenant(null);
    $this->getJson("/api/v1/reports/{$report->id}/run", $h)->assertStatus(403);
});
