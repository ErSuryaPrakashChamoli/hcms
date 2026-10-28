<?php

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\ReportExports;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use Illuminate\Support\Facades\Storage;

// The read API runs a shared report for an integration, not a person: as its owner (rows and dataset permission),
// with only reviewed datasets and fields (analytics.unattended_datasets), and only when the key also holds the scopes
// guarding the same data elsewhere in the API. Default deny; every run and refusal is audited.
beforeEach(function () {
    $this->travelTo('2026-10-03 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->owner = tenantUser($this->tenant, ['*']); // no one is signed in: API requests carry only the key
    $company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['company_id' => $company->id, 'name' => 'Delhi']);
    $mumbai = Location::factory()->create(['company_id' => $company->id, 'name' => 'Mumbai']);
    $hire = fn (string $first, Location $at, string $born) => app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test', 'date_of_birth' => $born], ['joining_date' => '2025-01-01'], ['company_id' => $company->id, 'location_id' => $at->id]);
    $this->priya = $hire('Priya', $this->delhi, '1990-05-17');
    $this->rahul = $hire('Rahul', $mumbai, '1988-11-02');
    $this->report = fn (string $dataset, array $definition, ?int $owner = null) => Report::query()->create(['name' => "Shared {$dataset} ".uniqid(), 'dataset' => $dataset, 'definition' => $definition, 'owner_id' => $owner ?? $this->owner->id, 'is_shared' => true, 'status' => 'active']);
    $this->key = fn (array $scopes, ?DateTimeInterface $expires = null) => app(ApiKeys::class)->issue('Integration '.uniqid(), $scopes, $expires);
    $this->full = ($this->key)(['reports.run', 'employees.read', 'payroll.read'])['plaintext'];
    $this->roster = ($this->report)('employees', ['fields' => ['employee_code', 'location']]);
    $this->run = fn (Report $report, ?string $token) => $this->getJson("/api/v1/reports/{$report->id}/run", $token === null ? [] : ['X-Api-Key' => $token]);
    actAsTenant(null);
});

it('refuses a request without a usable key: none, malformed, wrong secret, revoked or expired', function () {
    actAsTenant($this->tenant);
    $revoked = ($this->key)(['reports.run', 'employees.read']);
    app(ApiKeys::class)->revoke($revoked['key'], 'Rotated');
    $expired = ($this->key)(['reports.run', 'employees.read'], now()->subMinute())['plaintext'];
    $wrongSecret = explode('.', $this->full)[0].'.'.str_repeat('x', 40);
    actAsTenant(null);

    foreach ([null, 'not-a-key', $wrongSecret, $revoked['plaintext'], $expired] as $token) {
        ($this->run)($this->roster, $token)->assertStatus(401)->assertJsonMissingPath('data');
    }
    ($this->run)($this->roster, $this->full)->assertOk();
});

it('refuses a key without reports.run, even with the data scopes', function () {
    actAsTenant($this->tenant);
    $token = ($this->key)(['employees.read', 'payroll.read'])['plaintext'];
    actAsTenant(null);

    ($this->run)($this->roster, $token)->assertStatus(403)->assertJsonPath('message', 'This key lacks the [reports.run] scope.')->assertJsonMissingPath('data');
});

it('refuses reports on datasets not reviewed for the API, returning no rows and auditing each refusal', function () {
    actAsTenant($this->tenant);
    $unreviewed = [
        ($this->report)('family_members', ['fields' => ['name', 'member', 'phone', 'date_of_birth']]),
        ($this->report)('ctc_register', ['fields' => ['name', 'ctc_annual']]),
        ($this->report)('statutory_bank', ['fields' => ['name', 'pan', 'uan', 'account_number']]),
        ($this->report)('exits', ['fields' => ['employee_code', 'reason_for_leaving', 'net_settlement']]),
    ];
    actAsTenant(null);

    foreach ($unreviewed as $report) {
        ($this->run)($report, $this->full)->assertStatus(403)->assertJsonPath('message', 'This report\'s dataset is not available through the API.')->assertJsonMissingPath('data');
    }

    actAsTenant($this->tenant);
    $refusals = AuditEvent::query()->where('module', 'analytics')->where('action', 'REJECTED')->get();
    expect($refusals)->toHaveCount(4)
        ->and($refusals->pluck('metadata.refusal')->unique()->all())->toBe(['dataset_not_reviewed'])
        ->and($refusals->pluck('metadata.channel')->unique()->all())->toBe(['api'])
        ->and(AuditEvent::query()->where('module', 'analytics')->where('action', 'EXPORT')->exists())->toBeFalse();
});

it('refuses a reviewed dataset when the key lacks its data scope, the owner lacks the dataset, or the owner is inactive', function () {
    actAsTenant($this->tenant);
    $payroll = ($this->report)('payroll', ['fields' => ['period', 'net_pay']]);
    $reportsOnly = ($this->key)(['reports.run'])['plaintext'];
    $noPayroll = ($this->key)(['reports.run', 'employees.read'])['plaintext'];
    $employeeViewer = tenantUser($this->tenant, ['employee.view', 'analytics.reports']);
    $notTheirs = ($this->report)('payroll', ['fields' => ['period', 'net_pay']], $employeeViewer->id);
    $leaver = tenantUser($this->tenant, ['*']);
    $orphaned = ($this->report)('employees', ['fields' => ['employee_code']], $leaver->id);
    $leaver->forceFill(['status' => UserStatus::Suspended])->save();
    actAsTenant(null);

    ($this->run)($this->roster, $reportsOnly)->assertStatus(403)->assertJsonPath('message', 'This key also needs the [employees.read] scope to run this report.');
    ($this->run)($payroll, $noPayroll)->assertStatus(403)->assertJsonPath('message', 'This key also needs the [payroll.read] scope to run this report.')->assertJsonMissingPath('data');
    ($this->run)($notTheirs, $this->full)->assertStatus(403)->assertJsonPath('message', 'You cannot report on the '.app(DatasetRegistry::class)->get('payroll')->label().' dataset.')->assertJsonMissingPath('data');
    ($this->run)($orphaned, $this->full)->assertStatus(403)->assertJsonMissingPath('data');
    ($this->run)($payroll, $this->full)->assertOk();

    actAsTenant($this->tenant);
    expect(AuditEvent::query()->where('module', 'analytics')->where('action', 'REJECTED')->orderBy('id')->get()->pluck('metadata.refusal')->all())
        ->toBe(['scope_missing', 'scope_missing', 'refused', 'owner_inactive']);
});

it('never runs another tenant\'s report', function () {
    $other = provisionTenant('Elsewhere');
    actAsTenant($other);
    $foreignToken = ($this->key)(['reports.run', 'employees.read', 'payroll.read'])['plaintext'];
    $foreignReport = Report::query()->where('name', 'Headcount by department')->sole();
    actAsTenant(null);

    ($this->run)($this->roster, $foreignToken)->assertNotFound()->assertJsonMissingPath('data');
    ($this->run)($foreignReport, $this->full)->assertNotFound()->assertJsonMissingPath('data');
    ($this->run)($foreignReport, $foreignToken)->assertOk()->assertJsonPath('data.total', 0);
});

it('returns only the rows the report owner may see', function () {
    actAsTenant($this->tenant);
    $delhiHr = tenantUser($this->tenant, ['*']);
    app(AccessScopes::class)->assign($delhiHr, ['location' => [$this->delhi->id]], 'Delhi only');
    $delhiRoster = ($this->report)('employees', ['fields' => ['employee_code', 'location']], $delhiHr->id);
    actAsTenant(null);

    $all = ($this->run)($this->roster, $this->full)->assertOk()->json('data.rows');
    expect(collect($all)->pluck('employee_code')->sort()->values()->all())->toBe(collect([$this->priya->employee_code, $this->rahul->employee_code])->sort()->values()->all());
    ($this->run)($delhiRoster, $this->full)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.employee_code', $this->priya->employee_code);
    expect(auth()->user())->toBeNull(); // the owner is never left signed in after the run
});

it('returns only reviewed fields, refusing any report that uses another, while signed-in exports keep the fields their user may see', function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    actAsTenant($this->tenant);
    $birthdays = ($this->report)('employees', ['fields' => ['employee_code', 'date_of_birth']]);
    $derived = [
        ($this->report)('employees', ['fields' => ['employee_code', 'birthday', 'age']]),
        ($this->report)('employees', ['fields' => ['employee_code'], 'filters' => [['field' => 'date_of_birth', 'operator' => 'gt', 'value' => '1990-01-01']]]),
        ($this->report)('employees', ['fields' => ['marital_status'], 'group_by' => 'marital_status', 'aggregations' => [['fn' => 'count']]]),
        ($this->report)('payroll', ['fields' => ['period'], 'aggregations' => [['fn' => 'sum', 'field' => 'taxable_earnings']], 'group_by' => 'period']),
    ];
    $everything = ($this->report)('employees', []);
    $payroll = ($this->report)('payroll', []);
    $export = app(ReportExports::class)->export($birthdays, $this->owner);
    actAsTenant(null);

    ($this->run)($birthdays, $this->full)->assertStatus(403)->assertJsonPath('message', 'This report uses fields that are not available without a signed-in user: date_of_birth.')->assertJsonMissingPath('data');
    foreach ($derived as $report) {
        ($this->run)($report, $this->full)->assertStatus(403)->assertJsonMissingPath('data');
    }

    $reviewed = fn (string $dataset) => config("peopleos.analytics.unattended_datasets.{$dataset}.fields");
    $columns = array_keys(($this->run)($everything, $this->full)->assertOk()->json('data.columns'));
    expect($columns)->not->toBeEmpty()->and(array_diff($columns, $reviewed('employees')))->toBe([])
        ->and($columns)->not->toContain('date_of_birth', 'birthday', 'next_birthday', 'age', 'marital_status', 'personal_email', 'personal_phone', 'ctc_annual');
    $columns = array_keys(($this->run)($payroll, $this->full)->assertOk()->json('data.columns'));
    expect(array_diff($columns, $reviewed('payroll')))->toBe([])->and($columns)->not->toContain('uan', 'esic_number', 'account_number', 'ifsc', 'bank_name', 'taxable_earnings', 'payment_date');

    // The owner's own export (signed in, with every permission) still carries the date of birth.
    actAsTenant($this->tenant);
    expect(app(ReportExports::class)->contents($export))->toContain('Date of birth')->toContain('1990-05-17');
});

it('audits every API run with the key, the owner it ran as and the size of the result', function () {
    ($this->run)($this->roster, $this->full)->assertOk()->assertJsonPath('data.total', 2);

    actAsTenant($this->tenant);
    $event = AuditEvent::query()->where('module', 'analytics')->where('action', 'EXPORT')->sole();
    expect($event->metadata['channel'])->toBe('api')
        ->and($event->metadata['api_key_id'])->toBe(ApiKey::query()->where('prefix', explode('.', $this->full)[0])->value('id'))
        ->and($event->metadata['run_as'])->toBe($this->owner->id)
        ->and($event->metadata['total'])->toBe(2)
        ->and($event->metadata['columns'])->toBe(['employee_code', 'location']);
});

it('refuses a run without a user unless the dataset and every field it uses were reviewed for it', function () {
    actAsTenant($this->tenant);
    $runner = app(ReportRunner::class);

    expect(fn () => $runner->execute('family_members', []))->toThrow(RuntimeException::class, 'not available without a signed-in user')
        ->and(fn () => $runner->execute('employees', ['fields' => ['employee_code', 'age']]))->toThrow(RuntimeException::class, 'age')
        ->and(fn () => $runner->execute('employees', ['fields' => ['employee_code']], $this->owner, unattended: true))->not->toThrow(RuntimeException::class)
        ->and(array_keys($runner->execute('employees', ['fields' => ['employee_code', 'date_of_birth']], $this->owner)->columns))->toBe(['employee_code', 'date_of_birth']);

    // Every reviewed field exists, is neither sensitive nor permissioned, and every listed scope is a real API scope.
    foreach (config('peopleos.analytics.unattended_datasets') as $key => $entry) {
        $fields = app(DatasetRegistry::class)->get($key)->fields();
        foreach ($entry['fields'] as $field) {
            expect($fields)->toHaveKey($field);
            expect(empty($fields[$field]['sensitive']) && empty($fields[$field]['permission']))->toBeTrue("{$key}.{$field} is flagged");
        }
        expect(array_diff($entry['scopes'], array_keys(config('peopleos.api.scopes'))))->toBe([]);
    }
    expect(array_keys(config('peopleos.analytics.unattended_datasets')))->not->toContain('exits', 'family_members', 'statutory_bank', 'ctc_register');
});
