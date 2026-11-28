<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Letters\Support\DocxLetter;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Payroll\Services\PayslipDesigns;
use App\Domain\Payroll\Services\PayslipPdf;
use App\Domain\Payroll\Services\PayslipPublishing;
use App\Domain\Payroll\Support\PayslipWordTemplate;
use App\Domain\Platform\Models\TenantSetting;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\PayslipDesignsPage;
use App\Http\Controllers\PayslipPdfController;
use App\Http\Controllers\PayslipPrintController;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

/*
 | Owner, 2026-11-27: payslip designs — five built-in layouts the organisation picks from (or per salary structure), and
 | its own Word layout. A design only lays out the payslip's snapshot; the figures never change.
 */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->payroll = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->checker = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->payroll);
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000, ['payroll.payslip', 'employee.self']);
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'Union Bank', 'account_number' => '1234567890', 'ifsc' => 'BANK0000001', 'account_type' => 'savings', 'is_primary' => true]);
    $runs = app(PayrollRuns::class);
    $this->run = $runs->finalize($runs->approve($runs->validate($runs->calculate($runs->open($this->company, 2026, 9))), $this->checker), $this->checker);
    $this->payslip = Payslip::query()->where('employee_id', $this->employee->id)->sole();
    $this->designs = app(PayslipDesigns::class);
});

it('prints a payslip in each of the five designs, with the payslip\'s own figures', function () {
    $net = PayslipDesigns::money((float) $this->payslip->get('totals.net'));
    expect($this->payslip->get('structure'))->toBe('STANDARD');

    foreach (array_keys(PayslipDesigns::DESIGNS) as $design) {
        $html = $this->designs->html($this->payslip, $design);
        expect($html)->toContain('data-payslip-design="'.$design.'"')
            ->toContain(e($this->payslip->get('company.name')))
            ->toContain(e($this->payslip->get('employee.name')))
            ->toContain(e($net))
            ->toContain('September 2026')
            ->toContain('Basic');
    }
    // Detailed always has the year to date and the employer contributions; Classic only when asked.
    expect($this->designs->html($this->payslip, 'detailed'))->toContain('Year to date')->toContain('Employer contributions')
        ->and($this->designs->html($this->payslip, 'classic'))->not->toContain('Year to date');
});

it('follows the organisation\'s choice, a salary structure\'s own design and the options', function () {
    expect($this->designs->designFor($this->payslip))->toBe('classic');

    $this->designs->save(['layout' => 'modern', 'accent' => 'teal', 'title' => 'Salary slip', 'footer' => 'Questions? Write to payroll.', 'show_bank' => false], $this->payroll);
    $html = $this->designs->html($this->payslip);
    expect($this->designs->designFor($this->payslip))->toBe('modern')
        ->and($html)->toContain('Salary slip')->toContain('Questions? Write to payroll.')->toContain(PayslipDesigns::ACCENTS['teal'][1])
        ->not->toContain('Union Bank');

    $this->designs->save(['by_structure' => [['structure' => 'STANDARD', 'design' => 'compact']]], $this->payroll);
    expect($this->designs->designFor($this->payslip))->toBe('compact')
        ->and(AuditEvent::query()->where('entity_type', TenantSetting::class)->exists())->toBeTrue();

    expect(fn () => $this->designs->save(['layout' => 'custom'], $this->payroll))->toThrow(RuntimeException::class, 'Upload your Word payslip layout')
        ->and(fn () => $this->designs->save(['layout' => 'fancy'], $this->payroll))->toThrow(RuntimeException::class, 'Choose one of the payslip designs');
});

it('opens a payslip for printing only through a signed link, for payroll and for the employee once published', function () {
    $url = PayslipPrintController::url($this->payslip);
    $this->get($url)->assertOk()->assertSee('Print or save as PDF')->assertSee('data-payslip-design="classic"', false);
    expect(AuditEvent::query()->where('action', 'PAYSLIP_ACCESSED')->where('entity_id', (string) $this->payslip->id)->exists())->toBeTrue();
    $this->get(route('payslips.print', ['payslip' => $this->payslip->id]))->assertForbidden();

    // The employee: not before the payslips are published, then their own only.
    $this->actingAs($this->employee->user);
    $this->get($url)->assertNotFound();
    app(PayslipPublishing::class)->publish($this->run->refresh(), $this->checker);
    $this->get($url)->assertOk();

    $colleague = salariedEmployee(500000, ['payroll.payslip', 'employee.self']);
    $this->actingAs($colleague->user)->get($url)->assertNotFound();
});

it('fills the organisation\'s own Word layout and downloads it as the payslip', function () {
    $path = tempnam(sys_get_temp_dir(), 'ps');
    file_put_contents($path, PayslipWordTemplate::sample());
    expect(DocxLetter::problem($path))->toBeNull();

    $custom = $this->designs->storeWord($path, 'Our payslip.docx', $this->payroll);
    $this->designs->save(['custom' => $custom, 'layout' => PayslipDesigns::CUSTOM], $this->payroll);
    $file = $this->designs->word($this->payslip);
    $filled = tempnam(sys_get_temp_dir(), 'ps');
    file_put_contents($filled, $file['content']);
    $text = DocxLetter::text($filled);
    expect($text)->toContain($this->payslip->get('employee.name'))->toContain('September 2026')->toContain('Year to date')->toContain('Basic')
        ->not->toContain('{{');

    $this->get(PayslipPrintController::url($this->payslip))->assertOk()->assertHeader('Content-Type', $file['mime']);

    // A file with variables a payslip does not have (a letter's) is refused, with the variables named.
    $bad = tempnam(sys_get_temp_dir(), 'ps');
    file_put_contents($bad, DocxLetter::sample());
    expect(fn () => $this->designs->storeWord($bad, 'x.docx', $this->payroll))->toThrow(RuntimeException::class, '{{ letter.number }}');
    @unlink($path);
    @unlink($filled);
    @unlink($bad);
});

it('chooses and saves a design on Payroll → Payslip designs, with live previews', function () {
    $this->get(PayslipDesignsPage::getUrl())->assertOk()->assertSee('Payslip designs')->assertSee('data-design-card="statement"', false);

    Livewire::test(PayslipDesignsPage::class)
        ->assertSet('data.layout', 'classic')
        ->set('data.layout', 'statement')
        ->assertSee('data-design-preview="statement"', false)
        ->set('data.accent', 'maroon')
        ->call('save');

    expect($this->designs->settings())->toMatchArray(['layout' => 'statement', 'accent' => 'maroon']);

    $this->actingAs($this->employee->user)->get(PayslipDesignsPage::getUrl())->assertForbidden();
});

it('downloads the payslip as a PDF locked with the first four letters of the name and the last four characters of the PAN', function () {
    $sample = new Payslip(['number' => 'PS-1', 'snapshot' => ['employee' => ['name' => 'Rahul Sharma', 'pan' => '••••••234F', 'code' => 'EMP0042']]]);
    $noPan = new Payslip(['number' => 'PS-2', 'snapshot' => ['employee' => ['name' => 'Om Prakash', 'pan' => null, 'code' => 'EMP0042']]]);
    expect(PayslipPdf::password($sample))->toBe('RAHU234F')->and(PayslipPdf::password($noPan))->toBe('OMPR0042');

    $response = $this->get(PayslipPdfController::url($this->payslip))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $bytes = $response->getContent();
    expect($bytes)->toStartWith('%PDF')->toContain('/Encrypt')
        ->and(AuditEvent::query()->where('action', 'PAYSLIP_ACCESSED')->where('metadata->part', 'pdf')->exists())->toBeTrue();

    // Switched off: the same PDF without a password.
    app(SettingsRepository::class)->set('payroll.payslip.pdf_password', false);
    $open = app(PayslipPdf::class)->render($this->payslip);
    expect($open['content'])->toStartWith('%PDF')->not->toContain('/Encrypt')->and($open['protected'])->toBeFalse();
});

it('gives every employee the design payroll chose: employees never choose it', function () {
    $this->designs->save(['layout' => 'statement'], $this->payroll);
    app(PayslipPublishing::class)->publish($this->run->refresh(), $this->checker);

    $this->actingAs($this->employee->user)->get(PayslipPrintController::url($this->payslip))->assertOk()->assertSee('data-payslip-design="statement"', false);
    $this->get(PayslipDesignsPage::getUrl())->assertForbidden();
    expect(PayslipDesignsPage::canAccess())->toBeFalse();
});
