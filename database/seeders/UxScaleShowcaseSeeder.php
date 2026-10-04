<?php

namespace Database\Seeders;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Team;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * UX.15.19 / UX.15.20: a synthetic enterprise population for validating the experience at 100, 1,000 and
 * 10,000+ employees. Growth keeps a believable shape: a six-level hierarchy across two dozen departments,
 * every relationship type, promotions and transfers this month and last, joiners, completed exits, notice
 * periods, probation (some overdue), suspension, leave today and pending leave. The hard cases are added
 * once: a manager with 120+ reports and 300 pending requests, a flat team of 450, a 300-entry timeline,
 * very long names, a long department name, departments with no one in them, people with no department or
 * manager, and no profile photographs anywhere.
 *
 * Test data only: bulk inserts (no domain events, no audit rows), never production logic. It refuses
 * anything but a disposable *_showcase database and builds on UxShowcaseSeeder's demo tenant and personas.
 * Every person is invented; every reason and description says "UX scale seed (synthetic)".
 *
 *   UX_SCALE_EMPLOYEES=1000 php artisan db:seed --class=UxScaleShowcaseSeeder   grow the tenant to 1,000
 *   UX_SCALE_EDGE=1 php artisan db:seed --class=UxScaleShowcaseSeeder           add the hard cases (once)
 */
class UxScaleShowcaseSeeder extends Seeder
{
    public const TAG = 'UX scale seed (synthetic)';

    private const FANOUT = 8;

    private const DEPARTMENTS = [
        'UXS-PLAT' => 'Platform Engineering', 'UXS-DATA' => 'Data and Analytics', 'UXS-CS' => 'Customer Success', 'UXS-SALE' => 'Enterprise Sales',
        'UXS-MKT' => 'Marketing', 'UXS-OPS' => 'Operations', 'UXS-SCM' => 'Supply Chain', 'UXS-LEGAL' => 'Legal and Compliance',
        'UXS-SEC' => 'Information Security', 'UXS-QA' => 'Quality Engineering', 'UXS-RND' => 'Research', 'UXS-PM' => 'Product Management',
        'UXS-PROC' => 'Procurement', 'UXS-SUP' => 'Customer Support', 'UXS-IA' => 'Internal Audit', 'UXS-TRSY' => 'Treasury',
        'UXS-LND' => 'Learning and Development', 'UXS-COMMS' => 'Corporate Communications', 'UXS-PEOPLE' => 'People Operations',
    ];

    private const ENGINEERING = ['ENG', 'UXS-PLAT', 'UXS-DATA', 'UXS-SEC', 'UXS-QA', 'UXS-RND'];

    private const FIRST = ['Aarav', 'Aditi', 'Akash', 'Ananya', 'Arjun', 'Bhavna', 'Chetan', 'Deepa', 'Farhan', 'Gauri', 'Harsh', 'Ishita', 'Jaya', 'Kabir',
        'Lakshmi', 'Manish', 'Naina', 'Omkar', 'Pooja', 'Rehan', 'Sanya', 'Tanvi', 'Uday', 'Varun', 'Yamini', 'Zubin', 'Asha', 'Bilal', 'Charu', 'Dhruv',
        'Esha', 'Gautam', 'Hina', 'Irfan', 'Jatin', 'Kriti', 'Lavanya', 'Mohit', 'Nikhil', 'Parul', 'Rachel', 'Samuel', 'Tara', 'Vikram', 'Wei', 'Yusuf',
        'Ayesha', 'Daniel', 'Elena', 'Kenji', 'Leila', 'Mateo', 'Nadia', 'Oliver', 'Priyanka', 'Ritika', 'Sunil', 'Tejas', 'Uma', 'Veda'];

    private const LAST = ['Agarwal', 'Bansal', 'Chopra', 'Desai', 'Fernandes', 'Ghosh', 'Hegde', 'Iyer', 'Jain', 'Kulkarni', 'Lobo', 'Mathur', 'Nair', 'Oberoi',
        'Patel', 'Qureshi', 'Rao', 'Saxena', 'Thakur', 'Upadhyay', 'Varma', 'Wadhwa', 'Yadav', 'Zaidi', 'Banerjee', 'Chatterjee', 'D’Souza', 'Gill', 'Kapoor',
        'Menon', 'Mukherjee', 'Naidu', 'Pillai', 'Reddy', 'Sethi', 'Singh', 'Srinivasan', 'Tiwari', 'Venkatesh', 'Williams', 'Chen', 'Haddad', 'Kim', 'Lopez',
        'Morgan', 'Novak', 'Okafor', 'Petrov', 'Rossi', 'Sato', 'Tan', 'Ahmed', 'Bhatt', 'Dutta', 'Joshi', 'Krishnan', 'Malik', 'Shetty', 'Sinha', 'Vohra'];

    private const LONG_NAMES = [
        ['Venkata Satya Narasimha Lakshmi Prasanna', 'Subrahmanyam-Bhattacharjee Raghunathan'], ['Alexandria Maximiliana Josephine', 'Featherstonehaugh-Wolfeschlegelsteinhausen'],
        ['Thiruvenkataswamy', 'Kuppuswamy Ramalingam Chettiar'], ['Oluwaseun Adebayo Chukwuemeka', 'Okonkwo-Nwachukwu'],
        ['María de los Ángeles Guadalupe', 'Fernández de Córdoba y Montenegro'], ['Siddhivinayak Purushottam', 'Deshpande-Kulkarni-Joshi'],
        ['Nguyễn Thị Minh Khai', 'Trần-Phạm Hoàng'], ['Rajarajeshwari Annapurna Kamakshi', 'Venkataraghavan Iyengar'],
        ['Jean-Baptiste Emmanuel Théodore', 'de La Rochefoucauld-Montmorency'], ['Bartholomew', 'Ó Súilleabháin-MacGillicuddy'],
    ];

    private const RELATION_TYPES = ['dotted', 'functional', 'project', 'mentor', 'buddy', 'hrbp', 'secondary'];

    private const RELATION_TITLES = ['dotted' => 'Dotted line assigned', 'functional' => 'Functional manager assigned', 'project' => 'Project manager assigned',
        'mentor' => 'Mentor assigned', 'buddy' => 'Buddy assigned', 'hrbp' => 'HR business partner assigned', 'secondary' => 'Secondary manager assigned'];

    private int $tenantId;

    private int $companyId;

    private int $ceoId;

    private CarbonImmutable $today;

    private string $stamp;

    /** @var list<int> department ids in tree-root order (base departments first, then the synthetic ones) */
    private array $roots = [];

    /** @var array<int, string> department id => code */
    private array $deptCodes = [];

    /** @var array<string, array{id: int, level: int}> */
    private array $designations = [];

    /** @var array<int, int> level number => level id */
    private array $levels = [];

    /** @var list<int> */
    private array $locations = [];

    /** @var list<int> */
    private array $leaveTypes = [];

    public function run(TenantContext $tenants): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (app()->isProduction() || (! app()->environment('testing') && ! str_ends_with($database, '_showcase'))) {
            throw new RuntimeException("UxScaleShowcaseSeeder only runs on a disposable *_showcase database (current: {$database}).");
        }
        $demo = fn () => $tenants->bypass(fn () => Tenant::query()->where('slug', 'demo')->first());
        if ($demo() === null || ! $tenants->runAs($demo(), fn () => Employee::query()->where('work_email', 'amit.verma@demo.local')->exists())) {
            $this->call(UxShowcaseSeeder::class);
        }
        $target = (int) (getenv('UX_SCALE_EMPLOYEES') ?: 0);
        $edge = filter_var(getenv('UX_SCALE_EDGE') ?: false, FILTER_VALIDATE_BOOL);

        $tenants->runAs($demo(), function () use ($target, $edge) {
            $this->prepare();
            if ($target > 0) {
                $this->grow($target);
            }
            if ($edge) {
                $this->hardCases();
            }
        });
    }

    private function prepare(): void
    {
        $this->tenantId = (int) app(TenantContext::class)->id();
        $this->today = CarbonImmutable::now()->startOfDay();
        $this->stamp = now()->toDateTimeString();
        $this->companyId = (int) Company::query()->where('code', 'DEMO-TECH')->value('id');
        $this->ceoId = (int) Employee::query()->where('work_email', 'meera.iyer@demo.local')->value('id');

        foreach (self::DEPARTMENTS as $code => $name) {
            Department::query()->firstOrCreate(['code' => $code], ['company_id' => $this->companyId, 'name' => $name, 'status' => 'active', 'description' => self::TAG]);
        }
        $departments = Department::query()->where('code', 'not like', 'UXE-%')->orderBy('id')->pluck('code', 'id')->all();
        $this->roots = array_keys($departments);
        $this->deptCodes = Department::query()->pluck('code', 'id')->all();

        foreach (['MUM' => 'Mumbai', 'HYD' => 'Hyderabad', 'PUN' => 'Pune'] as $code => $name) {
            Location::query()->firstOrCreate(['code' => $code], ['company_id' => $this->companyId, 'name' => $name, 'type' => 'office', 'country_code' => 'IN', 'status' => 'active']);
        }
        $this->locations = Location::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->levels = Level::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [(int) substr((string) $code, 1) => (int) $id])->all();
        foreach (['UXS-DIR' => ['Director', 5], 'UXS-SM' => ['Senior Manager', 4], 'UXS-TL' => ['Team Lead', 3], 'UXS-SASC' => ['Senior Associate', 3],
            'UXS-ASC' => ['Associate', 2], 'UXS-AGT' => ['Customer Support Agent', 1]] as $code => [$name, $level]) {
            Designation::query()->firstOrCreate(['code' => $code], ['name' => $name, 'level_id' => $this->levels[$level]]);
        }
        $levelOf = array_flip($this->levels);
        $this->designations = Designation::query()->get(['id', 'code', 'level_id'])
            ->mapWithKeys(fn (Designation $d) => [$d->code => ['id' => (int) $d->id, 'level' => (int) ($levelOf[$d->level_id] ?? 2)]])->all();
        // A realistically configured manager: scoped to a team of their own, so (ADR-0004) they reach their
        // reporting line and nobody else. The demo personas otherwise have no scope rows, which by the
        // documented rule means tenant-wide.
        $squad = Team::query()->firstOrCreate(['code' => 'UXS-CORE'], ['company_id' => $this->companyId, 'name' => 'Core Platform squad', 'status' => 'active', 'description' => self::TAG]);
        if ($manager = User::query()->where('email', 'amit.verma@demo.local')->first()) {
            UserAccessScope::query()->firstOrCreate(['user_id' => $manager->id, 'dimension' => 'team', 'scope_id' => $squad->id]);
        }
        $this->leaveTypes = LeaveType::query()->whereIn('code', ['EL', 'CL', 'SL'])->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Grow the tenant to $target employees with the deterministic synthetic hierarchy (UXS000001 …). */
    private function grow(int $target): void
    {
        $have = Employee::query()->count();
        $next = Employee::query()->where('employee_code', 'like', 'UXS%')->count() + 1;
        while ($have < $target) {
            $size = min(500, $target - $have);
            $this->write(array_map(fn (int $g) => $this->spec($g), range($next, $next + $size - 1)));
            $next += $size;
            $have += $size;
        }
    }

    /** One synthetic employee, a pure function of its index so later growth extends the same tree. */
    private function spec(int $g): array
    {
        $k = $g - 1;
        $depth = $this->depth($k);
        $root = $k;
        while (($parent = $this->parent($root)) !== null) {
            $root = $parent;
        }
        $deptId = $this->roots[$root];
        $state = $this->state($g, $depth);
        $engineering = in_array($this->deptCodes[$deptId] ?? '', self::ENGINEERING, true);
        $designation = match (true) {
            $depth === 1 => 'UXS-DIR', $depth === 2 => 'UXS-SM', $depth === 3 => 'UXS-TL',
            default => $engineering ? 'SE' : 'UXS-ASC',
        };

        // Line manager: the nearest ancestor still employed (an exited or not-yet-joined manager's people move up a level).
        $manager = $this->parent($k);
        while ($manager !== null && in_array($this->state($manager + 1, $this->depth($manager)), ['exited', 'preboarding'], true)) {
            $manager = $this->parent($manager);
        }

        $h = ($g * 40503) % 1000;
        $joined = match ($state) {
            'joined' => $this->today->startOfMonth()->addDays($g % $this->today->day),
            'preboarding' => $this->today->addDays(10 + $g % 30),
            'probation' => $this->today->subDays(60 + $g % 160),
            default => CarbonImmutable::parse(['2012-01-09', '2014-03-03', '2016-06-06', '2017-08-07'][min($depth, 4) - 1])->addDays(($g * 7919) % [1500, 1800, 2400, 3000][min($depth, 4) - 1]),
        };
        if ($joined->gt($this->today->subYear()) && ! in_array($state, ['joined', 'preboarding', 'probation'], true)) {
            $joined = $joined->subYears(2);
        }
        $exit = $state === 'exited' ? ($h < 300 ? $this->today->startOfMonth()->addDays($g % $this->today->day) : $this->today->subDays(30 + $g % 300)) : null;

        $movement = null;
        if (! in_array($state, ['joined', 'preboarding', 'probation', 'exited'], true) && $joined->lt($this->today->subYear())) {
            $promote = ['SE' => 'SSE', 'UXS-ASC' => 'UXS-SASC', 'UXS-TL' => 'UXS-SM'];
            $movement = match (true) {
                $h < 20 => ['promotion', $this->today->startOfMonth()->addDays($g % $this->today->day)],
                $h < 35 => ['transfer', $this->today->startOfMonth()->addDays($g % $this->today->day)],
                $h < 50 => ['promotion', $this->today->subMonthNoOverflow()->startOfMonth()->addDays($g % 27)],
                $h < 60 => ['transfer', $this->today->subMonthNoOverflow()->startOfMonth()->addDays($g % 27)],
                $h < 200 => ['promotion', $this->today->subMonths(3 + $g % 28)],
                default => null,
            };
            if ($movement !== null) {
                $movement[] = $movement[0] === 'promotion' ? ($promote[$designation] ?? $designation) : $designation;
            }
        }
        $location = $this->locations[$g % count($this->locations)];
        $relations = [];
        if ((($g * 1103515245) % 1000) < 35 && $g > 40) {
            $other = 1 + (($g * 7919) % ($g - 1));
            if ($other - 1 !== $this->parent($k) && ! in_array($this->state($other, $this->depth($other - 1)), ['exited', 'preboarding'], true)) {
                $relations[] = [self::RELATION_TYPES[$g % count(self::RELATION_TYPES)], ['code' => $this->code('UXS', $other)], $this->today->subDays(30 + $g % 400)];
            }
        }

        return [
            'code' => $this->code('UXS', $g),
            'first' => self::FIRST[$g % count(self::FIRST)],
            'last' => self::LAST[($g * 7) % count(self::LAST)],
            'dept' => $deptId, 'designation' => $designation, 'location' => $location, 'state' => $state, 'joined' => $joined, 'exit' => $exit,
            'probation_end' => $state === 'probation' ? $joined->addDays(180) : null,
            'manager' => $manager === null ? ['id' => $this->ceoId] : ['code' => $this->code('UXS', $manager + 1)],
            'movement' => $movement, 'relations' => $relations, 'leaves' => $this->leavesFor($g, $state),
            'exit_case' => match ($state) {
                'exited' => ['completed', $exit], 'notice_period' => ['notice', $this->today->addDays(10 + $g % 50)], default => null,
            },
        ];
    }

    private function parent(int $k): ?int
    {
        $d = count($this->roots);

        return $k < $d ? null : intdiv($k - $d, self::FANOUT);
    }

    private function depth(int $k): int
    {
        $depth = 1;
        while (($k = $this->parent($k)) !== null) {
            $depth++;
        }

        return $depth;
    }

    /** Lifecycle mix; disruptive states only below the senior layers. */
    private function state(int $g, int $depth): string
    {
        $h = ($g * 2654435761) % 1000;
        if ($depth <= 2) {
            return $h % 2 === 0 ? 'confirmed' : 'active';
        }

        return match (true) {
            $h < 60 => 'probation',
            $h < 80 => 'notice_period',
            $h < 90 => 'on_leave',
            $h < 95 => 'suspended',
            $h < 125 && $depth >= 4 => 'exited',
            $h < 135 => 'joined',
            $h < 140 && $depth >= 4 => 'preboarding',
            default => $h % 2 === 0 ? 'confirmed' : 'active',
        };
    }

    /** @return list<array{0: string, 1: CarbonImmutable, 2: CarbonImmutable, 3: string, 4: CarbonImmutable}> status, from, to, reason, requested at */
    private function leavesFor(int $g, string $state): array
    {
        if (in_array($state, ['exited', 'preboarding', 'joined'], true)) {
            return [];
        }
        $h = ($g * 69069) % 1000;
        $reasons = ['Family function', 'Medical appointment', 'Personal work', 'Travel to hometown', 'Child’s school event', 'Feeling unwell'];
        $reason = $reasons[$g % count($reasons)].' ('.self::TAG.')';

        return match (true) {
            $h < 30 || $state === 'on_leave' => [['approved', $this->today->subDays($g % 3), $this->today->addDays($g % 4), $reason, $this->today->subDays(10)]],
            $h < 45 => [['pending', $this->today->addDays(3 + $g % 50), $this->today->addDays(3 + $g % 50 + $g % 3), $reason, $this->today->subDays($g % 9)]],
            $h < 55 => [['approved', $this->today->subMonthNoOverflow()->startOfMonth()->addDays($g % 25), $this->today->subMonthNoOverflow()->startOfMonth()->addDays($g % 25 + 1), $reason, $this->today->subDays(45)]],
            default => [],
        };
    }

    private function code(string $prefix, int $n): string
    {
        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Write people, employees, positions, reporting lines, timeline, leave and exit cases for a list of specs.
     *
     * @param  list<array<string, mixed>>  $specs
     * @return array<string, int> employee code => id
     */
    private function write(array $specs): array
    {
        $t = $this->tenantId;
        $now = $this->stamp;
        $meta = json_encode(['synthetic' => true, 'source' => self::TAG]);
        $date = fn (?CarbonImmutable $d) => $d?->toDateString();

        DB::table('people')->insert(array_map(fn ($s) => ['tenant_id' => $t, 'first_name' => $s['first'], 'last_name' => $s['last'],
            'personal_email' => strtolower($s['code']).'@example.test', 'metadata' => $meta, 'created_at' => $now, 'updated_at' => $now], $specs));
        $people = DB::table('people')->where('tenant_id', $t)->whereIn('personal_email', array_map(fn ($s) => strtolower($s['code']).'@example.test', $specs))->pluck('id', 'personal_email');

        DB::table('employees')->insert(array_map(fn ($s) => [
            'tenant_id' => $t, 'person_id' => $people[strtolower($s['code']).'@example.test'], 'employee_code' => $s['code'], 'lifecycle_state' => $s['state'], 'source' => 'seed',
            'joining_date' => $s['state'] === 'preboarding' ? null : $date($s['joined']), 'expected_joining_date' => $s['state'] === 'preboarding' ? $date($s['joined']) : null,
            'probation_end_date' => $date($s['probation_end']), 'confirmation_date' => $s['state'] === 'confirmed' ? $date($s['joined']->addDays(180)) : null,
            'exit_date' => $date($s['exit']), 'work_email' => strtolower($s['code']).'@scale.demo.local', 'metadata' => $meta, 'created_at' => $now, 'updated_at' => $now,
        ], $specs));
        $codes = array_column($specs, 'code');
        $refs = collect($specs)->flatMap(fn ($s) => array_filter([$s['manager']['code'] ?? null, ...array_map(fn ($r) => $r[1]['code'] ?? null, $s['relations'])]))->unique()->values()->all();
        $ids = DB::table('employees')->where('tenant_id', $t)->whereIn('employee_code', [...$codes, ...$refs])->pluck('id', 'employee_code')->map(fn ($id) => (int) $id)->all();
        $ref = fn (?array $r) => $r === null ? null : ($r['id'] ?? $ids[$r['code']] ?? null);

        $positions = $relations = $timeline = $leaves = $exits = [];
        foreach ($specs as $s) {
            $id = $ids[$s['code']];
            $designation = $this->designations[$s['designation']];
            $moved = $s['movement'];
            $positions[] = ['tenant_id' => $t, 'employee_id' => $id, 'company_id' => $this->companyId, 'location_id' => $s['location'], 'department_id' => $s['dept'],
                'designation_id' => $designation['id'], 'level_id' => $this->levels[$designation['level']] ?? null, 'fte' => 1, 'change_type' => 'hire',
                'effective_from' => $date($s['joined']), 'effective_to' => $moved ? $date($moved[1]->subDay()) : null, 'reason' => self::TAG, 'created_at' => $now, 'updated_at' => $now];
            $timeline[] = $this->entry($id, $s['joined'], 'lifecycle', 'Joined company');
            $timeline[] = $this->entry($id, $s['joined'], 'position', 'Position assigned');
            if ($moved) {
                $to = $this->designations[$moved[2]];
                $positions[] = ['tenant_id' => $t, 'employee_id' => $id, 'company_id' => $this->companyId,
                    'location_id' => $moved[0] === 'transfer' ? $this->locations[(array_search($s['location'], $this->locations, true) + 1) % count($this->locations)] : $s['location'],
                    'department_id' => $s['dept'], 'designation_id' => $to['id'], 'level_id' => $this->levels[$to['level']] ?? null, 'fte' => 1, 'change_type' => $moved[0],
                    'effective_from' => $date($moved[1]), 'effective_to' => null, 'reason' => self::TAG, 'created_at' => $now, 'updated_at' => $now];
                $timeline[] = $this->entry($id, $moved[1], 'position', $moved[0] === 'promotion' ? 'Promotion' : 'Transfer');
            }
            if (($manager = $ref($s['manager'])) !== null) {
                $relations[] = ['tenant_id' => $t, 'employee_id' => $id, 'manager_id' => $manager, 'type' => 'line', 'is_primary' => true, 'effective_from' => $date($s['joined']),
                    'effective_to' => $date($s['exit']), 'reason' => self::TAG, 'created_at' => $now, 'updated_at' => $now];
                $timeline[] = $this->entry($id, $s['joined'], 'reporting', 'Line manager assigned');
            }
            foreach ($s['relations'] as [$type, $other, $from]) {
                if (($otherId = $ref($other)) !== null && $otherId !== $id) {
                    $relations[] = ['tenant_id' => $t, 'employee_id' => $id, 'manager_id' => $otherId, 'type' => $type, 'is_primary' => false, 'effective_from' => $date($from),
                        'effective_to' => null, 'reason' => self::TAG, 'created_at' => $now, 'updated_at' => $now];
                    $timeline[] = $this->entry($id, $from, 'reporting', self::RELATION_TITLES[$type]);
                }
            }
            foreach ($s['leaves'] as $i => [$status, $from, $to, $reason, $at]) {
                $days = $from->diffInDays($to) + 1;
                $leaves[] = ['tenant_id' => $t, 'employee_id' => $id, 'leave_type_id' => $this->leaveTypes[($id + $i) % count($this->leaveTypes)], 'from_date' => $date($from), 'to_date' => $date($to),
                    'from_session' => 'full', 'to_session' => 'full', 'days' => $days, 'reason' => $reason, 'status' => $status,
                    'reviewed_at' => $status === 'pending' ? null : $at->addDay()->toDateTimeString(), 'cancel_requested_at' => $status === 'cancel_requested' ? $now : null,
                    'cancel_reason' => $status === 'cancel_requested' ? 'Plans changed ('.self::TAG.')' : null, 'created_at' => $at->toDateTimeString(), 'updated_at' => $now];
            }
            if ($s['exit_case'] !== null) {
                [$status, $lwd] = $s['exit_case'];
                $exits[] = ['tenant_id' => $t, 'number' => 'EXIT-'.$s['code'], 'employee_id' => $id, 'type' => 'resignation', 'reason' => self::TAG, 'status' => $status,
                    'initiated_on' => $date($lwd->subDays(30)), 'resignation_date' => $date($lwd->subDays(30)), 'notice_days' => 30, 'notice_end_date' => $date($lwd), 'last_working_day' => $date($lwd),
                    'manager_id' => $manager, 'is_rehire_eligible' => true, 'completed_at' => $status === 'completed' ? $lwd->toDateTimeString() : null, 'created_at' => $now, 'updated_at' => $now];
                if ($status === 'completed') {
                    $timeline[] = $this->entry($id, $lwd, 'lifecycle', 'Exited company');
                }
            }
        }
        foreach (['employee_positions' => $positions, 'reporting_relationships' => $relations, 'employee_timeline_entries' => $timeline, 'leave_requests' => $leaves, 'exit_cases' => $exits] as $table => $rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }

        return array_intersect_key($ids, array_flip($codes));
    }

    private function entry(int $employeeId, CarbonImmutable $on, string $category, string $title, ?string $description = null): array
    {
        return ['tenant_id' => $this->tenantId, 'employee_id' => $employeeId, 'occurred_on' => $on->toDateString(), 'category' => $category, 'title' => $title,
            'description' => $description, 'metadata' => null, 'created_at' => $this->stamp, 'updated_at' => $this->stamp];
    }

    /** The conditions clean demo data never has. Added once (marker: department UXE-CVO). */
    private function hardCases(): void
    {
        if (Department::query()->where('code', 'UXE-CVO')->exists()) {
            return;
        }
        $persona = fn (string $email) => (int) Employee::query()->where('work_email', $email)->value('id');
        $amit = $persona('amit.verma@demo.local');
        $priya = $persona('priya.nair@demo.local');
        $dept = fn (string $code, string $name) => (int) Department::query()->firstOrCreate(['code' => $code], ['company_id' => $this->companyId, 'name' => $name, 'status' => 'active', 'description' => self::TAG])->id;
        $eng = (int) Department::query()->where('code', 'ENG')->value('id');
        $ops = (int) Department::query()->where('code', 'UXS-OPS')->value('id');
        $dept('UXE-CVO', 'Corporate Venture Office');
        $dept('UXE-LAT', 'New Markets — Latin America');
        $regulatory = $dept('UXE-RAQ', 'Regulatory Affairs, Quality Assurance and Clinical Compliance Operations — Asia Pacific');
        $n = 0;
        $base = function (array $s) use (&$n): array {
            return $s + ['location' => $this->locations[0], 'state' => 'active', 'joined' => $this->today->subYears(2), 'exit' => null, 'probation_end' => null,
                'movement' => null, 'relations' => [], 'leaves' => [], 'exit_case' => null, 'code' => $this->code('UXE', ++$n)];
        };

        // 1. A manager with 120 more direct reports (ten with very long names) and 300 pending requests, some already started.
        $team = [];
        for ($i = 0; $i < 120; $i++) {
            [$first, $last] = self::LONG_NAMES[$i] ?? [self::FIRST[($i * 11) % count(self::FIRST)], self::LAST[($i * 13) % count(self::LAST)]];
            $team[] = $base(['first' => $first, 'last' => $last, 'dept' => $eng, 'designation' => 'SE', 'manager' => ['id' => $amit], 'joined' => $this->today->subDays(400 + $i * 9)]);
        }
        // As long as the leave form allows (255 characters).
        $long = 'Travelling to my grandmother’s village for the annual temple festival; reachable by phone in the evenings only. Handover notes for the release checklist and on-call rota are in the team channel and Rohan covers stand-up. (UX scale seed, synthetic)';
        for ($i = 0; $i < 300; $i++) {
            $j = $i % 120;
            $round = intdiv($i, 120);
            [$status, $from, $to] = match (true) {
                $i < 15 => ['pending', $this->today->subDays(1 + $i % 3), $this->today->addDay()],
                $i >= 290 => ['cancel_requested', $this->today->addDays(20 + $i % 9), $this->today->addDays(21 + $i % 9)],
                default => ['pending', $this->today->addDays(4 + $round * 30 + $j % 26), $this->today->addDays(4 + $round * 30 + $j % 26 + ($i % 4 === 0 ? 2 : 0))],
            };
            $team[$j]['leaves'][] = [$status, $from, $to, $i === 20 ? $long : 'Personal leave ('.self::TAG.')', $this->today->subDays($i % 12)];
        }
        // Unusual combinations inside the same team.
        $team[10]['state'] = 'probation';
        $team[10]['probation_end'] = $this->today->subDays(40);
        $team[10]['joined'] = $this->today->subDays(220);
        $team[11]['state'] = 'suspended';
        $team[11]['leaves'][] = ['approved', $this->today->subDay(), $this->today->addDays(2), 'Approved before suspension ('.self::TAG.')', $this->today->subDays(20)];
        $team[12]['state'] = 'on_leave';
        $team[13]['relations'] = [['dotted', ['id' => $persona('sara.thomas@demo.local')], $this->today->subDays(90)], ['project', ['id' => $persona('ravi.kumar@demo.local')], $this->today->subDays(60)],
            ['mentor', ['id' => $persona('neha.kapoor@demo.local')], $this->today->subDays(30)]];
        $team[14]['state'] = 'notice_period';
        $team[14]['designation'] = 'UXS-TL';
        $team[14]['exit_case'] = ['notice', $this->today->addDays(21)];
        $ids = $this->write($team);
        $lead = $ids[$team[14]['code']];
        $this->write(array_map(fn ($i) => $base(['first' => self::FIRST[($i * 17) % 60], 'last' => self::LAST[($i * 19) % 60], 'dept' => $eng, 'designation' => 'SE', 'manager' => ['id' => $lead]]), range(1, 5)));

        // 2. A flat team of 450 under one director (a contact centre).
        $director = $this->write([$base(['first' => 'Rukmini', 'last' => 'Sundararajan', 'dept' => $ops, 'designation' => 'UXS-DIR', 'manager' => ['id' => $this->ceoId], 'joined' => $this->today->subYears(9)])]);
        $directorId = reset($director);
        foreach (array_chunk(range(1, 450), 150) as $chunk) {
            $this->write(array_map(fn ($i) => $base(['first' => self::FIRST[($i * 23) % 60], 'last' => self::LAST[($i * 29) % 60], 'dept' => $ops, 'designation' => 'UXS-AGT',
                'location' => $this->locations[2 % count($this->locations)], 'manager' => ['id' => $directorId], 'joined' => $this->today->subDays(30 + ($i * 37) % 1400)]), $chunk));
        }

        // 3. A long department name, led by someone with a long name; and people with no department or manager.
        $raq = $this->write([$base(['first' => self::LONG_NAMES[0][0], 'last' => self::LONG_NAMES[1][1], 'dept' => $regulatory, 'designation' => 'UXS-DIR', 'manager' => ['id' => $this->ceoId]])]);
        $this->write(array_map(fn ($i) => $base(['first' => self::FIRST[($i * 31) % 60], 'last' => self::LAST[($i * 37) % 60], 'dept' => $regulatory, 'designation' => 'UXS-ASC', 'manager' => ['id' => reset($raq)]]), range(1, 6)));
        $this->write([$base(['first' => 'Unassigned', 'last' => 'Contractor-Conversion', 'dept' => null, 'designation' => 'UXS-ASC', 'manager' => null]),
            $base(['first' => 'Imran', 'last' => 'Siddiqui', 'dept' => null, 'designation' => 'UXS-ASC', 'manager' => null, 'state' => 'probation', 'probation_end' => $this->today->addDays(5), 'joined' => $this->today->subDays(175)])]);

        // 4. A long timeline (300 entries) and a long document name for one person.
        $kinds = [['leave', 'Casual leave approved: 1.00 day(s)'], ['service_request', 'HR request raised: Letters & certificates'], ['assets', 'Asset assigned: Dell Latitude 5540'],
            ['learning', 'Course completed: Information security basics'], ['document', 'Document verified: Address proof'], ['performance', 'Check-in recorded'],
            ['communication', 'Policy acknowledged: Code of conduct'], ['reporting', 'Mentor assigned']];
        $joined = CarbonImmutable::parse((string) Employee::query()->whereKey($priya)->value('joining_date'));
        $span = max(1, $joined->diffInDays($this->today));
        $rows = array_map(fn ($i) => $this->entry($priya, $joined->addDays(intdiv($i * $span, 300)), $kinds[$i % count($kinds)][0], $kinds[$i % count($kinds)][1].' ('.self::TAG.')',
            $i === 150 ? str_repeat('A long synthetic description that keeps going to test wrapping and truncation in the journey view. ', 6) : null), range(0, 299));
        DB::table('employee_timeline_entries')->insert($rows);
        DB::table('employee_documents')->insert(['tenant_id' => $this->tenantId, 'employee_id' => $priya, 'title' => 'Statutory declaration — Provident Fund nomination, revised after change of address and addition of dependants (Form 2, signed and witnessed)',
            'disk' => 'local', 'path' => 'synthetic/ux-scale/form-2.pdf', 'original_name' => 'EPF_Form2_Nomination_Declaration_Revised_Address_Change_Dependants_Added_Signed_Witnessed_FINAL_v3.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 245760, 'status' => 'pending', 'created_at' => $this->stamp, 'updated_at' => $this->stamp]);
    }
}
