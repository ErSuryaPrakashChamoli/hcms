<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\ComplianceReports\Builders\EmployeeRegister;
use App\Domain\ComplianceReports\Contracts\ReportBuilder;
use App\Domain\ComplianceReports\Models\ComplianceReportPack;
use App\Domain\ComplianceReports\Services\ComplianceReports;
use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Establishment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';

/** A builder that always fails, to prove a failure is reported and never presented as a workbook. */
class FailingComplianceBuilder implements ReportBuilder
{
    public function build(ReportContext $context): array
    {
        throw new RuntimeException('Simulated failure with a value 123456789012 that must not reach the index');
    }
}

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    ['company' => $this->company, 'entity' => $this->entity, 'establishment' => $this->bengaluru] = complianceCompany();
    $this->pune = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'PUNE', 'name' => 'Pune', 'state' => 'MH', 'effective_from' => '2020-01-01']);
    $this->employee = statutoryEmployee(240000, $this->bengaluru, '100200300410', ['esic_number' => '3100112233', 'pan' => 'ABCPE1234K', 'aadhaar_reference' => '987654321098']);
    $this->employee->bankAccounts()->create(['account_holder_name' => 'A', 'bank_name' => 'Test Bank', 'account_number' => '001122334455', 'ifsc' => 'TEST0001234', 'is_primary' => true]);
    statutoryEmployee(240000, $this->pune, '100200300411');
    $this->hr = tenantUser($this->tenant, ['compliance.reports.view', 'compliance.reports.generate']);
    $this->reports = app(ComplianceReports::class);
    finalizedPayroll($this->company, 2026, 9, tenantUser($this->tenant, ['payroll.*', 'employee.*']), tenantUser($this->tenant, ['payroll.*', 'employee.*']));
    $this->actingAs($this->hr);
});

it('masks UAN, ESI IP number, PAN, Aadhaar and bank account by default, and shows them in full only with the sensitive permission', function () {
    $item = 'employee_register|establishment|'.$this->bengaluru->id;
    $masked = firstSheet($this->reports->single($this->company, ReportPeriod::month(2026, 9), $item, $this->hr)['content']);
    foreach (['100200300410', '3100112233', 'ABCPE1234K', '987654321098', '001122334455'] as $full) {
        expect(sheetHas($masked, $full))->toBeFalse("{$full} must be masked");
    }
    expect(sheetHas($masked, '••••0410'))->toBeTrue('UAN masked');
    expect(sheetHas($masked, '••••1098'))->toBeTrue('Aadhaar masked');
    expect(sheetHas($masked, '••••4455'))->toBeTrue('Account masked');

    expect(fn () => $this->reports->single($this->company, ReportPeriod::month(2026, 9), $item, $this->hr, unmasked: true))->toThrow(RuntimeException::class, 'unmasked');

    $officer = tenantUser($this->tenant, ['compliance.reports.view', 'compliance.reports.generate', 'compliance.sensitive.view']);
    $this->actingAs($officer);
    $full = firstSheet($this->reports->single($this->company, ReportPeriod::month(2026, 9), $item, $officer, unmasked: true)['content']);
    expect(sheetHas($full, '100200300410'))->toBeTrue()->and(sheetHas($full, '001122334455'))->toBeTrue()->and(sheetHas($full, '987654321098'))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'EXPORT')->whereJsonContains('metadata->unmasked', true)->exists())->toBeTrue();
});

it('refuses another user, another tenant and a requester whose access has changed, whatever identifier they guess', function () {
    $pack = $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    expect($pack->status)->toBe('completed');

    // Another user of the same tenant, even with the same permissions.
    $colleague = tenantUser($this->tenant, ['compliance.reports.view', 'compliance.reports.generate']);
    expect(fn () => $this->reports->download($pack, $colleague))->toThrow(RuntimeException::class, 'Only the person who generated');

    // Another tenant cannot even find the pack by its id.
    $other = provisionTenant('Other');
    actAsTenant($other);
    $intruder = tenantUser($other, ['*']);
    $this->actingAs($intruder);
    expect(ComplianceReportPack::query()->find($pack->id))->toBeNull()
        ->and(fn () => $this->reports->download($pack, $intruder))->toThrow(RuntimeException::class);

    // The requester after their access was narrowed to one establishment.
    actAsTenant($this->tenant);
    $this->actingAs($this->hr);
    expect($this->reports->canDownload($pack->refresh(), $this->hr))->toBeTrue();
    app(AccessScopes::class)->assign($this->hr, ['establishment' => [$this->pune->id]], 'Narrowed');
    app(AccessScopes::class)->forget();
    expect(fn () => $this->reports->download($pack->refresh(), $this->hr))->toThrow(RuntimeException::class, 'access has changed');

    // Without the permission there is no page and no generation.
    $viewer = tenantUser($this->tenant, ['payroll.view']);
    expect(fn () => $this->reports->request($this->company, ReportPeriod::month(2026, 9), $viewer))->toThrow(RuntimeException::class, 'permission');
});

it('withholds PoSH counts from anyone who is not a handler of the PoSH category', function () {
    $handlers = Role::factory()->create();
    $category = GrievanceCategory::query()->firstOrCreate(['code' => 'POSH'], ['name' => 'Sexual harassment (PoSH)', 'is_confidential' => true, 'allow_anonymous' => false, 'sla_days' => 90, 'status' => 'active']);
    $category->update(['handler_role_ids' => [$handlers->id]]);
    Grievance::query()->create(['number' => 'G-1', 'grievance_category_id' => $category->id, 'employee_id' => $this->employee->id, 'is_anonymous' => false, 'subject' => 'Confidential', 'details' => 'Confidential', 'severity' => 'high', 'status' => 'submitted']);
    $item = 'posh_annual_report|company|'.$this->company->id;

    $withheld = firstSheet($this->reports->single($this->company, ReportPeriod::fromKey('CY:2026'), $item, $this->hr)['content']);
    $received = collect($withheld)->first(fn ($r) => in_array('Complaints received during the year', $r, true));
    expect($received[1] ?? '')->toBe('')
        ->and(sheetHas($withheld, 'Data gap: Counts withheld: the person generating the report is not a handler of the PoSH category.'))->toBeTrue();

    $member = tenantUser($this->tenant, ['compliance.reports.view', 'compliance.reports.generate']);
    app(TenantContext::class)->runAs($this->tenant, fn () => $member->roles()->attach($handlers));
    $this->actingAs($member);
    $counted = firstSheet($this->reports->single($this->company, ReportPeriod::fromKey('CY:2026'), $item, $member)['content']);
    $row = collect($counted)->first(fn ($r) => in_array('Complaints received during the year', $r, true));
    expect((int) $row[1])->toBe(1)->and(sheetHas($counted, 'Confidential'))->toBeFalse();
});

it('records an authorised review by someone other than the requester, and audits it', function () {
    $pack = $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    expect(fn () => $this->reports->review($pack, $this->hr, 'Looks right'))->toThrow(RuntimeException::class);

    $reviewer = tenantUser($this->tenant, ['compliance.reports.view', 'compliance.reports.review']);
    expect(fn () => $this->reports->review($pack, $this->hr, 'Self review'))->toThrow(RuntimeException::class)
        ->and($this->reports->review($pack, $reviewer, 'Checked against the payroll register')->reviewed_by)->toBe($reviewer->id)
        ->and(fn () => $this->reports->review($pack->refresh(), $reviewer, 'Again'))->toThrow(RuntimeException::class, 'already been reviewed')
        ->and(AuditEvent::query()->where('action', 'REVIEWED')->where('entity_id', (string) $pack->id)->exists())->toBeTrue();
});

it('reports a failing report as failed with no workbook, keeps the index true to the ZIP, cleans up and retries in place', function () {
    config(['peopleos.compliance.report_builders.employee_register' => FailingComplianceBuilder::class]);
    $before = glob(sys_get_temp_dir().'/peopleos-compliance-pack-*') ?: [];

    $pack = $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    $failed = collect($pack->manifest)->where('key', 'employee_register');
    expect($pack->status)->toBe('completed')
        ->and($failed->pluck('status')->unique()->all())->toBe(['failed'])
        ->and($failed->pluck('readiness')->unique()->all())->toBe(['BLOCKED'])
        ->and($failed->pluck('file')->filter()->all())->toBe([])
        ->and($failed->first()['note'])->toContain('reference')->not->toContain('123456789012');

    // Every listed file is in the ZIP and nothing else is.
    $zipPath = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($zipPath, Storage::disk('local')->get($pack->path));
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $inZip = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->sort()->values()->all();
    $zip->close();
    unlink($zipPath);
    expect($inZip)->toEqual(collect($pack->manifest)->pluck('file')->filter()->push('00 Index.xlsx')->sort()->values()->all())
        ->and(glob(sys_get_temp_dir().'/peopleos-compliance-pack-*') ?: [])->toEqual($before);

    // A failed pack is retried in place: same record, same file path, one file.
    $pack->update(['status' => 'failed']);
    config(['peopleos.compliance.report_builders.employee_register' => EmployeeRegister::class]);
    $retried = $this->reports->retry($pack, $this->hr);
    expect($retried->id)->toBe($pack->id)->and($retried->status)->toBe('completed')->and($retried->path)->toBe($pack->path)
        ->and(Storage::disk('local')->files("tenants/{$this->tenant->id}/compliance-reports"))->toHaveCount(1)
        ->and(collect($retried->manifest)->where('key', 'employee_register')->pluck('status')->unique()->all())->not->toContain('failed');
});

it('keeps every ZIP entry inside its folder whatever the establishment is called', function () {
    $this->pune->update(['name' => '../../../etc/passwd', 'code' => '..\\..']);
    $pack = $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    foreach (collect($pack->manifest)->pluck('file')->filter() as $file) {
        expect($file)->not->toContain('..')->and($file)->not->toStartWith('/')
            ->and(explode('/', $file)[0])->toBeIn(['Establishments', 'States', 'Legal entities', 'Company']);
    }
});

it('returns the pack already queued for the same request instead of building a duplicate', function () {
    Queue::fake();
    $first = $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    $second = $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    expect($second->id)->toBe($first->id)->and($first->status)->toBe('queued')
        ->and(ComplianceReportPack::query()->count())->toBe(1);
});

it('refuses to build a pack whose requester lost the permission before the job ran', function () {
    Queue::fake();
    $pack = $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    app(TenantContext::class)->runAs($this->tenant, fn () => $this->hr->roles()->detach());
    $built = $this->reports->build($pack->fresh());
    expect($built->status)->toBe('failed')->and($built->error)->toContain('no longer holds permission')->and($built->path)->toBeNull();
});
