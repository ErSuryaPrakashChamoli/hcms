<?php

use App\Domain\Analytics\Datasets\ExitsDataset;
use App\Domain\Analytics\Datasets\QualificationsDataset;
use App\Domain\Configuration\Models\OptionList;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\WorkforceReason;
use App\Domain\Exit\Services\Exits;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\People\Actions\ChangeFamilyMemberAction;
use App\Domain\People\Models\PersonQualification;
use App\Domain\People\Models\Qualification;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\CareerPathStep;
use App\Domain\Performance\Services\CareerPassport;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Skills\Models\SkillScale;
use App\Domain\Skills\Services\SkillScales;
use App\Domain\Skills\Support\SkillProficiencies;
use App\Filament\Resources\OptionLists\OptionListResource;
use App\Filament\Resources\Qualifications\QualificationResource;
use App\Filament\Resources\WorkforceReasons\WorkforceReasonResource;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/* HROne setup parity — Core HR → Employee masters (CA-3): qualifications, separation and blacklist reasons, option lists, skill proficiency. */

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
});

/** An active employee with a user account. */
function coreMastersEmployee(array $permissions = ['employee.self', 'exit.resign']): Employee
{
    $employee = employeeWithUser(null, $permissions);

    return forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2024-01-01']);
}

it('links education entries to the qualification masters, keeping the text, and reports the level', function () {
    $btech = Qualification::create(['name' => 'B.Tech', 'level' => 'graduate']);
    $cs = $btech->specialisations()->create(['name' => 'Computer science']);
    $employee = coreMastersEmployee();

    $picked = PersonQualification::create(['person_id' => $employee->person_id, 'qualification_id' => $btech->id, 'specialisation_id' => $cs->id, 'institution' => 'NIT']);
    $typed = PersonQualification::create(['person_id' => $employee->person_id, 'qualification' => 'B.Tech', 'specialisation' => 'Computer science']);
    $other = PersonQualification::create(['person_id' => $employee->person_id, 'qualification' => 'Diploma in art']);
    expect([$picked->qualification, $picked->specialisation])->toBe(['B.Tech', 'Computer science'])
        ->and([$typed->qualification_id, $typed->specialisation_id])->toBe([$btech->id, $cs->id])
        ->and($other->qualification_id)->toBeNull()
        ->and(fn () => PersonQualification::create(['person_id' => $employee->person_id]))->toThrow(RuntimeException::class, 'Name the qualification');

    $dataset = new QualificationsDataset;
    $rows = $dataset->query()->get();
    expect($rows->map(fn ($row) => $dataset->value('qualification_level', $row))->filter()->values()->all())->toBe(['Graduate', 'Graduate']);

    $this->get(QualificationResource::getUrl('index'))->assertOk()->assertSee('B.Tech');
    $this->actingAs(tenantUser($this->tenant, ['employee.self']))->get(QualificationResource::getUrl('index'))->assertForbidden();
});

it('offers separation reasons to employees and to HR as configured, with their remark rules, and reports their kind', function () {
    $better = WorkforceReason::create(['kind' => 'separation', 'name' => 'Better opportunity', 'separation_kind' => 'voluntary', 'for_employee' => true, 'for_approver' => false, 'remark_required' => true]);
    $misconduct = WorkforceReason::create(['kind' => 'separation', 'name' => 'Misconduct', 'separation_kind' => 'involuntary', 'for_employee' => false, 'for_approver' => true, 'approver_remark_required' => true]);
    expect(fn () => WorkforceReason::create(['kind' => 'transfer', 'name' => 'X', 'separation_kind' => 'voluntary']))->toThrow(RuntimeException::class, 'Only a separation reason');
    $exits = app(Exits::class);

    // HR: only reasons offered to approvers, with the approver's remark when the reason asks for it.
    $first = coreMastersEmployee();
    expect(fn () => $exits->initiate($first, 'termination', 'x', actor: $this->hr, extra: ['workforce_reason_id' => $better->id]))->toThrow(RuntimeException::class, 'offered to employees resigning')
        ->and(fn () => $exits->initiate($first, 'termination', null, actor: $this->hr, extra: ['workforce_reason_id' => $misconduct->id]))->toThrow(RuntimeException::class, 'needs a remark');
    $case = $exits->initiate($first, 'termination', 'Policy breach', actor: $this->hr, extra: ['workforce_reason_id' => $misconduct->id]);
    expect($case->workforce_reason_id)->toBe($misconduct->id);

    // Employees: a reason is required once the organisation offers them some, and only those offered to employees.
    $second = coreMastersEmployee();
    expect(fn () => $exits->resign($second, 'Leaving', null, $second->user))->toThrow(RuntimeException::class, 'Choose the reason for leaving')
        ->and(fn () => $exits->resign($second, 'Leaving', null, $second->user, separationReasonId: $misconduct->id))->toThrow(RuntimeException::class, 'not offered to employees');
    $resignation = $exits->resign($second, 'A larger role elsewhere', null, $second->user, separationReasonId: $better->id);
    expect($resignation->separationReason->is($better))->toBeTrue();

    $dataset = new ExitsDataset;
    $row = $dataset->query()->whereKey($resignation->id)->first();
    expect($dataset->value('separation_reason', $row))->toBe('Better opportunity')
        ->and($dataset->value('separation_kind', $row))->toBe('Voluntary')
        ->and($dataset->value('separation_kind', $dataset->query()->whereKey($case->id)->first()))->toBe('Involuntary');

    $this->get(WorkforceReasonResource::getUrl('index'))->assertOk()->assertSee('Misconduct');
});

it('names why an exited employee may not be rehired, and tells the recruitment system', function () {
    $fraud = WorkforceReason::create(['kind' => 'blacklist', 'name' => 'Fraud']);
    $exits = app(Exits::class);
    $employee = coreMastersEmployee();
    $employee->update(['external_reference' => 'OFFER-7']);

    expect(fn () => $exits->initiate($employee, 'termination', 'Audit finding', actor: $this->hr, extra: ['is_rehire_eligible' => false]))->toThrow(RuntimeException::class, 'may not be rehired')
        ->and(fn () => $exits->initiate($employee, 'termination', 'Audit finding', actor: $this->hr, extra: ['is_rehire_eligible' => false, 'rehire_block_reason_id' => WorkforceReason::create(['kind' => 'transfer', 'name' => 'Project'])->id]))->toThrow(RuntimeException::class, 'active blacklist reason');
    $case = $exits->initiate($employee, 'termination', 'Audit finding', actor: $this->hr, extra: ['is_rehire_eligible' => false, 'rehire_block_reason_id' => $fraud->id]);
    expect($case->rehireBlockReason->is($fraud))->toBeTrue();

    // A termination started elsewhere (no explicit choice) keeps working without a reason, as before.
    $other = coreMastersEmployee();
    expect($exits->initiate($other, 'termination', 'Probation not cleared', actor: $this->hr)->is_rehire_eligible)->toBeFalse();

    $key = app(ApiKeys::class)->issue('RMS', ['rms.read']);
    auth()->logout();
    actAsTenant(null);
    $this->withHeader('X-Api-Key', $key['plaintext'])->getJson('/api/v1/pre-employees/OFFER-7')->assertOk()
        ->assertJsonPath('data.rehire_eligible', false)->assertJsonPath('data.rehire_block_reason', 'Fraud');
});

it('lets the organisation add, rename and hide relationships while keeping built-in codes', function () {
    $lists = app(OptionLists::class);
    expect($lists->options('family_relations'))->toBe(config('peopleos.people.family_relations'));

    OptionList::create(['list_key' => 'family_relations', 'code' => 'guardian', 'label' => 'Guardian']);
    OptionList::create(['list_key' => 'family_relations', 'code' => 'spouse', 'label' => 'Spouse / partner']);
    $hidden = OptionList::create(['list_key' => 'family_relations', 'code' => 'other', 'label' => 'Other', 'status' => 'inactive']);
    $options = $lists->options('family_relations');
    expect($options)->toHaveKey('guardian')->not->toHaveKey('other')
        ->and($options['spouse'])->toBe('Spouse / partner')
        ->and($lists->label('family_relations', 'other'))->toBe('Other')
        ->and(fn () => $hidden->delete())->toThrow(RuntimeException::class, 'cannot be removed')
        ->and(fn () => OptionList::create(['list_key' => 'shoe_sizes', 'code' => 'ten', 'label' => '10']))->toThrow(RuntimeException::class, 'Unknown option list')
        ->and(fn () => OptionList::create(['list_key' => 'family_relations', 'code' => 'Big Brother', 'label' => 'x']))->toThrow(RuntimeException::class, 'lower-case');

    $employee = coreMastersEmployee();
    $member = app(ChangeFamilyMemberAction::class)->add($employee, ['name' => 'Asha', 'relation' => 'guardian'], $this->hr);
    expect($member->relation)->toBe('guardian')
        ->and(fn () => app(ChangeFamilyMemberAction::class)->add($employee, ['name' => 'Bo', 'relation' => 'neighbour'], $this->hr))->toThrow(ProfileChangeRefused::class);

    $this->get(OptionListResource::getUrl('index'))->assertOk()->assertSee('Guardian');
});

it('uses the chosen skill scale for profile and career path proficiencies, and the built-in list by default', function () {
    expect(SkillProficiencies::options())->toBe(config('peopleos.people.skill_proficiencies'))
        ->and(SkillProficiencies::default())->toBe('intermediate')
        ->and(SkillProficiencies::isExpert('advanced'))->toBeTrue()
        ->and(SkillProficiencies::isExpert('intermediate'))->toBeFalse();

    $scale = SkillScale::create(['code' => 'FIVE', 'name' => 'Five levels']);
    app(SkillScales::class)->publish($scale, [['value' => 1, 'label' => 'Aware'], ['value' => 2, 'label' => 'Working'], ['value' => 3, 'label' => 'Practised'], ['value' => 4, 'label' => 'Advanced'], ['value' => 5, 'label' => 'Master']]);
    app(SettingsRepository::class)->set('skills.profile_scale', $scale->id, 'Our scale');

    expect(SkillProficiencies::options())->toBe(['1' => 'Aware', '2' => 'Working', '3' => 'Practised', '4' => 'Advanced', '5' => 'Master'])
        ->and(SkillProficiencies::default())->toBe('1')
        ->and(SkillProficiencies::isExpert('3'))->toBeTrue()
        ->and(SkillProficiencies::isExpert('2'))->toBeFalse();

    $go = Skill::create(['name' => 'Go', 'code' => 'GO']);
    $step = new CareerPathStep(['required_skills' => [['skill_id' => $go->id, 'proficiency' => '3']]]);
    expect(app(CareerPassport::class)->gaps($step, [['skill_id' => $go->id, 'proficiency' => '4']])[0]['met'])->toBeTrue()
        ->and(app(CareerPassport::class)->gaps($step, [['skill_id' => $go->id, 'proficiency' => '2']])[0]['met'])->toBeFalse();
});
