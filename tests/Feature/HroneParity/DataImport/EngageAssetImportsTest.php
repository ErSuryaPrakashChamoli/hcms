<?php

use App\Domain\Assets\Imports\AssetImports;
use App\Domain\Assets\Imports\AssetProductImports;
use App\Domain\Assets\Imports\AssetSubtypeImports;
use App\Domain\Assets\Imports\AssetTypeImports;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetAssignment;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetMovement;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Recognition\Imports\BadgeAwardImports;
use App\Domain\Recognition\Imports\RewardPointImports;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Models\Recognition;
use App\Domain\Recognition\Services\Recognitions;
use App\Domain\Recognition\Services\RewardPoints;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Di35Helpers.php';
require_once __DIR__.'/../../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../../Performance/PerformanceTestHelpers.php';

/*
 | Data import DI-35, Engage and Asset heads: badge allocation (Recognitions::allocate: HR for the organisation, no giver
 | quota, the receiver's limits), reward points (RewardPoints::adjust against the budget), asset types, sub types, products
 | and the asset register (Assets::receive / assign / …).
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 06:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['recognition.view', 'recognition.give']);
    $this->asha = activeEmployee($this->manager, ['recognition.view', 'recognition.give']);
    $this->ravi = activeEmployee($this->manager, ['recognition.view', 'recognition.give']);
});

it('registers the engage and asset uploads with their rights', function () {
    foreach ([BadgeAwardImports::TYPE => ['recognition.manage', 'engage'], RewardPointImports::TYPE => ['recognition.points', 'engage'],
        AssetTypeImports::TYPE => ['asset.manage', 'asset'], AssetSubtypeImports::TYPE => ['asset.manage', 'asset'], AssetProductImports::TYPE => ['asset.manage', 'asset'],
        AssetImports::TYPE => ['asset.manage', 'asset']] as $type => [$permission, $area]) {
        expect(RowImportTypes::TYPES[$type][2])->toBe($permission)->and(RowImportTypes::TYPES[$type][3])->toBe($area);
    }
    expect(ImportTypes::modes(BadgeAwardImports::TYPE))->toBeNull()->and(ImportTypes::modes(RewardPointImports::TYPE))->toBeNull()
        ->and(ImportTypes::modes(AssetImports::TYPE))->not->toBeNull();
});

it('allocates badges for the organisation without using HR\'s own quota, within each badge\'s receiving limit', function () {
    app(SettingsRepository::class)->set('engage.badges.per_month', 1, 'test');
    Badge::query()->where('code', 'GREAT_JOB')->update(['receive_limit' => 1, 'receive_period' => 'month']);
    [$asha, $ravi] = [$this->asha->employee_code, $this->ravi->employee_code];
    $csv = "Employee code,Badge code,Reason\n{$asha},GREAT_JOB,Closed the audit early\n{$ravi},great_job,Led the office move\n{$asha},GREAT_JOB,Again\n{$ravi},NOPE,x\n{$asha},TEAM_PLAYER,Helped everyone\n";
    $import = di35Stage(BadgeAwardImports::TYPE, $csv);
    expect($import->create_count)->toBe(3)->and($import->error_count)->toBe(2)
        ->and(di35Errors($import)[3][0])->toContain('more than 1 time(s) in its period (counting the rows above)')
        ->and(di35Errors($import)[4][0])->toContain("Badge code 'NOPE' does not exist");
    di35Run($import);

    // Three badges from one HR person although the monthly quota is one: they are the organisation's, not HR's own.
    $given = Recognition::query()->where('giver_user_id', $this->hr->id)->get();
    expect($given)->toHaveCount(3)->and($given->pluck('source')->unique()->all())->toBe(['upload'])
        ->and($given->firstWhere('employee_id', $this->asha->id)->message)->toBe('Closed the audit early')
        ->and(app(Recognitions::class)->givenThisMonth($this->hr))->toBe(0);

    // The receiving limit still applies on file; a person giving still uses their own quota.
    expect(di35Errors(di35Stage(BadgeAwardImports::TYPE, "Employee code,Badge code\n{$asha},GREAT_JOB\n"))[1][0])->toContain('more than 1 time(s)');
    app(Recognitions::class)->give(Badge::query()->where('code', 'THANK_YOU')->first(), $this->ravi, $this->asha->user);
    expect(fn () => app(Recognitions::class)->give(Badge::query()->where('code', 'THANK_YOU')->first(), $this->manager, $this->asha->user))->toThrow(RuntimeException::class, 'your 1 badges');
});

it('refuses badge allocation to people without the HR right, to oneself and for switched-off badges', function () {
    Badge::query()->where('code', 'TEAM_PLAYER')->update(['status' => 'inactive']);
    $this->asha->update(['user_id' => $this->hr->id]);
    $import = di35Stage(BadgeAwardImports::TYPE, "Employee code,Badge code\n{$this->asha->employee_code},GREAT_JOB\n{$this->ravi->employee_code},TEAM_PLAYER\n");
    expect(di35Errors($import)[1])->toBe(['You cannot allocate a badge to yourself.'])
        ->and(di35Errors($import)[2])->toBe(['The badge Team Player is switched off.']);

    $this->actingAs(tenantUser($this->tenant, ['employee.import', 'recognition.give']));
    expect(fn () => di35Stage(BadgeAwardImports::TYPE, "Employee code,Badge code\nX,GREAT_JOB\n"))->toThrow(RuntimeException::class, 'You may not run this upload.');
});

it('credits and debits reward points through the budget, counting the rows above', function () {
    app(RewardPoints::class)->deposit(100, $this->hr, 'Budget');
    [$asha, $ravi] = [$this->asha->employee_code, $this->ravi->employee_code];
    $csv = "Employee code,Points,Reason\n{$asha},60,Sales contest\n{$ravi},50,Referral\n{$asha},-20,Correction\n{$ravi},-5,Too many\n{$asha},0,Zero\n{$asha},10,\n";
    $import = di35Stage(RewardPointImports::TYPE, $csv);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(4)
        ->and(di35Errors($import)[2])->toBe(['The points budget has 40 points left after the rows above.'])
        ->and(di35Errors($import)[4])->toBe(['The employee has 0 points (after the rows above); they cannot lose 5.'])
        ->and(di35Errors($import)[5])->toBe(['Points must be a whole number other than 0: positive to credit, negative to debit.'])
        ->and(di35Errors($import)[6])->toBe(['Reason is required.']);
    di35Run($import);

    $points = app(RewardPoints::class);
    expect($points->earnedBalance($this->asha))->toBe(40)->and($points->earnedBalance($this->ravi))->toBe(0)->and($points->budgetAvailable())->toBe(60);
});

it('loads asset types and sub types by code and updates them', function () {
    $types = di35Stage(AssetTypeImports::TYPE, "Code,Name,Serial number required,IT asset,Useful life (months),Owner role,Due days\nphone,Mobile phone,yes,yes,24,asset-admin,3\nCHAIR,Chair,no,maybe,,,\nDESK,Desk,,,,no-such-role,\n");
    expect($types->create_count)->toBe(1)->and($types->error_count)->toBe(2)
        ->and(di35Errors($types)[2])->toBe(['IT asset must be yes or no.'])
        ->and(di35Errors($types)[3][0])->toContain("Role 'no-such-role' does not exist");
    di35Run($types);
    $phone = AssetCategory::query()->where('code', 'PHONE')->sole();
    expect($phone->name)->toBe('Mobile phone')->and($phone->requires_serial)->toBeTrue()->and($phone->default_life_months)->toBe(24)->and($phone->fulfilment_days)->toBe(3)
        ->and($phone->owner_role_id)->not->toBeNull();

    expect(di35Stage(AssetTypeImports::TYPE, "Code,Name,Due days\nPHONE,Mobile phone,5\n", 'new')->skip_count)->toBe(1);
    di35Run(di35Stage(AssetTypeImports::TYPE, "Code,Name,Due days\nPHONE,Mobile phone,5\n", 'update'));
    expect($phone->refresh()->fulfilment_days)->toBe(5)->and($phone->default_life_months)->toBe(24);

    $subtypes = di35Stage(AssetSubtypeImports::TYPE, "Code,Asset type code,Name,Duplicate serial numbers allowed\nPHONE-STD,PHONE,Standard phone,no\nPHONE-X,NOPE,Bad,\n");
    expect($subtypes->create_count)->toBe(1)->and(di35Errors($subtypes)[2])->toBe(["Asset type code 'NOPE' does not exist."]);
    di35Run($subtypes);
    $subtype = AssetSubtype::query()->where('code', 'PHONE-STD')->sole();
    expect($subtype->asset_category_id)->toBe($phone->id)->and($subtype->allow_duplicate_serial)->toBeFalse()->and($subtype->status->value)->toBe('active');
});

it('loads products by type, manufacturer and name', function () {
    $laptop = AssetCategory::query()->where('code', 'LAPTOP')->sole();
    $csv = "Asset type code,Manufacturer,Product name,Useful life (months),Deployment type\nLAPTOP,Lenovo,ThinkPad T14,48,hardware\nLAPTOP,Apple,MacBook Pro,,Cloud / SaaS subscription\nLAPTOP,Dell,XPS,,rocket\nNOPE,Dell,XPS,,\n";
    $import = di35Stage(AssetProductImports::TYPE, $csv);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(2)
        ->and(di35Errors($import)[3][0])->toStartWith('Deployment type must be one of: hardware');
    di35Run($import);
    $thinkpad = AssetModel::query()->where('name', 'ThinkPad T14')->sole();
    expect($thinkpad->asset_category_id)->toBe($laptop->id)->and($thinkpad->manufacturer)->toBe('Lenovo')->and($thinkpad->life_months)->toBe(48)
        ->and(AssetModel::query()->where('name', 'MacBook Pro')->value('deployment_type'))->toBe('cloud_saas');

    expect(di35Stage(AssetProductImports::TYPE, "Asset type code,Manufacturer,Product name,Useful life (months)\nlaptop,LENOVO,thinkpad t14,60\n", 'new')->skip_count)->toBe(1);
    di35Run(di35Stage(AssetProductImports::TYPE, "Asset type code,Manufacturer,Product name,Useful life (months)\nlaptop,LENOVO,thinkpad t14,60\n", 'update'));
    expect($thinkpad->refresh()->life_months)->toBe(60)->and(AssetModel::query()->where('asset_category_id', $laptop->id)->where('name', 'ThinkPad T14')->count())->toBe(1);
});

it('receives assets into stock, assigns them through the asset service and keeps serial numbers unique', function () {
    $laptop = AssetCategory::query()->where('code', 'LAPTOP')->sole();
    AssetModel::query()->create(['asset_category_id' => $laptop->id, 'manufacturer' => 'Lenovo', 'name' => 'ThinkPad T14']);
    AssetModel::query()->create(['asset_category_id' => $laptop->id, 'manufacturer' => 'Apple', 'name' => 'MacBook Pro']);
    $location = Location::factory()->create(['code' => 'DEL']);
    $asha = $this->asha->employee_code;
    $csv = "Asset tag,Asset name,Asset type code,Product name,Serial number,Location code,Status,Custodian employee code,Assigned on,Purchase date,Purchase cost,Vendor name\n"
        ."lt-001,Asha's laptop,LAPTOP,ThinkPad T14,SN-1,DEL,,{$asha},2026-07-01,2026-06-15,\"95,000\",Acme IT\n"
        ."LT-002,Spare laptop,LAPTOP,ThinkPad T14,SN-2,DEL,in_stock,,,2026-06-15,87000,Acme IT\n"
        ."LT-003,Twin,LAPTOP,ThinkPad T14,sn-1,,,,,,,\nLT-004,Old,LAPTOP,,SN-4,,disposed,,,,,\nLT-005,Nobody,LAPTOP,,SN-5,,assigned,,,,,\nLT-006,Future,LAPTOP,,SN-6,,,,,2027-01-01,,\n"
        ."LT-007,Other make,LAPTOP,MacBook Pro,SN-1,,,,,,,\n";
    $import = di35Stage(AssetImports::TYPE, $csv);
    // Decision D35-6: a serial number is unique per product — the same serial of another make is fine.
    expect($import->create_count)->toBe(3)->and($import->error_count)->toBe(4)
        ->and(di35Errors($import)[3])->toBe(['The same serial number of the same product is in row 1.'])
        ->and(di35Errors($import)[4][0])->toStartWith('Status of a new asset must be one of')
        ->and(di35Errors($import)[5])->toBe(['An assigned asset needs the custodian\'s employee code.'])
        ->and(di35Errors($import)[6])->toBe(['Purchase date cannot be in the future.']);
    di35Run($import);

    $assigned = Asset::query()->where('asset_tag', 'LT-001')->sole();
    expect($assigned->status)->toBe('assigned')->and($assigned->custodian_id)->toBe($this->asha->id)->and($assigned->location_id)->toBe($location->id)
        ->and((float) $assigned->purchase_cost)->toBe(95000.0)->and($assigned->vendor)->toBe('Acme IT')
        ->and(AssetAssignment::query()->where('asset_id', $assigned->id)->value('assigned_on')?->toDateString())->toBe('2026-07-01')
        ->and(AssetMovement::query()->where('asset_id', $assigned->id)->pluck('type')->all())->toContain('procured', 'assigned')
        ->and(Asset::query()->where('asset_tag', 'LT-002')->value('status'))->toBe('in_stock')
        ->and(Asset::query()->where('serial_number', 'SN-1')->count())->toBe(2);

    // A serial already on file is refused; an asset on file is updated like its edit page — never its status or custodian.
    $again = di35Stage(AssetImports::TYPE, "Asset tag,Asset name,Asset type code,Product name,Serial number\nLT-009,Laptop 9,LAPTOP,ThinkPad T14,SN-2\nLT-010,Laptop 10,LAPTOP,MacBook Pro,SN-2\nLT-011,,,,\n");
    expect(di35Errors($again)[1])->toBe(['Serial number SN-2 is already used by asset LT-002 of the same product.'])
        ->and($again->create_count)->toBe(1)
        ->and(di35Errors($again)[3])->toBe(['Asset name is required for a new asset.', 'Asset type code is required for a new asset.']);
    $other = Location::factory()->create(['code' => 'BOM']);
    expect(di35Stage(AssetImports::TYPE, "Asset tag,Location code,Invoice number\nLT-002,BOM,INV-7\n", 'new')->skip_count)->toBe(1);
    $update = di35Stage(AssetImports::TYPE, "Asset tag,Location code,Invoice number,Status\nLT-002,BOM,INV-7,\nLT-001,,,in_stock\n", 'update');
    expect($update->update_count)->toBe(1)->and(di35Errors($update)[2][0])->toContain('change its status with the asset\'s actions');
    di35Run($update);
    $spare = Asset::query()->where('asset_tag', 'LT-002')->sole();
    expect($spare->location_id)->toBe($other->id)->and($spare->invoice_number)->toBe('INV-7')
        ->and(AssetMovement::query()->where('asset_id', $spare->id)->where('type', 'location')->exists())->toBeTrue();
});

it('validates the sample file of every engage and asset process', function () {
    app(RewardPoints::class)->deposit(1000, $this->hr, 'Budget');
    foreach (['engage.badges', 'engage.rewards', 'asset.types', 'asset.subtypes', 'asset.products', 'asset.assets'] as $process) {
        $import = di35StageSample($process);
        expect($import->error_count)->toBe(0, $process.': '.json_encode(di35Errors($import)))->and($import->row_count)->toBeGreaterThanOrEqual(2);
    }
});
