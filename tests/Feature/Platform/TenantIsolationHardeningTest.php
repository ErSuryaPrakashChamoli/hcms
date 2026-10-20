<?php

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Models\ReportSchedule;
use App\Domain\Analytics\Services\ReportSchedules;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Exceptions\DuplicatePersonException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\Scim;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\People\Services\PersonMatcher;
use App\Domain\Talent\Services\TalentReviews;
use App\Http\Middleware\EnforceSecurityPolicy;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Phase 14.7 tenant isolation and scope hardening: Livewire requests carry the tenant chain, user
 * lookups from form input stay in the tenant, duplicate-person and employee-code checks see the whole
 * tenant (with minimal disclosure outside the caller's scope), and SCIM never leaks another tenant's
 * login as a 500.
 */

beforeEach(function () {
    $this->tenant = provisionTenant('Alpha');
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id]);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id]);
    $this->other = provisionTenant('Beta');
    $this->foreignUser = tenantUser($this->other, ['*'], ['email' => 'taken@beta.test']);
    actAsTenant($this->tenant);
});

it('runs the tenant and security-policy middleware on Livewire update requests too', function () {
    $persistent = Livewire::getPersistentMiddleware();
    expect($persistent)->toContain(ResolveTenant::class)->toContain(EnforceSecurityPolicy::class);
});

it('refuses users of another tenant in talent review participants and service-desk / grievance pickers', function () {
    expect(fn () => app(TalentReviews::class)->create('Review', null, [$this->foreignUser->id], $this->hr))->toThrow(RuntimeException::class, 'users of this organisation');
    $session = app(TalentReviews::class)->create('Review', null, [tenantUser($this->tenant, ['talent.review'])->id], $this->hr);
    expect($session->participants)->toHaveCount(2);

    // Pickers resolve submitted ids inside the bound tenant only.
    foreach (['app/Filament/Support/ServiceDeskActions.php', 'app/Filament/Support/GrievanceActions.php', 'app/Filament/Resources/Tickets/TicketResource.php', 'app/Filament/Resources/TalentReviews/TalentReviewResource.php'] as $file) {
        expect(file_get_contents(base_path($file)))->not->toMatch('/User::query\(\)->(findOrFail|orderBy)/');
    }
    expect(User::forCurrentTenant()->find($this->foreignUser->id))->toBeNull();
});

it('never notifies another tenant\'s user from a report schedule', function () {
    Storage::fake('local');
    $this->travelTo('2026-10-03 06:00:00');
    $report = Report::create(['name' => 'People', 'dataset' => 'employees', 'definition' => ['fields' => ['employee_code']], 'owner_id' => $this->hr->id]);
    ReportSchedule::create(['report_id' => $report->id, 'frequency' => 'daily', 'time' => '07:00', 'recipient_user_ids' => [$this->foreignUser->id, $this->hr->id]]);
    $this->travelTo('2026-10-03 07:30:00');
    app(ReportSchedules::class)->runDue();

    expect($this->hr->notifications()->count())->toBe(1)
        ->and(NotificationDelivery::query()->withoutGlobalScopes()->where('user_id', $this->foreignUser->id)->count())->toBe(0);
});

it('detects duplicate people across the whole tenant and discloses out-of-scope matches minimally', function () {
    $hire = app(HireEmployeeAction::class);
    $existing = $hire->handle(['first_name' => 'Ravi', 'last_name' => 'Kumar', 'personal_email' => 'ravi@home.test'], ['joining_date' => '2025-01-01'], ['company_id' => $this->company->id, 'location_id' => $this->mumbai->id]);

    $delhiHr = tenantUser($this->tenant, ['*']);
    app(AccessScopes::class)->assign($delhiHr, ['location' => [$this->delhi->id]]);
    $this->actingAs($delhiHr);

    $candidates = app(PersonMatcher::class)->candidates(['first_name' => 'R', 'last_name' => 'K', 'personal_email' => 'RAVI@home.test']);
    expect($candidates)->toHaveCount(1)
        ->and($candidates[0])->toMatchArray(['definite' => true, 'outside_scope' => true, 'person_id' => null, 'employee_code' => null])
        ->and(json_encode($candidates))->not->toContain('Ravi')->not->toContain($existing->employee_code);

    // The scoped user cannot create the second Person, even with "allow duplicate".
    expect(fn () => $hire->handle(['first_name' => 'R', 'last_name' => 'K', 'personal_email' => 'ravi@home.test', 'allow_duplicate' => true], ['joining_date' => '2026-01-01'], ['company_id' => $this->company->id, 'location_id' => $this->delhi->id]))
        ->toThrow(DuplicatePersonException::class);

    // Employee codes skip numbers taken outside the caller's scope (no unique-key 500).
    $hired = $hire->handle(['first_name' => 'Neha', 'last_name' => 'S'], ['joining_date' => '2026-01-01'], ['company_id' => $this->company->id, 'location_id' => $this->delhi->id]);
    expect($hired->employee_code)->not->toBe($existing->employee_code)
        ->and(Employee::query()->withoutGlobalScope(AccessScope::class)->where('employee_code', $hired->employee_code)->count())->toBe(1);

    // An unscoped HR user still sees the full match.
    $this->actingAs($this->hr);
    expect(app(PersonMatcher::class)->candidates(['personal_email' => 'ravi@home.test'])[0])->toMatchArray(['employee_code' => $existing->employee_code, 'outside_scope' => false]);
});

it('answers a SCIM userName held by another tenant with 409, never a 500, and without naming the tenant', function () {
    $token = app(ApiKeys::class)->issue('SCIM', ['scim'])['plaintext'];
    $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/scim+json'];

    $this->postJson('/api/scim/v2/Users', ['schemas' => [Scim::SCHEMA_USER], 'userName' => 'taken@beta.test'], $headers)
        ->assertStatus(409)->assertJsonPath('detail', 'This userName is not available.')->assertDontSee('Beta');
    $mine = $this->postJson('/api/scim/v2/Users', ['schemas' => [Scim::SCHEMA_USER], 'userName' => 'new@alpha.test'], $headers)->assertCreated()->json('id');
    $this->putJson("/api/scim/v2/Users/{$mine}", ['userName' => 'taken@beta.test'], $headers)->assertStatus(409);
    $this->patchJson("/api/scim/v2/Users/{$mine}", ['Operations' => [['op' => 'replace', 'path' => 'userName', 'value' => 'taken@beta.test']]], $headers)->assertStatus(409);
    expect($this->foreignUser->refresh()->tenant_id)->toBe($this->other->id);
});
