<?php

use App\Domain\Compliance\Services\TaxIncomeEntries;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\MyCompensation;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne setup parity PS-3: what employees see on My compensation (history, take-home, wage split, perquisites, insight). */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    payrollCompany();
    $this->employee = salariedEmployee(600000, ['compensation.self', 'payroll.payslip'], from: '2025-04-01');
    compensate($this->employee, 660000, '2026-04-01', ['CONV' => 1600], 'revision', 'Annual revision');
});

it('shows only what the organisation allows on My compensation', function () {
    app(TaxIncomeEntries::class)->recordPerquisite($this->employee, '2026-27', 'car', 'Company car', 48000, 0, $this->hr);
    $this->actingAs($this->employee->user);

    Livewire::test(MyCompensation::class)->assertOk()->assertSee('History and scheduled changes')->assertDontSee('Perquisites this year')->assertDontSee('+10%');

    foreach (['show_perquisites', 'show_insight'] as $key) {
        app(SettingsRepository::class)->set("compensation.self_service.{$key}", true, 'test');
    }
    app(SettingsRepository::class)->set('compensation.self_service.show_history', false, 'test');
    $page = Livewire::test(MyCompensation::class)->assertOk()->assertSee('Perquisites this year')->assertSee('Company car')->assertDontSee('History and scheduled changes');
    expect($page->instance()->getInsight())->toBe(['2026-04-01' => 10.0]);
});
