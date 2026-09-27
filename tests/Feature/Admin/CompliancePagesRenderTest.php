<?php

use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Filament\Resources\ComplianceRules\ComplianceRuleResource;
use App\Filament\Resources\EpfReturns\EpfReturnResource;
use App\Filament\Resources\EpfReturns\Pages\ViewEpfReturn;
use App\Filament\Resources\EsiReturns\EsiReturnResource;
use App\Filament\Resources\EstablishmentAssignments\EstablishmentAssignmentResource;
use App\Filament\Resources\Establishments\EstablishmentResource;
use App\Filament\Resources\EstablishmentStatutoryProfiles\EstablishmentStatutoryProfileResource;
use App\Filament\Resources\LegalEntities\LegalEntityResource;
use App\Filament\Resources\LwfReturns\LwfReturnResource;
use App\Filament\Resources\ProfessionalTaxReturns\ProfessionalTaxReturnResource;
use App\Filament\Resources\RuleVerifications\RuleVerificationResource;
use App\Filament\Resources\StatutoryRegistrations\StatutoryRegistrationResource;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Compliance/ComplianceTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    ['company' => $this->company, 'establishment' => $this->establishment] = complianceCompany();
    statutoryEmployee(600000, $this->establishment, '100200300400');
    finalizedPayroll($this->company, 2026, 9, $this->admin, tenantUser($this->tenant, ['payroll.*', 'employee.*']));
    $this->return = app(EpfReturns::class)->generate($this->establishment, 2026, 9, $this->admin);
    actAsTenant(null);
});

it('renders the legal structure and compliance pages with masked identifiers', function () {
    $this->get(LegalEntityResource::getUrl('index'))->assertOk()->assertSee($this->company->name);
    $this->get(EstablishmentResource::getUrl('index'))->assertOk()->assertSee('principal establishment');
    $this->get(EstablishmentAssignmentResource::getUrl('index'))->assertOk();
    $this->get(StatutoryRegistrationResource::getUrl('index'))->assertOk()->assertSee('••••5000')->assertDontSee('KABNG0012345000');
    $this->get(EstablishmentStatutoryProfileResource::getUrl('index'))->assertOk();
    $this->get(ComplianceRuleResource::getUrl('index'))->assertOk()->assertSee('Employees Provident Fund')->assertSee('In review');
    $this->get(RuleVerificationResource::getUrl('index'))->assertOk();
    $this->get(EpfReturnResource::getUrl('index'))->assertOk()->assertSee('2026-09');
    $this->get(EsiReturnResource::getUrl('index'))->assertOk();
    $this->get(ProfessionalTaxReturnResource::getUrl('index'))->assertOk();
    $this->get(LwfReturnResource::getUrl('index'))->assertOk();
    $this->get(EpfReturnResource::getUrl('view', ['record' => $this->return]))->assertOk()->assertSee('ECR')->assertSee('Validate')->assertDontSee('100200300400');
});

it('validates an EPF return from its page', function () {
    actAsTenant($this->tenant);
    Livewire::test(ViewEpfReturn::class, ['record' => $this->return->id])->callAction('validateReturn')->assertNotified();
    expect($this->return->refresh()->status)->toBe('validated');
});
