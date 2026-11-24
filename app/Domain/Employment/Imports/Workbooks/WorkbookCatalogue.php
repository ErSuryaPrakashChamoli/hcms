<?php

namespace App\Domain\Employment\Imports\Workbooks;

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\BusinessRegion;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\DimensionValue;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\EmployeeCategory;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\GeoPlace;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\ProfitCentre;
use App\Domain\Organisation\Models\Region;
use App\Domain\Organisation\Models\Team;
use App\Domain\Organisation\Models\WorkMode;
use App\Domain\Partners\Models\Vendor;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\Kra;
use RuntimeException;

/**
 * Master uploads: the workbooks an organisation can fill in once instead of making many separate uploads. A workbook is
 * a list of worksheets; each worksheet is one existing upload process of the Data import catalogue (ImportCatalogue), so
 * its columns, checks, permissions and write path are that upload's own. A worksheet declares what it creates for later
 * worksheets (`provides`: reference kind => the column holding the key) and what it refers to (`needs`: kind => column),
 * which is all the engine needs to order the worksheets (DependencyPlanner) and to accept, in the preview, a reference
 * to a record that an earlier worksheet of the same workbook adds (PlannedReferences).
 *
 * The grouping follows two rules (docs/architecture/master-data-import.md):
 *  - set-up and go-live data that is loaded together and refers to itself goes in one workbook;
 *  - periodic operational uploads (a month's attendance, overtime, arrears, variable pay, statutory overrides, ratings,
 *    normalisation, rewards) stay individual: each has its own month or cycle and its own approval, and coupling them
 *    would make one late sheet hold up the others.
 */
final class WorkbookCatalogue
{
    /** employee_imports.type of a workbook upload: the prefix and the workbook key. */
    public const TYPE_PREFIX = 'workbook_';

    /**
     * Reference kinds: kind => [label, model class (null: not a record of its own, e.g. "has family members in this
     * workbook"), the model column the key is matched on]. Keys are matched without case.
     */
    public const KINDS = [
        'company' => ['Company', Company::class, 'code'],
        'cost_centre' => ['Cost centre', CostCentre::class, 'code'],
        'geo_state' => ['State', GeoPlace::class, 'code'],
        'geo_district' => ['District', GeoPlace::class, 'code'],
        'region' => ['Region', Region::class, 'code'],
        'business_region' => ['Business region', BusinessRegion::class, 'code'],
        'location' => ['Branch', Location::class, 'code'],
        'business_unit' => ['Business unit', BusinessUnit::class, 'code'],
        'department' => ['Department', Department::class, 'code'],
        'level' => ['Level', Level::class, 'code'],
        'grade' => ['Grade', Grade::class, 'code'],
        'designation' => ['Designation', Designation::class, 'code'],
        'division' => ['Division', Division::class, 'code'],
        'team' => ['Team', Team::class, 'code'],
        'profit_centre' => ['Profit centre', ProfitCentre::class, 'code'],
        'job_family' => ['Job family', JobFamily::class, 'code'],
        'employment_type' => ['Employment type', EmploymentType::class, 'code'],
        'employee_category' => ['Employee category', EmployeeCategory::class, 'code'],
        'work_mode' => ['Work mode', WorkMode::class, 'code'],
        'dimension_value' => ['Dimension value', DimensionValue::class, 'code'],
        'skill' => ['Skill', Skill::class, 'code'],
        'employee' => ['Employee', Employee::class, 'employee_code'],
        'family_member' => ['Family members of the employee', null, 'employee_code'],
        'leave_balance' => ['Leave balance of the employee', null, 'employee_code'],
        'vendor' => ['Vendor', Vendor::class, 'code'],
        'kra' => ['KRA', Kra::class, 'code'],
        'asset_type' => ['Asset type', AssetCategory::class, 'code'],
        'asset_subtype' => ['Asset sub type', AssetSubtype::class, 'code'],
        'asset_product' => ['Asset product', AssetModel::class, 'name'],
        'holiday_calendar' => ['Holiday calendar', HolidayCalendar::class, 'code'],
    ];

    /**
     * workbook key => definition. Worksheet titles are the tab names (Excel allows 31 characters) and are listed in the
     * order the template shows them; the run order comes from the dependencies, not from this list.
     *
     * @var array<string, array{label: string, head: string, icon: string, about: string, use: string, sheets: array<string, array{process: string, provides?: array<string, string>, needs?: array<string, string|list<string>>}>}>
     */
    public const WORKBOOKS = [
        'organisation' => [
            'label' => 'Organisation Master', 'head' => 'core', 'icon' => 'heroicon-o-building-office-2',
            'about' => 'Companies, cost and profit centres, places, branches, business units, divisions, departments, teams, people setup masters, skills and where each unit sits.',
            'use' => 'Setting up PeopleOS, adding a company, or reshaping the organisation.',
            'sheets' => [
                'Companies' => ['process' => 'core.companies', 'provides' => ['company' => 'code']],
                'Cost centres' => ['process' => 'core.cost_centres', 'provides' => ['cost_centre' => 'code'], 'needs' => ['company' => ['company_code', 'applies_to']]],
                'Profit centres' => ['process' => 'core.profit_centres', 'provides' => ['profit_centre' => 'code'], 'needs' => ['company' => ['company_code', 'applies_to']]],
                'States' => ['process' => 'core.states', 'provides' => ['geo_state' => 'code']],
                'Districts' => ['process' => 'core.districts', 'provides' => ['geo_district' => 'code'], 'needs' => ['geo_state' => 'state_code']],
                'Cities' => ['process' => 'core.cities', 'needs' => ['geo_state' => 'state_code', 'geo_district' => 'district_code']],
                'Regions' => ['process' => 'core.regions', 'provides' => ['region' => 'code'], 'needs' => ['company' => 'applies_to']],
                'Business regions' => ['process' => 'core.business_regions', 'provides' => ['business_region' => 'code'], 'needs' => ['company' => 'applies_to']],
                'Branches' => ['process' => 'core.branches', 'provides' => ['location' => 'code'], 'needs' => ['company' => ['company_code', 'applies_to'], 'region' => 'region_code', 'business_region' => 'business_region_code']],
                'Sub branches' => ['process' => 'core.sub_branches', 'provides' => ['location' => 'code'], 'needs' => ['location' => 'parent_code', 'region' => 'region_code', 'business_region' => 'business_region_code', 'company' => 'applies_to']],
                'Business units' => ['process' => 'core.business_units', 'provides' => ['business_unit' => 'code'], 'needs' => ['company' => ['company_code', 'applies_to']]],
                'Divisions' => ['process' => 'core.divisions', 'provides' => ['division' => 'code'], 'needs' => ['company' => ['company_code', 'applies_to']]],
                'Departments' => ['process' => 'core.departments', 'provides' => ['department' => 'code'], 'needs' => ['company' => ['company_code', 'applies_to'], 'cost_centre' => 'cost_centre_code']],
                'Sub departments' => ['process' => 'core.sub_departments', 'provides' => ['department' => 'code'], 'needs' => ['department' => 'parent_code', 'cost_centre' => 'cost_centre_code', 'company' => 'applies_to']],
                'Teams' => ['process' => 'core.teams', 'provides' => ['team' => 'code'], 'needs' => ['company' => ['company_code', 'applies_to']]],
                'Levels' => ['process' => 'core.levels', 'provides' => ['level' => 'code'], 'needs' => ['company' => 'applies_to']],
                'Grades' => ['process' => 'core.grades', 'provides' => ['grade' => 'code'], 'needs' => ['level' => 'level_code', 'company' => 'applies_to']],
                'Job families' => ['process' => 'core.job_families', 'provides' => ['job_family' => 'code'], 'needs' => ['company' => 'applies_to']],
                'Designations' => ['process' => 'core.designations', 'provides' => ['designation' => 'code'],
                    'needs' => ['level' => 'level_code', 'grade' => 'grade_code', 'department' => 'department_code', 'job_family' => 'job_family_code', 'company' => 'applies_to']],
                'Employment types' => ['process' => 'core.employment_types', 'provides' => ['employment_type' => 'code'], 'needs' => ['company' => 'applies_to']],
                'Employee categories' => ['process' => 'core.employee_categories', 'provides' => ['employee_category' => 'code'], 'needs' => ['company' => 'applies_to']],
                'Work modes' => ['process' => 'core.work_modes', 'provides' => ['work_mode' => 'code'], 'needs' => ['company' => 'applies_to']],
                'Dimension values' => ['process' => 'core.dimensions', 'provides' => ['dimension_value' => 'code'], 'needs' => ['dimension_value' => 'parent_code', 'company' => 'applies_to']],
                'Skills' => ['process' => 'core.skills', 'provides' => ['skill' => 'code']],
                'Designation skills' => ['process' => 'core.designation_skills', 'needs' => ['designation' => 'designation_code', 'skill' => 'skill_code']],
                'Unit tree' => ['process' => 'core.organisation_tree',
                    'needs' => ['company' => ['unit_code', 'parent_code'], 'business_unit' => ['unit_code', 'parent_code'], 'division' => ['unit_code', 'parent_code'],
                        'department' => ['unit_code', 'parent_code'], 'team' => ['unit_code', 'parent_code'], 'location' => ['unit_code', 'parent_code']]],
            ],
        ],
        'employees' => [
            'label' => 'Employee Master', 'head' => 'workforce', 'icon' => 'heroicon-o-users',
            'about' => 'Employees with their job, reporting manager, family, education, experience, nominees, skills, bank, settings, contracts and insurance.',
            'use' => 'Moving employees into PeopleOS, a batch of joiners, or a profile clean-up. Load the Organisation Master first.',
            'sheets' => [
                'Employees' => ['process' => 'workforce.new_hire_advance', 'provides' => ['employee' => 'employee.employee_code'],
                    'needs' => ['company' => 'position.company_code', 'location' => 'position.location_code', 'business_unit' => 'position.business_unit_code', 'department' => 'position.department_code',
                        'designation' => 'position.designation_code', 'level' => 'position.level_code', 'grade' => 'position.grade_code', 'cost_centre' => 'position.cost_centre_code']],
                'Job history' => ['process' => 'workforce.employee_advance', 'needs' => ['employee' => ['employee_code', 'manager_code']]],
                'Family members' => ['process' => 'workforce.family', 'provides' => ['family_member' => 'employee_code'], 'needs' => ['employee' => 'employee_code']],
                'Education' => ['process' => 'workforce.education', 'needs' => ['employee' => 'employee_code']],
                'Work experience' => ['process' => 'workforce.work_experience', 'needs' => ['employee' => 'employee_code']],
                'Nominees' => ['process' => 'workforce.nominees', 'needs' => ['employee' => 'employee_code', 'family_member' => 'employee_code']],
                'Employee skills' => ['process' => 'workforce.employee_skills', 'needs' => ['employee' => 'employee_code']],
                'Other details' => ['process' => 'workforce.other_details', 'needs' => ['employee' => 'employee_code']],
                'Bank accounts' => ['process' => 'workforce.bank', 'needs' => ['employee' => 'employee_code']],
                'Employee settings' => ['process' => 'workforce.employee_settings', 'needs' => ['employee' => 'employee_code']],
                'Vendors' => ['process' => 'workforce.vendor', 'provides' => ['vendor' => 'code']],
                'Contracts' => ['process' => 'workforce.contract', 'needs' => ['employee' => 'employee_code', 'vendor' => 'vendor_code']],
                'Health insurance' => ['process' => 'workforce.health_insurance', 'needs' => ['employee' => 'employee_code']],
            ],
        ],
        'time_office' => [
            'label' => 'Attendance & Time Office', 'head' => 'time_office', 'icon' => 'heroicon-o-clock',
            'about' => 'Holiday calendars and their holidays, opening leave balances, leave already taken, comp-off credits, the shift roster and geo work locations.',
            'use' => 'Going live on attendance and leave, or the start of a leave year. Monthly attendance and overtime stay separate uploads.',
            'sheets' => [
                'Holiday calendars' => ['process' => 'time_office.holiday_calendars', 'provides' => ['holiday_calendar' => 'code'], 'needs' => ['company' => 'applies_to']],
                'Holidays' => ['process' => 'time_office.holidays', 'needs' => ['holiday_calendar' => 'calendar_code']],
                'Opening leave balances' => ['process' => 'time_office.opening_balance', 'provides' => ['leave_balance' => 'employee_code'], 'needs' => ['employee' => 'employee_code']],
                'Comp-off credits' => ['process' => 'time_office.comp_off', 'provides' => ['leave_balance' => 'employee_code'], 'needs' => ['employee' => 'employee_code']],
                'Leave taken' => ['process' => 'time_office.leave_requests', 'needs' => ['employee' => 'employee_code', 'leave_balance' => 'employee_code']],
                'Shift roster' => ['process' => 'time_office.shift', 'needs' => ['employee' => 'employee_code']],
                'Geo work locations' => ['process' => 'time_office.geo_locations', 'needs' => ['employee' => 'employee_code']],
            ],
        ],
        'payroll' => [
            'label' => 'Payroll & Compensation', 'head' => 'payroll', 'icon' => 'heroicon-o-banknotes',
            'about' => 'Statutory details, PF options, salaries, previous employment and year-to-date salary, declarations, loans, reimbursement and perquisite openings.',
            'use' => 'Starting payroll in PeopleOS part-way through a financial year. Monthly inputs (variable pay, arrears, overrides) stay separate uploads.',
            'sheets' => [
                'Statutory details' => ['process' => 'payroll.employee_statutory', 'needs' => ['employee' => 'employee_code']],
                'PF options' => ['process' => 'payroll.pf_options', 'needs' => ['employee' => 'employee_code']],
                'Salaries' => ['process' => 'payroll.salary_structure_manual', 'needs' => ['employee' => 'employee_code']],
                'Previous employment' => ['process' => 'workforce.previous_employment', 'needs' => ['employee' => 'employee_code']],
                'Salary paid this year' => ['process' => 'payroll.previous_salary', 'needs' => ['employee' => 'employee_code']],
                'Investments' => ['process' => 'payroll.investments', 'needs' => ['employee' => 'employee_code']],
                'HRA declarations' => ['process' => 'payroll.hra', 'needs' => ['employee' => 'employee_code']],
                '12BAA declarations' => ['process' => 'payroll.form_12baa', 'needs' => ['employee' => 'employee_code']],
                'Loans' => ['process' => 'payroll.loans', 'needs' => ['employee' => 'employee_code']],
                'Reimbursement opening' => ['process' => 'payroll.reimbursement_opening', 'needs' => ['employee' => 'employee_code']],
                'Perquisites' => ['process' => 'payroll.perquisites_manual', 'needs' => ['employee' => 'employee_code']],
            ],
        ],
        'goals' => [
            'label' => 'Performance Goals', 'head' => 'performance', 'icon' => 'heroicon-o-chart-bar',
            'about' => 'The goal bank (KRA library), employees\' goals and the initiatives they take part in.',
            'use' => 'The start of a performance cycle. Ratings and normalisation stay separate uploads at the cycle\'s end.',
            'sheets' => [
                'Goal bank' => ['process' => 'performance.goal_bank', 'provides' => ['kra' => 'code']],
                'Goals' => ['process' => 'performance.goals', 'needs' => ['employee' => 'employee_code', 'kra' => 'kra_code']],
                'Initiatives' => ['process' => 'performance.initiatives', 'needs' => ['employee' => 'employee_code']],
            ],
        ],
        'assets' => [
            'label' => 'Asset Master', 'head' => 'asset', 'icon' => 'heroicon-o-computer-desktop',
            'about' => 'Asset types, sub types, products and the assets themselves, with who holds each one.',
            'use' => 'Bringing an asset register into PeopleOS, or a large purchase.',
            'sheets' => [
                'Asset types' => ['process' => 'asset.types', 'provides' => ['asset_type' => 'code']],
                'Asset sub types' => ['process' => 'asset.subtypes', 'provides' => ['asset_subtype' => 'code'], 'needs' => ['asset_type' => 'type_code']],
                'Products' => ['process' => 'asset.products', 'provides' => ['asset_product' => 'name'], 'needs' => ['asset_type' => 'type_code', 'asset_subtype' => 'subtype_code']],
                'Assets' => ['process' => 'asset.assets', 'needs' => ['asset_type' => 'type_code', 'asset_subtype' => 'subtype_code', 'asset_product' => 'product', 'employee' => 'custodian_code']],
            ],
        ],
        'accounting' => [
            'label' => 'Accounting Codes (JV)', 'head' => 'integration', 'icon' => 'heroicon-o-link',
            'about' => 'The ledger codes of companies, branches, departments and every other master, and of pay components, for the payroll journal voucher.',
            'use' => 'Connecting payroll to your accounting system.',
            'sheets' => [
                'Companies' => ['process' => 'integration.jv_company'],
                'Branches' => ['process' => 'integration.jv_branch'],
                'Sub branches' => ['process' => 'integration.jv_sub_branch'],
                'Regions' => ['process' => 'integration.jv_region'],
                'Business units' => ['process' => 'integration.jv_business_unit'],
                'Departments' => ['process' => 'integration.jv_department'],
                'Sub departments' => ['process' => 'integration.jv_sub_department'],
                'Designations' => ['process' => 'integration.jv_designation'],
                'Grades' => ['process' => 'integration.jv_grade'],
                'Levels' => ['process' => 'integration.jv_level'],
                'Dimension values' => ['process' => 'integration.jv_dimension'],
                'Employees' => ['process' => 'integration.jv_employee'],
                'Pay components' => ['process' => 'integration.jv_pay_component'],
                'Pay component payables' => ['process' => 'integration.jv_pay_component_payable'],
            ],
        ],
    ];

    /** The worksheets of the template that hold guidance, never data. */
    public const GUIDE_SHEETS = ['Read me', 'Columns'];

    /** @return array<string, mixed>|null the workbook with its key */
    public static function find(?string $key): ?array
    {
        return $key !== null && isset(self::WORKBOOKS[$key]) ? ['key' => $key] + self::WORKBOOKS[$key] : null;
    }

    public static function get(string $key): array
    {
        return self::find($key) ?? throw new RuntimeException("There is no master upload '{$key}'.");
    }

    public static function type(string $key): string
    {
        return self::TYPE_PREFIX.$key;
    }

    public static function keyOf(string $type): ?string
    {
        if (! str_starts_with($type, self::TYPE_PREFIX)) {
            return null;
        }
        $key = substr($type, strlen(self::TYPE_PREFIX));

        return isset(self::WORKBOOKS[$key]) ? $key : null;
    }

    public static function isType(string $type): bool
    {
        return self::keyOf($type) !== null;
    }

    /** @return array<string, string> workbook type => label */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::WORKBOOKS as $key => $workbook) {
            $labels[self::type($key)] = $workbook['label'].' (master upload)';
        }

        return $labels;
    }

    /**
     * The worksheets of a workbook with what each one is: its process (ImportCatalogue entry) and upload type.
     *
     * @return array<string, array{title: string, process: array<string, mixed>, type: string, provides: array<string, string>, needs: array<string, list<string>>}>
     */
    public static function sheets(string $key): array
    {
        $out = [];
        foreach (self::get($key)['sheets'] as $title => $sheet) {
            $process = ImportCatalogue::find($sheet['process']) ?? throw new RuntimeException("Worksheet '{$title}' names an unknown process '{$sheet['process']}'.");
            $out[$title] = [
                'title' => $title, 'process' => $process, 'type' => $process['type'],
                'provides' => $sheet['provides'] ?? [],
                'needs' => array_map(fn ($fields) => (array) $fields, $sheet['needs'] ?? []),
            ];
        }

        return $out;
    }

    /** Whether this person may use the workbook: may make uploads and run at least one of its worksheets. */
    public static function allowed(?User $user, string $key): bool
    {
        if ($user === null || ! isset(self::WORKBOOKS[$key]) || ! $user->hasPermission('employee.import')) {
            return false;
        }
        foreach (self::sheets($key) as $sheet) {
            if (ImportCatalogue::built($sheet['process']) && ImportTypes::allowed($user, $sheet['type'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The worksheets this person may fill in (built, and they may run that upload).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sheetsFor(?User $user, string $key): array
    {
        return array_filter(self::sheets($key), fn (array $sheet) => ImportCatalogue::built($sheet['process']) && ImportTypes::allowed($user, $sheet['type']));
    }

    /** @return list<string> the workbooks this person may use */
    public static function visible(?User $user): array
    {
        return array_values(array_filter(array_keys(self::WORKBOOKS), fn (string $key) => self::allowed($user, $key)));
    }

    /** The kinds whose model is this class (several kinds can share one, e.g. states and districts are both places). */
    public static function kindsOf(string $class): array
    {
        return array_keys(array_filter(self::KINDS, fn (array $kind) => $kind[1] === $class));
    }
}
