<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Employment\Imports\CustomFieldValueImports;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Organisation\Models\Company;
use App\Domain\People\Actions\ChangeFamilyMemberAction;
use App\Domain\People\Actions\ChangeNominationsAction;
use App\Domain\People\Actions\ChangeQualificationAction;
use App\Domain\People\Imports\EmployeeSkillImports;
use App\Domain\People\Imports\ExperienceImports;
use App\Domain\People\Imports\FamilyMemberImports;
use App\Domain\People\Imports\NominationImports;
use App\Domain\People\Imports\QualificationImports;
use App\Domain\People\Models\PersonExperience;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Models\PersonNomination;
use App\Domain\People\Models\PersonQualification;
use App\Domain\People\Models\PersonSkill;
use App\Domain\People\Models\Qualification;
use App\Domain\People\Models\QualificationSpecialisation;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Models\EmployeeSkill;
use App\Domain\Skills\Services\SkillProfiles;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Di2Helpers.php';

/*
| Data import DI-2 (Workforce): family members (Children / Dependents / Family details), education, work experience,
| nominee details, employee skills and employee other details — each staged, checked and run through the People /
| Employment write path its Employee 360 screen uses (scope, audit, timeline).
*/

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Company::factory()->create(['code' => 'ACME']);
    $this->asha = di2Hire('EMP1', 'Asha');
    $this->ravi = di2Hire('EMP2', 'Ravi');
});

it('registers every DI-2 type on the Workforce uploads with the rights of its screen', function () {
    $types = ['family_members', 'qualifications', 'work_experience', 'nominations', 'employee_skills', 'custom_field_values', 'position_changes', 'employee_codes', 'employee_settings', 'shortcuts'];
    foreach ($types as $type) {
        expect(RowImportTypes::has($type))->toBeTrue($type)
            ->and(ImportTypes::module($type))->toBe('workforce')
            ->and(RowImportTypes::allowed($this->hr, $type))->toBeTrue($type)
            ->and(RowImportTypes::allowed(tenantUser($this->tenant, ['employee.import']), $type))->toBeFalse($type);
    }
    expect(RowImportTypes::allowed(tenantUser($this->tenant, ['employee.import', 'employee.update']), 'family_members'))->toBeTrue()
        ->and(RowImportTypes::allowed(tenantUser($this->tenant, ['employee.import', 'employee.update']), 'position_changes'))->toBeFalse()
        ->and(RowImportTypes::allowed(tenantUser($this->tenant, ['employee.import', 'employee.position']), 'position_changes'))->toBeTrue()
        // Rows only change existing employees (or pin pages): no "new or existing" question.
        ->and(ImportTypes::modes('position_changes'))->toBeNull()->and(ImportTypes::modes('employee_codes'))->toBeNull()
        ->and(ImportTypes::modes('employee_settings'))->toBeNull()->and(ImportTypes::modes('shortcuts'))->toBeNull()
        ->and(ImportTypes::modes('family_members'))->toBe(ImportCatalogue::ROW_MODES);
    foreach (['workforce.children', 'workforce.dependents', 'workforce.family', 'workforce.education', 'workforce.work_experience', 'workforce.nominees',
        'workforce.employee_skills', 'workforce.other_details', 'workforce.employee_advance', 'workforce.employee_code', 'workforce.employee_settings', 'workforce.shortcut'] as $key) {
        expect(ImportCatalogue::built(ImportCatalogue::find($key)))->toBeTrue($key);
    }
    // The Children sample file is a valid upload: relation "child" is read as son or daughter by the gender.
    $sample = ImportCatalogue::csv(ImportCatalogue::find('workforce.children'), true);
    $import = di2Stage(FamilyMemberImports::TYPE, $sample, 'both', ['defaults' => ['relation' => 'child']]);
    expect($import->error_count)->toBe(0)->and($import->create_count)->toBe(3);
});

it('adds and updates family members through the family action, children by gender, dependents by default', function () {
    $mother = app(ChangeFamilyMemberAction::class)->add($this->asha, ['name' => 'Lakshmi Rao', 'relation' => 'mother', 'gender' => 'female'], $this->hr);
    $csv = "Employee code,Relation,Name,Date of birth,Gender,Dependent,Occupation,Phone\n"
        ."EMP1,wife,Kiran Rao,1992-03-14,female,yes,Teacher,9876543210\n"
        ."emp1,Mother,lakshmi rao,1965-07-02,,,Homemaker,\n"
        ."EMP1,child,Arjun Rao,2018-11-20,M,,,\n"
        ."EMP1,child,Meera Rao,2020-01-05,,,,\n"
        ."EMP1,cousin,Raj Rao,,,,,\n"
        ."EMP1,spouse,Kiran Rao,,,,,\n"
        ."NOPE,spouse,Someone,2030-01-01,,maybe,,\n";
    $import = di2Stage(FamilyMemberImports::TYPE, $csv);

    expect(di2Actions($import))->toBe(['create', 'update', 'create', 'error', 'error', 'error', 'error'])
        ->and(di2Errors($import, 4))->toContain("Relation 'child' needs the gender")
        ->and(di2Errors($import, 5))->toContain("Relation 'cousin' is not in the list")
        ->and(di2Errors($import, 6))->toContain('The same family member is in row 1')
        ->and(di2Errors($import, 7))->toContain("Employee code 'NOPE' does not exist")->toContain('Dependent must be yes or no')
        ->and(di2Row($import, 2)->match['changes'])->toBe(['date_of_birth', 'occupation']);

    di2Run($import);
    $spouse = PersonFamilyMember::query()->where('person_id', $this->asha->person_id)->where('relation', 'spouse')->sole();
    expect($spouse->only(['name', 'gender', 'is_dependent', 'occupation', 'phone']))->toBe(['name' => 'Kiran Rao', 'gender' => 'female', 'is_dependent' => true, 'occupation' => 'Teacher', 'phone' => '9876543210'])
        ->and($mother->fresh()->date_of_birth->toDateString())->toBe('1965-07-02')->and($mother->fresh()->occupation)->toBe('Homemaker')
        ->and(PersonFamilyMember::query()->where('person_id', $this->asha->person_id)->where('name', 'Arjun Rao')->value('relation'))->toBe('son')
        ->and(di2Timeline($this->asha))->toContain('Family member added')->toContain('Family member updated')
        ->and(AuditEvent::query()->where('entity_type', PersonFamilyMember::class)->where('entity_id', (string) $spouse->id)->value('reason'))->toContain('Family members upload #');

    // Children: relation defaults to child; a child already on file is found under son / daughter without a gender.
    $children = di2Stage(FamilyMemberImports::TYPE, "Employee code,Name,In school\nEMP1,Arjun Rao,yes\nEMP2,Sita Rao,\n", 'both', ['defaults' => ['relation' => 'child']]);
    expect(di2Actions($children))->toBe(['update', 'error']);
    // Dependents: blank Dependent cells read as yes.
    $dependents = di2Stage(FamilyMemberImports::TYPE, "Employee code,Relation,Name\nEMP2,father,Mohan Rao\n", 'both', ['defaults' => ['is_dependent' => 'yes']]);
    di2Run($dependents);
    expect(PersonFamilyMember::query()->where('person_id', $this->ravi->person_id)->sole()->is_dependent)->toBeTrue();
});

it('keeps to the chosen intent and to the rights of the family action', function () {
    app(ChangeFamilyMemberAction::class)->add($this->asha, ['name' => 'Kiran Rao', 'relation' => 'spouse'], $this->hr);
    $csv = "Employee code,Relation,Name,Occupation\nEMP1,spouse,Kiran Rao,Doctor\nEMP2,spouse,Uma Rao,Engineer\n";

    expect(di2Actions(di2Stage(FamilyMemberImports::TYPE, $csv, 'new')))->toBe(['skip', 'create'])
        ->and(di2Actions(di2Stage(FamilyMemberImports::TYPE, $csv, 'update')))->toBe(['update', 'skip']);

    // Adding needs employee.create (the action's right), changing employee.update.
    $clerk = tenantUser($this->tenant, ['employee.import', 'employee.update', 'employee.view']);
    $this->actingAs($clerk);
    $import = di2Stage(FamilyMemberImports::TYPE, $csv, 'both', [], $clerk);
    expect(di2Actions($import))->toBe(['update', 'error'])->and(di2Errors($import, 2))->toContain('This needs employee.create');
    di2Run($import, $clerk);
    expect(PersonFamilyMember::query()->where('name', 'Kiran Rao')->value('occupation'))->toBe('Doctor')
        ->and(PersonFamilyMember::query()->where('name', 'Uma Rao')->exists())->toBeFalse();
});

it('loads education against the qualification masters through the qualification action', function () {
    $btech = Qualification::query()->create(['name' => 'B.Tech', 'level' => 'Graduate']);
    QualificationSpecialisation::query()->create(['qualification_id' => $btech->id, 'name' => 'Computer science']);
    $mba = app(ChangeQualificationAction::class)->add($this->ravi, ['qualification' => 'MBA', 'institution' => 'IIM'], $this->hr);
    $csv = "Employee code,Qualification,Specialisation,Institute,Year of starting,Year of passing,Grade or score,Mode of study,Highest qualification\n"
        ."EMP1,b.tech,computer science,IIT Bombay,2012,2016,8.6 CGPA,Full time,yes\n"
        ."EMP2,MBA,,IIM Ahmedabad,2018,2020,,distance,\n"
        ."EMP2,M.Tech,,,2022,2020,,,\n"
        ."EMP2,PhD,,,,,,evening,\n";
    $import = di2Stage(QualificationImports::TYPE, $csv);

    expect(di2Actions($import))->toBe(['create', 'update', 'error', 'error'])
        ->and(di2Errors($import, 3))->toContain('year of starting is after the year of passing')
        ->and(di2Errors($import, 4))->toContain("Mode of study 'evening' is not in the list");
    di2Run($import);

    $entry = PersonQualification::query()->where('person_id', $this->asha->person_id)->sole();
    expect($entry->qualification_id)->toBe($btech->id)->and($entry->qualification)->toBe('B.Tech')->and($entry->specialisation)->toBe('Computer science')
        ->and($entry->specialisation_id)->not->toBeNull()->and($entry->mode)->toBe('full_time')->and($entry->is_highest)->toBeTrue()
        ->and($mba->fresh()->institution)->toBe('IIM Ahmedabad')->and($mba->fresh()->mode)->toBe('distance')
        ->and(di2Timeline($this->asha))->toContain('Education added')->and(di2Timeline($this->ravi))->toContain('Education updated');

    // Existing only: the new entry is skipped; the same values again are skipped as on file.
    $again = di2Stage(QualificationImports::TYPE, "Employee code,Qualification,Institute\nEMP2,MBA,IIM Ahmedabad\nEMP2,B.Com,SRCC\n", 'update');
    expect(di2Actions($again))->toBe(['skip', 'skip'])->and(di2Errors($again, 1))->toBe('Already on file as given.');
});

it('loads work experience through the experience action and refuses it for a fresher', function () {
    $this->ravi->person->update(['is_fresher' => true]);
    $csv = "Employee code,Employer,Designation,From date,To date,Reason for leaving,Verified\n"
        ."EMP1,Infosys Ltd,Engineer,2016-07-01,2019-06-30,Better opportunity,no\n"
        ."EMP1,Wipro Ltd,,01/07/2019,2019-06-01,,\n"
        ."EMP2,TCS,,2015-01-01,,,\n"
        ."EMP1,Acme,,,,,\n";
    $import = di2Stage(ExperienceImports::TYPE, $csv);
    expect(di2Actions($import))->toBe(['create', 'error', 'error', 'error'])
        ->and(di2Errors($import, 2))->toContain('to date')
        ->and(di2Errors($import, 3))->toContain('marked as a fresher')
        ->and(di2Errors($import, 4))->toContain('From date is required');
    di2Run($import);
    $job = PersonExperience::query()->where('person_id', $this->asha->person_id)->sole();
    expect($job->employer)->toBe('Infosys Ltd')->and($job->to_date->toDateString())->toBe('2019-06-30')
        ->and(di2Timeline($this->asha))->toContain('Previous employment added');

    // The same employer and start date is the job on file: updated (existing), or skipped when only new rows are wanted.
    $csv = "Employee code,Employer,From date,Designation\nEMP1,infosys ltd,2016-07-01,Senior engineer\n";
    expect(di2Actions(di2Stage(ExperienceImports::TYPE, $csv, 'new')))->toBe(['skip']);
    di2Run(di2Stage(ExperienceImports::TYPE, $csv, 'update'));
    expect($job->fresh()->designation)->toBe('Senior engineer')->and($job->fresh()->reason_for_leaving)->toBe('Better opportunity');
});

it('saves the nominees of a benefit together, shares totalling 100, through the nominations action', function () {
    $kiran = app(ChangeFamilyMemberAction::class)->add($this->asha, ['name' => 'Kiran Rao', 'relation' => 'spouse', 'date_of_birth' => '1992-03-14'], $this->hr);
    $lakshmi = app(ChangeFamilyMemberAction::class)->add($this->asha, ['name' => 'Lakshmi Rao', 'relation' => 'mother', 'date_of_birth' => '1965-07-02'], $this->hr);
    app(ChangeFamilyMemberAction::class)->add($this->asha, ['name' => 'Arjun Rao', 'relation' => 'son', 'date_of_birth' => '2018-11-20'], $this->hr);
    $csv = "Employee code,Benefit,Nominee,Share %,Nominee address\n"
        ."EMP1,pf,Kiran Rao,60%,12 MG Road\n"
        ."EMP1,Provident fund,Lakshmi Rao,40,\n"
        ."EMP1,gratuity,Kiran Rao,50,\n"
        ."EMP1,gratuity,Lakshmi Rao,30,\n"
        ."EMP1,insurance,Arjun Rao,100,\n"
        ."EMP1,esi,Nobody,100,\n";
    $import = di2Stage(NominationImports::TYPE, $csv);
    expect(di2Actions($import))->toBe(['create', 'create', 'error', 'error', 'error', 'error'])
        ->and(di2Errors($import, 3))->toContain('total 80%')->and(di2Errors($import, 4))->toContain('total 80%')
        ->and(di2Errors($import, 5))->toContain('is a minor')
        ->and(di2Errors($import, 6))->toContain("No family member named 'Nobody'");

    $import = di2Run($import);
    expect($import->create_count)->toBe(2)->and($import->failure_count)->toBe(0)
        ->and(PersonNomination::query()->where('benefit', 'pf')->orderBy('id')->get()->map(fn ($n) => [$n->family_member_id, (float) $n->share, $n->address])->all())
        ->toBe([[$kiran->id, 60.0, '12 MG Road'], [$lakshmi->id, 40.0, null]])
        ->and(di2Row($import, 2)->result)->toContain('Saved with row 1')
        ->and(di2Timeline($this->asha))->toContain('Nominations for Provident fund saved');

    // A set on file is replaced as a whole (the check names who is removed); "new only" leaves it alone; the same set is skipped.
    $replace = "Employee code,Benefit,Nominee,Share %\nEMP1,pf,Kiran Rao,100\n";
    expect(di2Actions(di2Stage(NominationImports::TYPE, $replace, 'new')))->toBe(['skip']);
    $update = di2Stage(NominationImports::TYPE, $replace, 'update');
    expect(di2Actions($update))->toBe(['update'])->and(di2Errors($update, 1))->toContain('removes Lakshmi Rao');
    di2Run($update);
    expect(ChangeNominationsAction::totals($this->asha))->toBe(['pf' => 100.0])
        ->and(PersonNomination::query()->where('benefit', 'pf')->sole()->address)->toBe('12 MG Road')
        ->and(di2Actions(di2Stage(NominationImports::TYPE, $replace)))->toBe(['skip']);
});

it('loads profile skills through the profile skill action and imported levels into the skill history', function () {
    $php = Skill::factory()->create(['name' => 'PHP', 'code' => 'PHP']);
    $sql = Skill::factory()->create(['name' => 'SQL', 'code' => 'SQL']);
    $csv = "Employee code,Skill,Level,Years of experience,Assessed level,Target level\n"
        ."EMP1,php,Advanced,4,3,4\n"
        ."EMP1,SQL,Guru,,,\n"
        ."EMP2,Cobol,,,,\n"
        ."EMP2,sql,beginner,,9,\n";
    $import = di2Stage(EmployeeSkillImports::TYPE, $csv);
    expect(di2Actions($import))->toBe(['create', 'error', 'error', 'error'])
        ->and(di2Errors($import, 2))->toContain('Level must be one of')
        ->and(di2Errors($import, 3))->toContain("Skill 'Cobol' is not an active skill")
        ->and(di2Errors($import, 4))->toContain("Assessed level must be a level of SQL's scale");
    di2Run($import);

    expect(PersonSkill::query()->where('person_id', $this->asha->person_id)->sole()->only(['skill_id', 'proficiency', 'years_of_experience']))
        ->toBe(['skill_id' => $php->id, 'proficiency' => 'advanced', 'years_of_experience' => 4])
        ->and(di2Timeline($this->asha))->toContain('Profile skill added');
    $entry = EmployeeSkill::query()->where('employee_id', $this->asha->id)->sole();
    expect($entry->source)->toBe('imported')->and($entry->is_verified)->toBeFalse()->and((float) $entry->current_level)->toBe(3.0)->and((float) $entry->target_level)->toBe(4.0)
        ->and(collect(app(SkillProfiles::class)->gaps($this->asha))->pluck('skill')->all())->toBe(['PHP']);

    // The same levels again change nothing; a new level adds history; "new only" skips the skill on file.
    expect(di2Actions(di2Stage(EmployeeSkillImports::TYPE, "Employee code,Skill,Level,Assessed level,Target level\nEMP1,PHP,advanced,3,4\n")))->toBe(['skip'])
        ->and(di2Actions(di2Stage(EmployeeSkillImports::TYPE, "Employee code,Skill,Assessed level\nEMP1,PHP,4\n", 'new')))->toBe(['skip']);
    di2Run(di2Stage(EmployeeSkillImports::TYPE, "Employee code,Skill,Years of experience,Assessed level\nEMP1,PHP,5,4\n", 'update'));
    expect(EmployeeSkill::query()->where('employee_id', $this->asha->id)->where('status', 'current')->where('source', 'imported')->sole()->current_level)->toEqual('4.00')
        ->and(PersonSkill::query()->where('skill_id', $php->id)->value('years_of_experience'))->toBe(5);

    // A level for the skill history needs skills.manage; the profile part alone does not.
    $clerk = tenantUser($this->tenant, ['employee.import', 'employee.update', 'employee.create', 'employee.view']);
    $this->actingAs($clerk);
    $refused = di2Stage(EmployeeSkillImports::TYPE, "Employee code,Skill,Level,Assessed level\nEMP2,SQL,beginner,2\nEMP2,PHP,beginner,\n", 'both', [], $clerk);
    expect(di2Actions($refused))->toBe(['error', 'create'])->and(di2Errors($refused, 1))->toContain('needs skills.manage');
});

it('loads employee other details as each custom field\'s type through the custom field engine', function () {
    CustomField::query()->create(['entity' => 'employee', 'key' => 'tshirt', 'label' => 'T-shirt size', 'type' => 'dropdown', 'status' => 'active',
        'options' => [['value' => 'm', 'label' => 'Medium'], ['value' => 'l', 'label' => 'Large']]]);
    CustomField::query()->create(['entity' => 'employee', 'key' => 'blood_donor', 'label' => 'Blood donor', 'type' => 'boolean', 'status' => 'active']);
    CustomField::query()->create(['entity' => 'employee', 'key' => 'commute_km', 'label' => 'Commute (km)', 'type' => 'number', 'status' => 'active']);
    CustomField::query()->create(['entity' => 'employee', 'key' => 'languages', 'label' => 'Languages', 'type' => 'multiselect', 'status' => 'active', 'options' => ['Hindi', 'English', 'Tamil']]);
    $this->ravi->setCustomFields(['tshirt' => 'm']);
    $csv = "Employee code,Field,Value\n"
        ."EMP1,T-shirt size,large\n"
        ."EMP1,blood_donor,yes\n"
        ."EMP1,Commute (km),\"1,250\"\n"
        ."EMP1,languages,Hindi; English\n"
        ."EMP2,tshirt,Medium\n"
        ."EMP2,tshirt,Large\n"
        ."EMP2,Shoe size,9\n"
        ."EMP2,languages,French\n";
    $import = di2Stage(CustomFieldValueImports::TYPE, $csv);
    expect(di2Actions($import))->toBe(['create', 'create', 'create', 'create', 'skip', 'error', 'error', 'error'])
        ->and(di2Errors($import, 6))->toContain('The same field for the same employee is in row 5')
        ->and(di2Errors($import, 7))->toContain("Field 'Shoe size' is not an active employee field")
        ->and(di2Errors($import, 8))->toContain("'French' is not an option");
    di2Run($import);

    expect($this->asha->fresh()->customFields())->toBe(['tshirt' => 'l', 'blood_donor' => true, 'commute_km' => 1250.0, 'languages' => ['Hindi', 'English']]);

    // Existing only: a value not on file is skipped, a changed value replaced; a wrong option is refused.
    $update = di2Stage(CustomFieldValueImports::TYPE, "Employee code,Field,Value\nEMP2,tshirt,Large\nEMP2,blood_donor,no\nEMP1,tshirt,XS\n", 'update');
    expect(di2Actions($update))->toBe(['update', 'skip', 'error'])->and(di2Errors($update, 3))->toContain('T-shirt size');
    di2Run($update);
    expect($this->ravi->fresh()->customField('tshirt'))->toBe('l');
});
