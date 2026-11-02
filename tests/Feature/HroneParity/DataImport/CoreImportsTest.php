<?php

use App\Domain\Career\Models\RoleRequirementVersion;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Organisation\Enums\GeoPlaceKind;
use App\Domain\Organisation\Models\BusinessRegion;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\DimensionValue;
use App\Domain\Organisation\Models\GeoPlace;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Region;
use App\Domain\People\Models\Skill;
use App\Domain\Platform\Services\FeatureFlags;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\Locations\Pages\ListLocations;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use Livewire\Livewire;

require_once __DIR__.'/Di1Helpers.php';

/* Data import DI-1 — Core: branches, sub branches, regions, business regions, business units, departments, sub
   departments, designations, grades, levels, skills, designation wise skills, states, districts, cities, dimension. */

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->acme = di1Company();
});

const DI1_TYPES = [
    'locations' => 'core', 'sub_branches' => 'core', 'regions' => 'core', 'business_regions' => 'core', 'business_units' => 'core', 'departments' => 'core',
    'sub_departments' => 'core', 'designations' => 'core', 'grades' => 'core', 'levels' => 'core', 'skills' => 'core', 'designation_skills' => 'core',
    'geo_states' => 'core', 'geo_districts' => 'core', 'geo_cities' => 'core', 'dimensions' => 'core', 'customers' => 'core', 'projects' => 'projects', 'jv_mappings' => 'integration',
];

it('registers every DI-1 upload under its head with a format in field order and example rows', function () {
    foreach (DI1_TYPES as $type => $area) {
        expect(RowImportTypes::has($type))->toBeTrue($type)->and(RowImportTypes::TYPES[$type][3])->toBe($area, $type);
        $service = ImportTypes::service($type);
        $labels = array_column($service::fields(), 0);
        $format = $service->format();
        expect(array_column($format['columns'], 0))->toBe($labels, "{$type}: format columns in field order")
            ->and(collect($format['columns'])->every(fn (array $c) => count($c) === 4 && in_array($c[1], ['Yes', 'No'], true)))->toBeTrue($type)
            ->and(collect($format['columns'])->pluck(1)->all())->toBe(array_map(fn (array $f) => $f[1] ? 'Yes' : 'No', array_values($service::fields())), "{$type}: required flags")
            ->and($format['notes'])->not->toBeEmpty();
        $records = iterator_to_array(Reader::fromString($service->template())->getRecords(), false);
        expect($records[0])->toBe($labels)->and(count($records))->toBeGreaterThanOrEqual(3, "{$type}: two example rows");
        foreach (array_slice($records, 1) as $example) {
            expect($example)->toHaveCount(count($labels));
        }
    }
    foreach (ImportCatalogue::PROCESSES as $key => $process) {
        if (in_array($process['head'], ['core', 'projects', 'integration'], true)) {
            expect(ImportCatalogue::built($process))->toBeTrue("{$key} is built")
                ->and(ImportTypes::module($process['type']))->toBe($process['head'], $key);
        }
    }
});

it('runs every Core, Project Management and Integration process from its own sample file, without an error', function () {
    $second = di1Company('ACME-IN', 'Acme India');
    di1Hire($this->acme, 'EMP1');
    di1Hire($this->acme, 'EMP2', 'Ravi');
    $order = ['core.regions', 'core.business_regions', 'core.levels', 'core.grades', 'core.branches', 'core.sub_branches', 'core.business_units', 'core.departments',
        'core.sub_departments', 'core.designations', 'core.skills', 'core.designation_skills', 'core.states', 'core.districts', 'core.cities', 'core.dimensions',
        'core.customers', 'projects.projects'];
    foreach (ImportCatalogue::PROCESSES as $key => $process) {
        if ($process['head'] === 'integration') {
            $order[] = $key;
        }
    }
    expect(count($order))->toBe(collect(ImportCatalogue::PROCESSES)->filter(fn ($p) => in_array($p['head'], ['core', 'projects', 'integration'], true))->count());

    foreach ($order as $key) {
        $import = di1StageSample($key);
        // Tenants start with levels L1–L6, so the levels sample updates them; every other sample adds records.
        expect($import->error_count)->toBe(0, "{$key}: ".json_encode(collect(di1Rows($import))->pluck('errors')->filter()->values()))
            ->and($import->create_count + $import->update_count)->toBe(2, $key);
        $done = di1Run($import);
        expect($done->status)->toBe('imported', $key)->and($done->failure_count)->toBe(0, "{$key}: ".json_encode(collect(di1Rows($done))->pluck('result')));
    }

    expect(Location::query()->where('code', 'BLR-WF')->first()->parentLocation->code)->toBe('BLR')
        ->and(GeoPlace::query()->where('kind', 'city')->where('code', 'BLR')->first()->district->code)->toBe('BLR-U')
        ->and(DimensionValue::query()->where('code', 'BFSI')->first()->parent->code)->toBe('FIN-VERT')
        ->and($second->refresh()->code)->toBe('ACME-IN');
});

it('adds and updates branches with company, region, business region, state code and time zone, and never moves a sub branch', function () {
    $south = Region::query()->create(['code' => 'SOUTH', 'name' => 'South', 'status' => 'active']);
    $apac = BusinessRegion::query()->create(['code' => 'APAC', 'name' => 'Asia Pacific', 'status' => 'active']);
    $hq = Location::factory()->create(['company_id' => $this->acme->id, 'code' => 'HQ', 'name' => 'Head office']);
    $annexe = Location::factory()->create(['company_id' => $this->acme->id, 'code' => 'HQ-ANX', 'name' => 'Annexe', 'parent_location_id' => $hq->id]);
    $csv = "Code,Name,Company code,Type,City,State code,Country code,Time zone,Region code,Business region code\n"
        ."BLR,Bengaluru office,acme,Branch,Bengaluru,Karnataka,IN,Asia/Kolkata,south,APAC\n"
        ."HQ-ANX,Annexe (renamed),ACME,,,,,,SOUTH,\n"
        ."BAD,Bad branch,NOPE,castle,,,,Mars/Base,,\n"
        ."blr,Duplicate,ACME,,,,,,,\n";

    $import = di1Stage('locations', $csv);
    $rows = di1Rows($import);
    expect($import->create_count)->toBe(1)->and($import->update_count)->toBe(1)->and($import->error_count)->toBe(2)
        ->and($rows[3]['errors'])->toContain("Company code 'NOPE' does not exist.")
        ->and(implode(' ', $rows[3]['errors']))->toContain("Type 'castle' is not one of")->toContain("Time zone 'Mars/Base'")
        ->and($rows[4]['errors'])->toBe(['The same code is in row 1.']);

    di1Run($import);
    $blr = Location::query()->where('code', 'BLR')->first();
    expect($blr->company_id)->toBe($this->acme->id)->and($blr->type->value)->toBe('branch')->and($blr->state_code)->toBe('KA')
        ->and($blr->timezone)->toBe('Asia/Kolkata')->and($blr->region_id)->toBe($south->id)->and($blr->business_region_id)->toBe($apac->id)
        ->and($blr->status->value)->toBe('active')->and($blr->effective_from->toDateString())->toBe('2026-09-25')
        ->and($annexe->refresh()->name)->toBe('Annexe (renamed)')->and($annexe->region_id)->toBe($south->id)->and($annexe->parent_location_id)->toBe($hq->id);

    // Update mode: a new code is skipped, a code on file is changed; a sub branch cannot change company away from its branch.
    $other = di1Company('BETA', 'Beta');
    $again = di1Stage('locations', "Code,Name,Company code\nNEW1,New branch,ACME\nBLR,Bengaluru (city),ACME\nHQ-ANX,Annexe,BETA\n", 'update');
    $rows = di1Rows($again);
    expect($rows[1]['action'])->toBe('skip')->and($rows[1]['match']['reason'])->toContain('only updates')
        ->and($rows[2]['action'])->toBe('update')
        ->and($rows[3]['errors'][0])->toContain('same company');
    di1Run($again);
    expect(Location::query()->where('code', 'NEW1')->exists())->toBeFalse()->and($blr->refresh()->name)->toBe('Bengaluru (city)')
        ->and($annexe->refresh()->company_id)->toBe($this->acme->id)->and($other->id)->not->toBeNull();
});

it('puts sub branches inside a branch of their company, inherits the branch details and refuses loops', function () {
    $south = Region::query()->create(['code' => 'SOUTH', 'name' => 'South', 'status' => 'active']);
    $blr = Location::factory()->create(['company_id' => $this->acme->id, 'code' => 'BLR', 'name' => 'Bengaluru', 'timezone' => 'Asia/Kolkata', 'region_id' => $south->id]);
    $beta = di1Company('BETA', 'Beta');
    $pune = Location::factory()->create(['company_id' => $beta->id, 'code' => 'PUN', 'name' => 'Pune']);
    $csv = "Code,Name,Parent branch code\nBLR-WF,Whitefield,BLR\nBLR-WF2,Whitefield 2,BLR-WF\nPUN,Pune,BLR\nSELF,Self,self\nX1,Nowhere,NOPE\n";

    $import = di1Stage('sub_branches', $csv);
    $rows = di1Rows($import);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(3)
        ->and($rows[3]['errors'][0])->toContain('different companies')
        ->and($rows[4]['errors'])->toContain('A sub branch cannot sit inside itself.')
        ->and($rows[5]['errors'][0])->toContain("Parent branch code 'NOPE' does not exist");

    di1Run($import);
    $wf = Location::query()->where('code', 'BLR-WF')->first();
    $wf2 = Location::query()->where('code', 'BLR-WF2')->first();
    expect($wf->parent_location_id)->toBe($blr->id)->and($wf->company_id)->toBe($this->acme->id)->and($wf->timezone)->toBe('Asia/Kolkata')
        ->and($wf->region_id)->toBe($south->id)->and($wf2->parent_location_id)->toBe($wf->id)->and($pune->refresh()->parent_location_id)->toBeNull();

    // A branch cannot go under its own sub branch; "new only" skips a location on file.
    $loop = di1Stage('sub_branches', "Code,Name,Parent branch code\nBLR,Bengaluru,BLR-WF2\n");
    expect(di1Rows($loop)[1]['errors'][0])->toContain('itself or one of its own sub branches');
    $newOnly = di1Stage('sub_branches', "Code,Name,Parent branch code\nBLR-WF2,Whitefield 2,BLR\n", 'new');
    expect(di1Rows($newOnly)[1]['action'])->toBe('skip');

    // The location screen shows the parent and checks it the same way.
    Livewire::test(ListLocations::class)->assertSee('Whitefield')->assertSee('Parent branch');
    Livewire::test(EditLocation::class, ['record' => $blr->getRouteKey()])
        ->fillForm(['parent_location_id' => $wf2->id])->call('save')->assertHasFormErrors(['parent_location_id']);
    Livewire::test(EditLocation::class, ['record' => $pune->getRouteKey()])
        ->fillForm(['parent_location_id' => $blr->id])->call('save')->assertHasFormErrors(['parent_location_id']);
    expect($blr->refresh()->parent_location_id)->toBeNull();
});

it('keeps regions and business regions by code: create, update, skip and the new-only choice', function () {
    Region::query()->create(['code' => 'NORTH', 'name' => 'North', 'status' => 'active']);
    $import = di1Stage('regions', "Code,Name,Description,Status\nSOUTH,South zone,KA TN KL,active\nNORTH,North zone,,\nnorth2,North 2,,maybe\n");
    $rows = di1Rows($import);
    expect($rows[1]['action'])->toBe('create')->and($rows[2]['action'])->toBe('update')->and($rows[3]['errors'])->toBe(["Status 'maybe' must be active or inactive."]);
    di1Run($import);
    expect(Region::query()->where('code', 'SOUTH')->first()->description)->toBe('KA TN KL')
        ->and(Region::query()->where('code', 'NORTH')->first()->name)->toBe('North zone');

    $same = di1Stage('regions', "Code,Name\nSOUTH,South zone\n");
    expect(di1Rows($same)[1]['action'])->toBe('skip');

    $business = di1Stage('business_regions', "Code,Name,Status\nAPAC,Asia Pacific,active\nEMEA,Europe,inactive\n", 'new');
    expect($business->create_count)->toBe(2);
    di1Run($business);
    expect(BusinessRegion::query()->where('code', 'EMEA')->first()->status->value)->toBe('inactive');
    $update = di1Stage('business_regions', "Code,Name\nAPAC,APAC (renamed)\nLATAM,Latin America\n", 'new');
    expect(di1Rows($update)[1]['action'])->toBe('skip')->and(di1Rows($update)[2]['action'])->toBe('create');
});

it('loads business units for a company and sends a change of one on file to the Change Centre when changes need approval', function () {
    $unit = BusinessUnit::factory()->create(['company_id' => $this->acme->id, 'code' => 'RETAIL', 'name' => 'Retail']);
    $import = di1Stage('business_units', "Code,Name,Company code,Description\nCORP,Corporate,ACME,Big clients\nRETAIL,Retail banking,ACME,\nNEW,New,,\n");
    $rows = di1Rows($import);
    expect($rows[1]['action'])->toBe('create')->and($rows[2]['action'])->toBe('update')
        ->and($rows[3]['errors'])->toBe(['Company code is required for a new record.']);

    app(FeatureFlags::class)->set('configuration.approval', true);
    di1Run($import);
    expect(BusinessUnit::query()->where('code', 'CORP')->first()->company_id)->toBe($this->acme->id)
        ->and($unit->refresh()->name)->toBe('Retail')
        ->and(di1Rows($import)[2]['result'])->toContain('waits for approval in the Configuration Change Centre')
        ->and(ConfigurationChange::query()->where('subject_id', $unit->id)->exists())->toBeTrue();
});

it('loads departments and sub departments, keeping a sub department in its parent company and refusing loops', function () {
    $eng = Department::factory()->create(['company_id' => $this->acme->id, 'code' => 'ENG', 'name' => 'Engineering']);
    $import = di1Stage('departments', "Code,Name,Company code,Cost centre code\nFIN,Finance,ACME,\nENG,Engineering & IT,,\nHR,People,ACME,CC-NONE\n");
    $rows = di1Rows($import);
    expect($rows[1]['action'])->toBe('create')->and($rows[2]['action'])->toBe('update')->and($rows[3]['errors'])->toBe(["Cost centre code 'CC-NONE' does not exist."]);
    di1Run($import);
    expect($eng->refresh()->name)->toBe('Engineering & IT')->and(Department::query()->where('code', 'FIN')->first()->company_id)->toBe($this->acme->id);

    $beta = di1Company('BETA', 'Beta');
    $betaOps = Department::factory()->create(['company_id' => $beta->id, 'code' => 'OPS', 'name' => 'Ops']);
    $subs = di1Stage('sub_departments', "Code,Name,Parent department code\nENG-QA,Quality,ENG\nENG-QA-AUTO,Automation,ENG-QA\nOPS,Ops,ENG\nENG,Engineering,ENG-QA-AUTO\nZZ,Nowhere,\n");
    $rows = di1Rows($subs);
    expect($subs->create_count)->toBe(2)
        ->and($rows[3]['errors'][0])->toContain('different companies')
        ->and($rows[4]['action'])->toBe('update')
        ->and($rows[5]['errors'])->toBe(['Parent department code is required for a new record.']);
    di1Run($subs);
    $qa = Department::query()->where('code', 'ENG-QA')->first();
    expect($qa->parent_department_id)->toBe($eng->id)->and($qa->company_id)->toBe($this->acme->id)
        ->and(Department::query()->where('code', 'ENG-QA-AUTO')->first()->parent_department_id)->toBe($qa->id)
        ->and($betaOps->refresh()->parent_department_id)->toBeNull()
        // Row 4 put ENG under its own sub department only at check time (its parent did not exist yet): the run refused it.
        ->and($eng->refresh()->parent_department_id)->toBeNull()
        ->and(di1Rows($subs)[4]['status'])->toBe('failed');

    $loop = di1Stage('sub_departments', "Code,Name,Parent department code\nENG,Engineering,ENG-QA-AUTO\n");
    expect(di1Rows($loop)[1]['errors'][0])->toContain('cannot sit under itself or one of its sub-departments');
});

it('loads designations with level, grade, department, job family and experience range, and grades and levels with ranks', function () {
    // Tenants start with levels L1–L6: L1 is renamed, M1 is new.
    $levels = di1Stage('levels', "Code,Name,Rank\nL1,Associate,1\nM1,Manager,7\nM2,Lead,two\n");
    expect($levels->update_count)->toBe(1)->and($levels->create_count)->toBe(1)->and(di1Rows($levels)[3]['errors'])->toBe(['Rank must be a whole number from 0 to 9999.']);
    di1Run($levels);
    $grades = di1Stage('grades', "Code,Name,Level code,Rank\nG1,Grade 1,L1,1\nG2,Grade 2,L9,2\n");
    expect(di1Rows($grades)[2]['errors'])->toBe(["Level code 'L9' does not exist."]);
    di1Run($grades);
    expect(Grade::query()->where('code', 'G1')->first()->level_id)->toBe(Level::query()->where('code', 'L1')->value('id'));

    Department::factory()->create(['company_id' => $this->acme->id, 'code' => 'ENG', 'name' => 'Engineering']);
    JobFamily::factory()->create(['code' => 'TECH', 'name' => 'Technology']);
    $existing = Designation::factory()->create(['code' => 'TL', 'name' => 'Team lead', 'min_experience_years' => 5]);
    $csv = "Code,Name,Level code,Grade code,Department code,Job family code,Minimum experience (years),Maximum experience (years),Wage skill category,Hazardous work\n"
        ."SE,Software engineer,L1,G1,ENG,TECH,2,5,Skilled,no\n"
        ."TL,Team lead,,,,,,3,,\n"
        ."QA,QA engineer,L1,,,,1,0.5,artisan,maybe\n";
    $designations = di1Stage('designations', $csv);
    $rows = di1Rows($designations);
    expect($rows[1]['action'])->toBe('create')
        ->and($rows[2]['errors'])->toBe(['The experience range runs from a minimum to a higher maximum, in years.'])
        ->and(implode(' ', $rows[3]['errors']))->toContain('minimum to a higher maximum')->toContain("Wage skill category 'artisan'")->toContain('Hazardous work must be yes or no.');
    di1Run($designations);
    $se = Designation::query()->where('code', 'SE')->first();
    expect($se->level->code)->toBe('L1')->and($se->grade->code)->toBe('G1')->and($se->department->code)->toBe('ENG')->and($se->jobFamily->code)->toBe('TECH')
        ->and((float) $se->min_experience_years)->toBe(2.0)->and((float) $se->max_experience_years)->toBe(5.0)
        ->and($se->wage_skill_category)->toBe('skilled')->and($se->is_hazardous)->toBeFalse()
        ->and((float) $existing->refresh()->min_experience_years)->toBe(5.0);
});

it('loads the skill master and publishes designation wise skills as one role requirements version per designation', function () {
    $skills = di1Stage('skills', "Code,Name,Category,Type\nPYTHON,Python,technical,Technical\nSQL,SQL,technical,technical\nNEGOTIATION,Negotiation,behavioural,behavioural\nBAD CODE,Bad,,wizardry\n");
    expect($skills->create_count)->toBe(3)->and(implode(' ', di1Rows($skills)[4]['errors']))->toContain('Code is up to 64 letters')->toContain("Type 'wizardry'");
    di1Run($skills);
    expect(Skill::query()->where('code', 'PYTHON')->first()->skill_type)->toBe('technical');

    $se = Designation::factory()->create(['code' => 'SE', 'name' => 'Software engineer']);
    $csv = "Designation code,Skill code,Expected level,Required,Primary skill\nSE,PYTHON,Advanced,yes,yes\nSE,SQL,2,no,\nSE,NEGOTIATION,9,,\nSE,PYTHON,1,,\nNOPE,SQL,1,,\n";
    $import = di1Stage('designation_skills', $csv);
    $rows = di1Rows($import);
    expect($import->create_count)->toBe(2)
        ->and($rows[3]['errors'][0])->toContain("Expected level '9' is not on the skill's scale")
        ->and($rows[4]['errors'])->toBe(['The same designation and skill are in row 1.'])
        ->and($rows[5]['errors'])->toBe(["Designation code 'NOPE' does not exist."]);
    di1Run($import);
    $versions = RoleRequirementVersion::query()->where('designation_id', $se->id)->get();
    expect($versions)->toHaveCount(1)
        ->and(collect($versions[0]->skills)->keyBy('skill_id')->map(fn ($s) => [(float) $s['level'], $s['required'], $s['primary'] ?? false])->all())->toBe([
            Skill::query()->where('code', 'PYTHON')->value('id') => [3.0, true, true],
            Skill::query()->where('code', 'SQL')->value('id') => [2.0, false, false],
        ])
        ->and(di1Rows($import)[2]['result'])->toContain('Published with row 1 in role requirements version 1');

    // The same day needs a later Effective from; a later one keeps the skills on file and changes the level.
    $sameDay = di1Stage('designation_skills', "Designation code,Skill code,Expected level\nSE,SQL,3\nSE,PYTHON,3\n");
    expect(di1Rows($sameDay)[1]['errors'][0])->toContain('set Effective from to a later date')->and(di1Rows($sameDay)[2]['action'])->toBe('skip');
    $later = di1Stage('designation_skills', "Designation code,Skill code,Expected level,Effective from\nSE,SQL,3,2026-10-01\nSE,NEGOTIATION,1,2026-10-01\n", 'update');
    expect(di1Rows($later)[1]['action'])->toBe('update')->and(di1Rows($later)[2]['action'])->toBe('skip');
    di1Run($later);
    $v2 = RoleRequirementVersion::query()->where('designation_id', $se->id)->orderByDesc('version')->first();
    expect($v2->version)->toBe(2)->and($v2->effective_from->toDateString())->toBe('2026-10-01')
        ->and(collect($v2->skills)->pluck('level', 'skill_id')->map(fn ($l) => (float) $l)->all())->toBe([Skill::query()->where('code', 'PYTHON')->value('id') => 3.0, Skill::query()->where('code', 'SQL')->value('id') => 3.0]);
});

it('loads states, districts and cities with their links and checks them', function () {
    $states = di1Stage('geo_states', "Code,Name,Country code,ISO region code\nKA,Karnataka,IN,IN-KA\nMH,Maharashtra,,Maharashtra\nXX,Nowhere,IN,ZZ\n");
    expect($states->create_count)->toBe(2)->and(di1Rows($states)[3]['errors'][0])->toContain("ISO region code 'ZZ' is not an active region of IN");
    di1Run($states);
    expect(GeoPlace::query()->where('code', 'MH')->first()->region_code)->toBe('MH');

    // A code may repeat across kinds (district KA beside state KA); a district's state must be a state.
    $districts = di1Stage('geo_districts', "Code,Name,State code\nBLR-U,Bengaluru Urban,KA\nPUNE,Pune,MH\nKA,Kalaburagi,KA\nMYS,Mysuru,BLR-U\n");
    expect($districts->create_count)->toBe(3)->and(di1Rows($districts)[4]['errors'][0])->toContain("State code 'BLR-U' does not exist");
    di1Run($districts);
    expect(GeoPlace::query()->where('kind', 'district')->where('code', 'KA')->first()->state->code)->toBe('KA')
        ->and(GeoPlace::query()->where('code', 'KA')->count())->toBe(2);

    $cities = di1Stage('geo_cities', "Code,Name,State code,District code\nBLR,Bengaluru,KA,BLR-U\nPNQ,Pune,MH,PUNE\nODD,Odd,KA,PUNE\nNOS,No state,,\n");
    $rows = di1Rows($cities);
    expect($cities->create_count)->toBe(2)->and($rows[3]['errors'])->toBe(["District 'PUNE' is not in the city's state."])
        ->and($rows[4]['errors'])->toBe(['State code is required for a new record.']);
    di1Run($cities);
    $blr = GeoPlace::query()->where('kind', GeoPlaceKind::City->value)->where('code', 'BLR')->first();
    expect($blr->state->code)->toBe('KA')->and($blr->district->code)->toBe('BLR-U')->and($blr->country_code)->toBe('IN');

    // The same code is fine in another kind; a city's state change needs its district in the new state.
    $move = di1Stage('geo_cities', "Code,Name,State code\nBLR,Bengaluru,MH\n", 'update');
    expect(di1Rows($move)[1]['errors'])->toBe(['The city moves to another state: give its district in that state (District code).']);
});

it('keeps the dimension values with their parents, from the file or on file, and refuses loops', function () {
    $import = di1Stage('dimensions', "Code,Name,Parent code\nFIN,Financial services,\nBFSI,Banking,FIN\nINS,Insurance,fin\nLOOP,Loop,LOOP\nORPH,Orphan,NOPE\n");
    $rows = di1Rows($import);
    expect($import->create_count)->toBe(3)->and($rows[4]['errors'])->toBe(['A value cannot sit under itself.'])
        ->and($rows[5]['errors'][0])->toContain("Parent code 'NOPE' does not exist");
    di1Run($import);
    expect(DimensionValue::query()->where('code', 'INS')->first()->parent->code)->toBe('FIN');

    $loop = di1Stage('dimensions', "Code,Name,Parent code\nFIN,Financial services,BFSI\n");
    expect(di1Rows($loop)[1]['errors'][0])->toContain('a value cannot sit under one of its own values');
    $update = di1Stage('dimensions', "Code,Name,Parent code\nINS,Insurance,BFSI\n", 'update');
    di1Run($update);
    expect(DimensionValue::query()->where('code', 'INS')->first()->parent->code)->toBe('BFSI');
});

it('lets only people with bulk import and the module permission run a DI-1 upload', function () {
    $orgOnly = tenantUser($this->tenant, ['employee.import', 'organisation.create']);
    expect(ImportTypes::allowed($orgOnly, 'regions'))->toBeTrue()
        ->and(ImportTypes::allowed($orgOnly, 'designations'))->toBeFalse()
        ->and(ImportTypes::allowed($orgOnly, 'projects'))->toBeFalse()
        ->and(ImportTypes::allowed($orgOnly, 'jv_mappings'))->toBeFalse()
        ->and(ImportTypes::allowed(tenantUser($this->tenant, ['organisation.create']), 'regions'))->toBeFalse()
        ->and(ImportTypes::allowed(tenantUser($this->tenant, ['employee.import', 'career.manage']), 'designation_skills'))->toBeTrue()
        ->and(ImportTypes::allowed(tenantUser($this->tenant, ['employee.import', 'project.manage']), 'projects'))->toBeTrue()
        ->and(ImportTypes::allowed(tenantUser($this->tenant, ['employee.import', 'integration.manage']), 'jv_mappings'))->toBeTrue();
});
