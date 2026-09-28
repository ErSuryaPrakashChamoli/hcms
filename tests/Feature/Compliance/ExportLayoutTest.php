<?php

use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Compliance\Services\ExportLayouts;
use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';

/* Phase 6.2: versioned export layouts, maker-checker verification, structural validation, portal validation. */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->layouts = app(ExportLayouts::class);
    $this->maker = platformAdmin();
    $this->checker = platformAdmin();
    $this->ecr = StatutoryExportLayout::query()->where('code', 'EPF_ECR')->sole();
    $this->submitLayout = fn (StatutoryExportLayout $l) => $this->layouts->submit($l, $this->maker, 'https://www.epfindia.gov.in/help/ecr-format.pdf', 'ECR file format (Help File)', '2026-09-28', '%PDF ECR help file', 'ecr-help.pdf');
});

it('publishes every layout as an immutable DRAFT version', function () {
    expect(StatutoryExportLayout::query()->pluck('status', 'code')->all())->toBe(['EPF_ECR' => 'draft', 'ESI_MC' => 'draft', 'PT_RETURN' => 'draft', 'LWF_RETURN' => 'draft', 'TDS_FORM_138' => 'draft'])
        ->and($this->ecr->checksumIntact())->toBeTrue();
    expect(fn () => $this->ecr->update(['specification' => ['type' => 'delimited']]))->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => $this->ecr->delete())->toThrow(RuntimeException::class, 'never deleted');

    DB::table('statutory_export_layouts')->where('id', $this->ecr->id)->update(['checksum' => str_repeat('0', 64)]);
    expect(fn () => app(TenantContext::class)->bypass(fn () => $this->layouts->sync()))->toThrow(RuntimeException::class, 'Publish it as version 2');
});

it('verifies a layout only on official evidence, by someone other than the submitter, superseding older verified versions', function () {
    expect(fn () => $this->layouts->submit($this->ecr, $this->maker, 'https://www.payroll-vendor.com/ecr', 'Vendor guide', '2026-09-28', 'x', 'x.pdf'))->toThrow(RuntimeException::class, 'not an official source');
    expect(fn () => $this->layouts->submit($this->ecr, tenantUser(provisionTenant(), ['*']), 'https://www.epfindia.gov.in/x', 't', '2026-09-28', 'x', 'x.pdf'))->toThrow(RuntimeException::class, 'platform administrators');

    ($this->submitLayout)($this->ecr);
    expect($this->ecr->refresh()->status)->toBe('review')->and(Storage::disk('local')->exists($this->ecr->evidence_path))->toBeTrue();
    expect(fn () => $this->layouts->verify($this->ecr, $this->maker, 'self'))->toThrow(RuntimeException::class, 'cannot verify');
    $this->layouts->verify($this->ecr, $this->checker, 'Matches the Help File field by field');
    expect($this->ecr->refresh()->isVerified())->toBeTrue();
    expect(fn () => $this->ecr->update(['evidence_sha256' => 'x']))->toThrow(RuntimeException::class, 'cannot be changed');

    $v2 = $this->layouts->publishVersion($this->ecr, $this->ecr->specification + ['notes' => 'x'], 'Portal added a column', $this->maker);
    expect($v2->version)->toBe(2)->and($v2->status)->toBe('draft')
        ->and($this->layouts->current('EPF_ECR')->id)->toBe($v2->id);
    ($this->submitLayout)($v2);
    $this->layouts->verify($v2, $this->checker, 'ok');
    expect($this->ecr->refresh()->status)->toBe('superseded')->and($this->ecr->superseded_by_id)->toBe($v2->id);
});

it('validates file structure: header, field count, required fields, formats, line endings', function () {
    $pt = StatutoryExportLayout::query()->where('code', 'PT_RETURN')->sole();
    $header = ExportLayouts::csvRow($pt->fieldNames());

    expect($this->layouts->validate($pt, $header."\n".ExportLayouts::csvRow(['A', 'MH', '28560.00', '200.00'])."\n"))->toBe([]);
    expect($this->layouts->validate($pt, ExportLayouts::csvRow(['X', 'State', 'Gross', 'PT'])."\n"))->toContain('Header row does not match the layout field names and order.');
    expect(implode(' ', $this->layouts->validate($pt, $header."\n".ExportLayouts::csvRow(['A', 'MH', '28560.00'])."\n")))->toContain('3 field(s), layout expects 4');
    expect(implode(' ', $this->layouts->validate($pt, $header."\n".ExportLayouts::csvRow(['', 'Maharashtra', '28,560', '200'])."\n")))
        ->toContain('Employee is required')->toContain('State value does not match')->toContain('Gross salary value does not match');
    expect($this->layouts->validate($pt, $header."\r\n"))->toContain('File contains carriage returns; the layout uses LF line endings.');
});

it('records the layout version on each return, blocks structurally invalid exports and marks unverified layouts', function () {
    $tenant = provisionTenant();
    actAsTenant($tenant);
    $this->actingAs(tenantUser($tenant, ['*']));
    ['company' => $company, 'establishment' => $establishment] = complianceCompany();
    $good = statutoryEmployee(600000, $establishment, '100200300400');
    $bad = statutoryEmployee(300000, $establishment, '100200300401');
    finalizedPayroll($company, 2026, 9, tenantUser($tenant, ['payroll.*', 'employee.*']), tenantUser($tenant, ['payroll.*', 'employee.*']));
    ['generator' => $generator, 'approver' => $approver, 'filer' => $filer] = complianceUsers($tenant);
    $returns = app(StatutoryReturns::class);

    $return = $returns->approve($returns->validate(app(EpfReturns::class)->generate($establishment, 2026, 9, $generator), $generator), $approver);
    expect($return->export_layout_id)->toBe($this->ecr->id)->and($return->format_version)->toBe('v1');

    // Simulate a line that would not satisfy the layout (a UAN overwritten outside the lifecycle).
    DB::table('epf_return_entries')->where('employee_id', $bad->id)->update(['uan' => encrypt('12345', false)]);
    expect(fn () => $returns->export($return, $generator))->toThrow(RuntimeException::class, 'failed structural validation');
    expect($return->refresh()->status)->toBe('approved')
        ->and($return->local_validation['valid'])->toBeFalse()
        ->and(implode(' ', $return->local_validation['issues']))->toContain('UAN value does not match');

    DB::table('epf_return_entries')->where('employee_id', $bad->id)->update(['uan' => encrypt('100200300401', false)]);
    $return = $returns->export($return, $generator);
    expect($return->status)->toBe('exported')
        ->and($return->local_validation['valid'])->toBeTrue()
        ->and($return->export_filename)->toStartWith('UNVERIFIED-FORMAT_');

    // A later layout version never changes the layout a return was exported with.
    app(TenantContext::class)->bypass(fn () => $this->layouts->publishVersion($this->ecr, $this->ecr->specification, 'New portal format', $this->maker));
    expect($return->refresh()->exportLayout->version)->toBe(1);

    // Portal validation is a human-entered fact, separate from filing.
    expect(fn () => $returns->recordPortalValidation($return, $approver, 'accepted', 'VAL-1', now()))->toThrow(RuntimeException::class, 'permission');
    expect(fn () => $returns->recordPortalValidation($return, $filer, 'accepted', ' ', now()))->toThrow(RuntimeException::class, 'reference');
    $return = $returns->recordPortalValidation($return, $filer, 'accepted', 'ECR-VALIDATION-778', now()->subHour());
    expect($return->status)->toBe('exported')
        ->and($return->portal_validation_result)->toBe('accepted')
        ->and($return->submitted_at)->toBeNull()
        ->and($return->actions()->where('action', 'portal_validated')->exists())->toBeTrue();
});
