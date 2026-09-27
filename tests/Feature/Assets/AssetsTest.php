<?php

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Services\Assets;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->assets = app(Assets::class);
    $this->employee = activeEmployee(null, ['asset.own', 'task.view']);
    $this->other = activeEmployee(null, ['asset.own', 'task.view']);
    $this->laptop = $this->assets->receive(['asset_category_id' => AssetCategory::query()->where('code', 'LAPTOP')->value('id'), 'asset_tag' => 'lt-001', 'name' => 'Dell Latitude', 'serial_number' => 'SN1', 'purchase_cost' => 85000, 'purchase_date' => '2026-09-01']);
});

it('seeds default categories and normalises tags', function () {
    expect(AssetCategory::query()->count())->toBe(11)->and($this->laptop->asset_tag)->toBe('LT-001')->and($this->laptop->status)->toBe('in_stock')
        ->and($this->laptop->movements()->pluck('type')->all())->toBe(['procured']);
});

it('walks the lifecycle: assign, acknowledge, transfer, return, repair, dispose, with movements and clearance', function () {
    $assignment = $this->assets->assign($this->laptop, $this->employee, '2026-09-21', 'new', 'Joiner kit');
    expect($this->laptop->refresh()->status)->toBe('assigned')->and($this->laptop->custodian_id)->toBe($this->employee->id)
        ->and($this->employee->user->notifications()->count())->toBe(1)
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $this->employee->id)->where('category', 'assets')->count())->toBe(1)
        ->and($this->assets->clearanceFor($this->employee)->count())->toBe(1);
    expect(fn () => $this->assets->assign($this->laptop, $this->other))->toThrow(RuntimeException::class, 'cannot be assigned');
    expect(fn () => $this->assets->dispose($this->laptop, 'scrap'))->toThrow(RuntimeException::class, 'return it first');

    $this->assets->acknowledge($assignment);
    expect($assignment->refresh()->acknowledged_at)->not->toBeNull();

    $this->assets->transfer($this->laptop->refresh(), $this->other, 'Handover');
    expect($this->laptop->refresh()->custodian_id)->toBe($this->other->id)
        ->and($assignment->refresh()->status)->toBe('returned')
        ->and($this->assets->clearanceFor($this->employee)->count())->toBe(0)
        ->and($this->assets->clearanceFor($this->other)->count())->toBe(1)
        ->and($this->laptop->movements()->first()->type)->toBe('transferred');

    $this->assets->returnAsset($this->laptop->refresh(), 'fair', 'Back to stock');
    expect($this->laptop->refresh()->status)->toBe('in_stock')->and($this->laptop->custodian_id)->toBeNull()->and($this->laptop->condition)->toBe('fair');

    $repair = $this->assets->sendForRepair($this->laptop, 'Keyboard broken', 'Dell');
    expect($this->laptop->refresh()->status)->toBe('in_repair');
    expect(fn () => $this->assets->assign($this->laptop, $this->employee))->toThrow(RuntimeException::class, 'In repair');
    $this->assets->repaired($repair, 2500, 'Keyboard replaced');
    expect($this->laptop->refresh()->status)->toBe('in_stock')->and($this->laptop->condition)->toBe('good')->and($repair->refresh()->status)->toBe('closed');

    $this->assets->dispose($this->laptop, 'scrap', '2026-09-30', 0, 'GP-1', 'End of life');
    expect($this->laptop->refresh()->status)->toBe('disposed')
        ->and($this->laptop->disposal->method)->toBe('scrap')
        ->and($this->laptop->movements()->reorder('id')->pluck('type')->all())->toBe(['procured', 'assigned', 'transferred', 'returned', 'repair_out', 'repair_in', 'disposed'])
        ->and(AuditEvent::query()->where('module', 'assets')->where('entity_id', $this->laptop->id)->count())->toBeGreaterThan(3);
});

it('handles lost, found and retire, and refuses assignment to non-employees', function () {
    $this->assets->assign($this->laptop, $this->employee);
    $this->assets->markLost($this->laptop->refresh(), 'Left in a cab');
    expect($this->laptop->refresh()->status)->toBe('lost')->and($this->laptop->custodian_id)->toBeNull()->and($this->assets->clearanceFor($this->employee)->count())->toBe(0);
    $this->assets->found($this->laptop, 'Cab company returned it');
    expect($this->laptop->refresh()->status)->toBe('in_stock');
    $this->assets->retire($this->laptop, 'Too old');
    expect($this->laptop->refresh()->status)->toBe('retired');

    $leaver = activeEmployee();
    $leaver->update(['lifecycle_state' => 'exited']);
    $monitor = $this->assets->receive(['asset_category_id' => AssetCategory::query()->where('code', 'MONITOR')->value('id'), 'asset_tag' => 'MON-1', 'name' => 'Monitor']);
    expect(fn () => $this->assets->assign($monitor, $leaver->refresh()))->toThrow(RuntimeException::class, 'current employees');
    expect($this->other->user->can('view', $monitor))->toBeFalse();
    $this->assets->assign($monitor, $this->other);
    expect($this->other->user->can('view', $monitor->refresh()))->toBeTrue()->and($this->employee->user->can('view', $monitor))->toBeFalse();
});
