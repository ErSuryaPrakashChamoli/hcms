<?php

use App\Domain\Bgv\Events\BgvCompleted;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Bgv\Services\Bgv;
use App\Domain\Employment\Models\Employee;
use App\Domain\Integration\Services\ApiKeys;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->employee = Employee::factory()->create();
    $this->bgv = app(Bgv::class);
});

it('requires consent, creates the configured checks and blocks a second open case', function () {
    expect(fn () => $this->bgv->initiate($this->employee))->toThrow(RuntimeException::class, 'consent');

    $case = $this->bgv->initiate($this->employee, ['identity', 'education'], 'manual', true, null, 'Standard');

    expect($case->status)->toBe('initiated')
        ->and($case->consent_given_at)->not->toBeNull()
        ->and($case->checks->pluck('type')->all())->toBe(['identity', 'education'])
        ->and($this->employee->timelineEntries()->where('category', 'bgv')->value('title'))->toBe('Background verification initiated');

    expect(fn () => $this->bgv->initiate($this->employee, [], 'manual', true))->toThrow(RuntimeException::class, 'already open');
});

it('closes the case with the worst result once every check is in', function () {
    Event::fake([BgvCompleted::class]);
    $case = $this->bgv->initiate($this->employee, ['identity', 'address', 'employment'], 'manual', true);
    [$identity, $address, $employment] = $case->checks;

    $this->bgv->recordCheck($identity, 'clear', 'Aadhaar matched');
    expect($case->fresh()->status)->toBe('in_progress')->and($case->fresh()->overall_result)->toBe('pending');

    $this->bgv->recordCheck($address, 'discrepancy', 'Old address on file');
    $this->bgv->recordCheck($employment, 'clear');

    $case->refresh();
    expect($case->status)->toBe('completed')
        ->and($case->overall_result)->toBe('discrepancy')
        ->and($case->completed_at)->not->toBeNull()
        ->and($this->employee->timelineEntries()->where('title', 'like', 'Background verification completed%')->value('title'))->toBe('Background verification completed: Discrepancy');
    Event::assertDispatched(BgvCompleted::class);

    expect(fn () => $this->bgv->recordCheck($identity, 'bogus'))->toThrow(RuntimeException::class, 'Unknown check status');
});

it('accepts vendor results through the callback API', function () {
    $case = $this->bgv->initiate($this->employee, ['identity', 'address'], 'manual', true);
    $case->update(['external_reference' => 'VENDOR-9']);
    $key = app(ApiKeys::class)->issue('Vendor', ['bgv.write']);
    auth()->logout();
    actAsTenant(null);

    $this->withHeader('X-Api-Key', $key['plaintext'])
        ->postJson('/api/v1/bgv/cases/VENDOR-9/checks', ['checks' => [['type' => 'identity', 'status' => 'clear'], ['type' => 'address', 'status' => 'failed', 'notes' => 'Not found']]])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.overall_result', 'failed');

    $this->withHeader('X-Api-Key', $key['plaintext'])
        ->postJson('/api/v1/bgv/cases/VENDOR-9/checks', ['checks' => [['type' => 'nope', 'status' => 'clear']]])
        ->assertStatus(422);
});

it('closes an open case manually, skipping open checks', function () {
    $case = $this->bgv->initiate($this->employee, ['identity', 'address'], 'manual', true);
    $this->bgv->close($case, 'Candidate withdrew');

    expect($case->fresh()->status)->toBe('closed')
        ->and($case->checks()->where('status', 'skipped')->count())->toBe(2)
        ->and(BgvCase::query()->count())->toBe(1);
});
