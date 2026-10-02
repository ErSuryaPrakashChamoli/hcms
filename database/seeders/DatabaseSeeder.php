<?php

namespace Database\Seeders;

use App\Domain\Analytics\Services\AnalyticsDefaults;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Services\AssetDefaults;
use App\Domain\Assets\Services\Assets;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Models\WorkScheduleRule;
use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Services\Communications;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Services\Blueprints;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\ExitInterviews;
use App\Domain\Exit\Services\Exits;
use App\Domain\Exit\Services\FinalSettlements;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningAssignment;
use App\Domain\Learning\Models\TrainingSession;
use App\Domain\Learning\Services\Learning;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Letters\Services\LetterDefaults;
use App\Domain\Letters\Services\Letters;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Performance\Models\CareerPath;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\PerformanceDefaults;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Models\Tenant;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Domain\ServiceDesk\Services\ServiceDeskDefaults;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\Workflows;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(PermissionRegistry $permissions, ProvisionTenantAction $provisioner, TenantContext $tenants): void
    {
        $permissions->sync();

        User::query()->firstOrCreate(
            ['email' => 'platform@markedge.local'],
            [
                'name' => 'Markedge Platform Admin',
                'password' => 'password',
                'is_platform_admin' => true,
            ],
        );

        $tenant = $tenants->bypass(fn () => Tenant::query()->where('slug', 'demo')->first())
            ?? $provisioner->handle(
                ['name' => 'Demo Group', 'slug' => 'demo'],
                ['name' => 'Demo Admin', 'email' => 'admin@fynnedge.com', 'password' => 'Fynnone@2029'],
                reason: 'Development seed',
            );

        $tenants->runAs($tenant, function () use ($provisioner) {
            $provisioner->seedOrganisationDefaults();

            Company::query()->firstOrCreate(
                ['code' => 'DEMO-TECH'],
                ['name' => 'Demo Technologies', 'legal_name' => 'Demo Technologies Pvt Ltd', 'effective_from' => '2025-04-01'],
            );
            Company::query()->firstOrCreate(
                ['code' => 'DEMO-SVC'],
                ['name' => 'Demo Services', 'legal_name' => 'Demo Services Pvt Ltd', 'effective_from' => '2025-04-01'],
            );

            $tree = app(OrganisationTree::class);

            // Idempotent placement: reuse a unit with the same code, attach it if it is not in the tree yet.
            $place = function (string $type, array $attributes, ?OrganisationNode $parent) use ($tree): OrganisationNode {
                $unit = $tree->modelForType($type)::query()->where('code', $attributes['code'])->first();

                if ($unit === null) {
                    return $tree->createUnit($type, $attributes, $parent, 'Development seed');
                }

                return $unit->organisationNode()->first() ?? $tree->attach($unit, $parent, 'Development seed');
            };

            $root = $place('company', ['code' => 'DEMO-TECH'], null);
            $delhi = $place('location', ['name' => 'Delhi', 'code' => 'DEL', 'city' => 'New Delhi'], $root);
            $place('location', ['name' => 'Bangalore', 'code' => 'BLR', 'city' => 'Bengaluru'], $root);
            $product = $place('business_unit', ['name' => 'Product', 'code' => 'PROD'], $root);
            $eng = $place('department', ['name' => 'Engineering', 'code' => 'ENG'], $product);
            $place('team', ['name' => 'Platform', 'code' => 'PLAT'], $eng);
            $place('team', ['name' => 'Mobile', 'code' => 'MOB'], $eng);
            $place('department', ['name' => 'Design', 'code' => 'DSN'], $product);
            $place('department', ['name' => 'Finance', 'code' => 'FIN'], $root);
            $place('department', ['name' => 'Facilities', 'code' => 'FAC'], $delhi);

            $services = $place('company', ['code' => 'DEMO-SVC'], null);
            $place('department', ['name' => 'Consulting', 'code' => 'CONS'], $services);

            $this->seedEmployees();

            $leavePolicy = Policy::query()->where('code', 'IT_LEAVE')->first();

            if (Policy::query()->doesntExist() || ($leavePolicy && ! isset($leavePolicy->versionEffectiveOn()?->settings['entitlements']))) {
                app(Blueprints::class)->applyPack('it-company', 'Development seed');
            }

            $this->seedWorkflows();
            $this->seedOnboardingTemplate();
            $this->seedAttendance();
            $this->seedLeave();
            $this->seedPayroll();
            $this->seedPerformance();
            $this->seedLearningAndAssets();
            $this->seedExperience();
            $this->seedExit();
            app(AnalyticsDefaults::class)->seed();
        });
    }

    /** Letter templates, and one completed exit turned into an alumnus with an issued experience letter. */
    private function seedExit(): void
    {
        app(LetterDefaults::class)->seed();

        if (ExitCase::query()->exists()) {
            return;
        }

        $admin = User::query()->where('email', 'admin@fynnedge.com')->first();
        $vikram = Employee::query()->with('person')->where('work_email', 'vikram.singh@demo.local')->first();
        if (! $vikram || ! $vikram->lifecycle_state->isEmployed()) {
            return;
        }

        $exits = app(Exits::class);
        $case = $exits->initiate($vikram, 'resignation', 'Moving abroad', '2026-09-01', null, 30, $admin, ['knowledge_transfer_notes' => 'Finance reports handed to Anita.']);
        $exits->startClearance($case);
        foreach ($case->clearances()->get() as $stage) {
            $exits->clearStage($stage, $admin, 'Cleared during seed');
        }
        $settlements = app(FinalSettlements::class);
        $settlement = $settlements->calculate($case->refresh(), $admin);
        $settlements->approve($settlement, $admin, 'Development seed');
        app(ExitInterviews::class)->submit($case, ['reason_for_leaving' => 'relocation', 'ratings' => ['manager_experience' => 4, 'compensation' => 3, 'culture' => 5, 'workload' => 3, 'career_opportunities' => 4, 'work_environment' => 4], 'would_recommend' => true, 'would_rejoin' => true, 'suggestions' => 'Offer more remote roles.'], $admin, false);
        $exits->complete($case->refresh(), $admin);
        $letters = app(Letters::class);
        $letter = $letters->generate('experience', $vikram->refresh(), [], $admin, $case);
        $letters->approve($letter, $admin, 'Development seed');
        $letters->issue($letter->refresh(), $admin);
        $exits->createAlumni($case->refresh(), $admin, ['personal_email' => 'vikram.singh@example.test']);
    }

    /** Service desk categories, a knowledge article, a pinned announcement, and an open request. */
    private function seedExperience(): void
    {
        app(ServiceDeskDefaults::class)->seed();
        $admin = User::query()->where('email', 'admin@fynnedge.com')->first();

        if (Article::query()->doesntExist()) {
            $kb = app(KnowledgeBase::class);
            foreach ([
                ['title' => 'Leave policy', 'category' => 'leave', 'summary' => 'Entitlements, how to apply, carry forward and encashment.', 'body' => "# Leave policy\n\nEarned leave accrues monthly. Apply at least three working days ahead unless it is an emergency.\n\n## Carry forward\n\nUp to 30 days of earned leave carry into the next year.", 'requires_acknowledgement' => true, 'is_mandatory_reading' => true],
                ['title' => 'Work from home guidelines', 'category' => 'wfh', 'summary' => 'When and how to work remotely.', 'body' => "# Work from home\n\nAgree the days with your manager and mark them in attendance.", 'requires_acknowledgement' => false],
                ['title' => 'Prevention of sexual harassment (PoSH)', 'category' => 'posh', 'summary' => 'Your rights, the Internal Committee and how to raise a complaint.', 'body' => "# PoSH\n\nComplaints go to the Internal Committee within three months of the incident. Raise a grievance under the PoSH category; it is confidential.", 'requires_acknowledgement' => true, 'is_mandatory_reading' => true],
            ] as $row) {
                $kb->publish(Article::create($row + ['author_id' => $admin?->id]), $admin);
            }
        }

        if (Announcement::query()->doesntExist()) {
            $comms = app(Communications::class);
            $comms->publish(Announcement::create(['title' => 'Welcome to MY PEOPLEOS', 'type' => 'announcement', 'body' => 'Your new employee portal is live. Check in, apply leave, read your payslip and ask HR from **My Day**.', 'is_pinned' => true, 'requires_acknowledgement' => true, 'author_id' => $admin?->id]), $admin);
            $comms->publish(Announcement::create(['title' => 'Quarterly fire drill', 'type' => 'circular', 'body' => 'Register for the drill from Learning → Training sessions.', 'author_id' => $admin?->id]), $admin);
        }

        if (Ticket::query()->doesntExist()) {
            $rahul = Employee::query()->where('work_email', 'rahul.sharma@demo.local')->first();
            $letters = TicketCategory::query()->where('code', 'LETTER')->first();
            if ($rahul && $letters) {
                app(ServiceDesk::class)->open($rahul, $letters, 'Experience letter for visa', 'I need an experience letter covering my full tenure for a visa application.', 'high', $admin);
            }
        }
    }

    /** A compliance course with a quiz assigned to everyone, a classroom session, and a few assets in custody. */
    private function seedLearningAndAssets(): void
    {
        app(AssetDefaults::class)->seed();

        if (Course::query()->where('code', 'POSH')->doesntExist()) {
            $posh = Course::create(['title' => 'POSH awareness', 'code' => 'POSH', 'type' => 'elearning', 'category' => 'compliance', 'description' => 'Prevention of sexual harassment at the workplace: policy, committee, reporting.', 'duration_minutes' => 45, 'is_mandatory' => true, 'validity_months' => 12, 'attempts_allowed' => 3, 'status' => 'published']);
            $posh->modules()->create(['title' => 'The policy', 'type' => 'text', 'content' => 'Read the PoSH policy.', 'duration_minutes' => 20, 'sort_order' => 10]);
            $posh->modules()->create(['title' => 'Reporting and the ICC', 'type' => 'text', 'content' => 'How to raise a complaint.', 'duration_minutes' => 15, 'sort_order' => 20]);
            $posh->assessments()->create(['title' => 'PoSH quiz', 'passing_score' => 70, 'questions' => [
                ['question' => 'Who receives a complaint under the PoSH Act?', 'options' => ['The manager', 'The Internal Committee', 'The CEO'], 'answer' => 1, 'marks' => 1],
                ['question' => 'Within how many months of the incident should a complaint be filed?', 'options' => ['1', '3', '12'], 'answer' => 1, 'marks' => 1],
                ['question' => 'Can a complaint be filed anonymously?', 'options' => ['Yes', 'No'], 'answer' => 1, 'marks' => 1],
            ]]);

            $fire = Course::create(['title' => 'Fire safety drill', 'code' => 'FIRE', 'type' => 'classroom', 'category' => 'safety', 'duration_minutes' => 90, 'validity_months' => 24, 'status' => 'published']);
            TrainingSession::create(['course_id' => $fire->id, 'title' => 'Quarterly fire drill – Delhi', 'mode' => 'classroom', 'trainer_name' => 'Facilities warden', 'starts_at' => now()->addDays(10)->setTime(10, 0), 'ends_at' => now()->addDays(10)->setTime(11, 30), 'venue' => 'Delhi office, ground floor', 'capacity' => 30]);

            $assignment = LearningAssignment::create(['name' => 'PoSH for everyone (annual)', 'course_id' => $posh->id, 'due_days' => 30, 'recur_months' => 12, 'is_mandatory' => true]);
            app(Learning::class)->applyAssignment($assignment);
        }

        if (Asset::query()->doesntExist()) {
            $assets = app(Assets::class);
            $laptop = AssetCategory::query()->where('code', 'LAPTOP')->first();
            $model = AssetModel::query()->firstOrCreate(['asset_category_id' => $laptop->id, 'name' => 'Latitude 5540'], ['manufacturer' => 'Dell', 'specifications' => ['cpu' => 'i7', 'ram' => '16 GB', 'ssd' => '512 GB']]);
            $delhi = Location::query()->where('code', 'DEL')->first();
            $tech = Company::query()->where('code', 'DEMO-TECH')->first();

            foreach (['rahul.sharma@demo.local' => 'LT-0001', 'priya.nair@demo.local' => 'LT-0002', 'amit.verma@demo.local' => 'LT-0003'] as $email => $tag) {
                $asset = $assets->receive(['asset_category_id' => $laptop->id, 'asset_model_id' => $model->id, 'company_id' => $tech->id, 'location_id' => $delhi?->id, 'asset_tag' => $tag, 'name' => 'Dell Latitude 5540', 'serial_number' => 'DL'.substr($tag, -4).'X', 'purchase_date' => '2026-01-15', 'purchase_cost' => 92000, 'vendor' => 'Dell India', 'warranty_until' => '2029-01-14', 'condition' => 'new']);
                if ($employee = Employee::query()->where('work_email', $email)->first()) {
                    $assets->assign($asset, $employee, '2026-02-01', 'new', 'Standard engineering kit');
                }
            }
            $assets->receive(['asset_category_id' => $laptop->id, 'asset_model_id' => $model->id, 'company_id' => $tech->id, 'location_id' => $delhi?->id, 'asset_tag' => 'LT-0004', 'name' => 'Dell Latitude 5540 (spare)', 'serial_number' => 'DL0004X', 'purchase_date' => '2026-01-15', 'purchase_cost' => 92000, 'vendor' => 'Dell India', 'warranty_until' => '2029-01-14', 'condition' => 'new']);
        }
    }

    /** Defaults, a company → team → employee goal cascade, an active FY cycle with reviews under way, a career path. */
    private function seedPerformance(): void
    {
        app(PerformanceDefaults::class)->seed();

        if (PerformanceCycle::query()->where('code', 'FY27')->exists()) {
            return;
        }

        $goals = app(Goals::class);
        $cycle = PerformanceCycle::create([
            'name' => 'FY 2026-27 annual review', 'code' => 'FY27', 'type' => 'annual', 'period_start' => '2026-04-01', 'period_end' => '2027-03-31',
            'rating_scale_id' => RatingScale::default()->id,
            'stages' => PerformanceCycle::defaultStages(Carbon::parse('2027-03-31')),
            'weights' => ['goals' => 70, 'competencies' => 30],
            'competency_ids' => Competency::query()->whereIn('code', ['OWNERSHIP', 'COLLAB', 'COMMS'])->pluck('id')->all(),
        ]);

        $company = $goals->create(['level' => 'company', 'title' => 'Grow ARR by 40%', 'performance_cycle_id' => $cycle->id]);
        $eng = Department::query()->where('code', 'ENG')->first();
        $dept = $goals->create(['level' => 'department', 'organisation_node_id' => $eng?->organisationNode()->value('id'), 'parent_id' => $company->id, 'title' => 'Ship platform v2 by Q3', 'performance_cycle_id' => $cycle->id]);

        foreach (['rahul.sharma@demo.local' => ['Cut p95 latency to 200ms', 500, 200, 320], 'priya.nair@demo.local' => ['Raise test coverage to 80%', 40, 80, 62]] as $email => [$title, $start, $target, $current]) {
            $employee = Employee::query()->where('work_email', $email)->first();
            if ($employee) {
                $goals->create(['employee_id' => $employee->id, 'parent_id' => $dept->id, 'performance_cycle_id' => $cycle->id, 'type' => 'okr', 'title' => $title, 'weight' => 70], [
                    ['title' => $title, 'measure_type' => 'numeric', 'start_value' => $start, 'target_value' => $target, 'current_value' => $current, 'weight' => 100],
                ]);
                $goals->create(['employee_id' => $employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Mentor one junior engineer', 'weight' => 30, 'measure_type' => 'boolean', 'target_value' => 1]);
            }
        }

        $appraisals = app(Appraisals::class);
        $cycle = $appraisals->advance($appraisals->launch($cycle)); // goal setting → self review

        $se = Designation::query()->where('code', 'SE')->first();
        $em = Designation::query()->where('code', 'EM')->first();
        $cto = Designation::query()->where('code', 'CTO')->first();
        if ($se && $em && $cto) {
            $path = CareerPath::query()->firstOrCreate(['code' => 'ENG_LADDER'], ['name' => 'Engineering ladder']);
            foreach ([[$se, 10, 3], [$em, 20, 4], [$cto, 30, null]] as [$designation, $order, $years]) {
                $path->steps()->firstOrCreate(['designation_id' => $designation->id], ['sort_order' => $order, 'typical_years' => $years, 'required_skills' => []]);
            }
        }
    }

    /** Statutory profile, salaries for the demo workforce, a calculated run for the current month. */
    private function seedPayroll(): void
    {
        app(Kernel::class)->call('peopleos:compliance:sync');

        $tech = Company::query()->where('code', 'DEMO-TECH')->first();
        CompanyStatutoryProfile::query()->firstOrCreate(
            ['company_id' => $tech->id],
            ['pt_state' => 'KA', 'lwf_state' => 'KA', 'lwf_applicable' => true, 'pf_establishment_code' => 'KABNG0000001000', 'tan' => 'BLRD00000A'] + CompanyStatutoryProfile::defaults(),
        );
        // ADR-0001: every company gets an explicit legal entity and establishment (state from the profile).
        app(Kernel::class)->call('peopleos:legal-entities:backfill');

        // Phase 11: compensation is written only through an approved change, by four different people.
        $structure = SalaryStructure::query()->where('code', 'STANDARD')->first();
        $actors = [];
        foreach (['proposer' => 'tenant-hr-admin', 'reviewer' => 'hr-manager', 'approver' => 'tenant-hr-admin', 'executor' => 'payroll-admin'] as $duty => $role) {
            $actors[$duty] = User::query()->firstOrCreate(['email' => "compensation.{$duty}@demo.local"], ['tenant_id' => app(TenantContext::class)->id(), 'name' => 'Compensation '.ucfirst($duty).' (demo)', 'password' => 'password', 'status' => 'active']);
            $actors[$duty]->roles()->syncWithoutDetaching(Role::query()->where('slug', $role)->pluck('id'));
        }
        $changes = app(CompensationChanges::class);
        $ctc = ['anita.rao@demo.local' => 4800000, 'amit.verma@demo.local' => 2400000, 'rahul.sharma@demo.local' => 600000, 'priya.nair@demo.local' => 900000, 'vikram.singh@demo.local' => 480000];

        foreach ($ctc as $email => $annual) {
            $employee = Employee::query()->where('work_email', $email)->first();

            if ($employee && app(CompensationOutput::class)->history($employee)->isEmpty()) {
                $change = $changes->propose($employee, ['change_type' => 'hire', 'effective_from' => ($employee->joining_date ?? '2025-04-01'), 'salary_structure_id' => $structure->id, 'ctc_annual' => $annual, 'currency' => 'INR', 'component_values' => ['CONV' => 1600], 'reason' => 'Development seed'], $actors['proposer']);
                $changes->submit($change, $actors['proposer']);
                $changes->review($change, $actors['reviewer']);
                $changes->approve($change, $actors['approver']);
                $changes->schedule($change, $actors['executor']);
            }
        }

        if (PayrollRun::query()->doesntExist()) {
            $runs = app(PayrollRuns::class);
            $runs->calculate($runs->open($tech, (int) now()->year, (int) now()->month));
        }
    }

    /** Accrue leave for the demo workforce so balances show something. */
    private function seedLeave(): void
    {
        Employee::query()->with('person')->employed()->each(fn ($e) => app(LeaveAccrual::class)->accrue($e));
    }

    /** A general shift, a Mon–Fri schedule applied to everyone, and the national holiday calendar. */
    private function seedAttendance(): void
    {
        if (Shift::query()->where('code', 'GEN')->exists()) {
            return;
        }

        $general = Shift::create(['name' => 'General (09:00–18:00)', 'code' => 'GEN', 'type' => 'fixed', 'start_time' => '09:00:00', 'end_time' => '18:00:00', 'break_minutes' => 60, 'full_day_minutes' => 480, 'half_day_minutes' => 240, 'grace_in_minutes' => 15, 'overtime_eligible' => true, 'min_overtime_minutes' => 30]);
        Shift::create(['name' => 'Night (22:00–07:00)', 'code' => 'NIGHT', 'type' => 'fixed', 'start_time' => '22:00:00', 'end_time' => '07:00:00', 'crosses_midnight' => true, 'break_minutes' => 60, 'full_day_minutes' => 480, 'half_day_minutes' => 240, 'grace_in_minutes' => 15, 'overtime_eligible' => true]);
        Shift::create(['name' => 'Flexible (8 h)', 'code' => 'FLEX', 'type' => 'flexible', 'full_day_minutes' => 480, 'half_day_minutes' => 240]);

        $schedule = WorkSchedule::create(['name' => 'Monday to Friday', 'code' => 'MON_FRI', 'effective_from' => '2026-01-05', 'pattern' => [
            ['mon' => $general->id, 'tue' => $general->id, 'wed' => $general->id, 'thu' => $general->id, 'fri' => $general->id, 'sat' => null, 'sun' => null],
        ]]);
        WorkScheduleRule::create(['work_schedule_id' => $schedule->id, 'name' => 'Everyone', 'priority' => 100, 'conditions' => []]);

        $calendar = HolidayCalendar::create(['name' => 'National holidays 2026', 'code' => 'NAT_2026', 'year' => 2026]);
        foreach ([['2026-01-26', 'Republic Day'], ['2026-03-04', 'Holi'], ['2026-08-15', 'Independence Day'], ['2026-10-02', 'Gandhi Jayanti'], ['2026-10-20', 'Dussehra'], ['2026-11-08', 'Diwali'], ['2026-12-25', 'Christmas']] as [$date, $name]) {
            Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => $date, 'name' => $name]);
        }
        HolidayCalendarRule::create(['holiday_calendar_id' => $calendar->id, 'name' => 'Everyone', 'priority' => 100, 'conditions' => []]);

        AttendanceDevice::create(['name' => 'Delhi main gate', 'code' => 'DEL_GATE', 'adapter' => 'generic', 'location_id' => Location::query()->where('code', 'DEL')->value('id')]);
    }

    /** A standard onboarding checklist covering preboarding to 90 days. */
    private function seedOnboardingTemplate(): void
    {
        if (OnboardingTemplate::query()->where('key', 'standard')->exists()) {
            return;
        }

        $hrRole = Role::query()->where('slug', 'hr-executive')->value('id');
        $docType = fn (string $code) => DocumentType::query()->where('code', $code)->value('id');

        $template = OnboardingTemplate::create(['name' => 'Standard onboarding', 'key' => 'standard', 'description' => 'Preboarding documents, day one setup, first-week introductions and 30/60/90 check-ins.', 'priority' => 100]);
        $template->items()->createMany([
            ['phase' => 'preboarding', 'type' => 'document', 'title' => 'Upload PAN card', 'owner_type' => 'employee', 'document_type_id' => $docType('PAN'), 'sort_order' => 1],
            ['phase' => 'preboarding', 'type' => 'document', 'title' => 'Upload Aadhaar', 'owner_type' => 'employee', 'document_type_id' => $docType('AADHAAR'), 'sort_order' => 2],
            ['phase' => 'preboarding', 'type' => 'document', 'title' => 'Upload signed offer letter', 'owner_type' => 'employee', 'document_type_id' => $docType('OFFER'), 'sort_order' => 3],
            ['phase' => 'preboarding', 'type' => 'document', 'title' => 'Sign BGV consent', 'owner_type' => 'employee', 'document_type_id' => $docType('BGV_CONSENT'), 'sort_order' => 4],
            ['phase' => 'preboarding', 'type' => 'task', 'title' => 'Prepare laptop and accounts', 'owner_type' => 'role', 'owner_role_id' => $hrRole, 'sort_order' => 5],
            ['phase' => 'day_one', 'type' => 'task', 'title' => 'HR induction and ID card', 'owner_type' => 'role', 'owner_role_id' => $hrRole, 'sort_order' => 6],
            ['phase' => 'day_one', 'type' => 'acknowledgement', 'title' => 'Acknowledge code of conduct and POSH policy', 'owner_type' => 'employee', 'sort_order' => 7],
            ['phase' => 'day_one', 'type' => 'task', 'title' => 'Meet your manager and team', 'owner_type' => 'manager', 'sort_order' => 8],
            ['phase' => 'week_one', 'type' => 'task', 'title' => 'Set 30-day goals', 'owner_type' => 'manager', 'sort_order' => 9],
            ['phase' => 'day_30', 'type' => 'task', 'title' => '30-day check-in', 'owner_type' => 'manager', 'is_mandatory' => false, 'sort_order' => 10],
            ['phase' => 'day_60', 'type' => 'task', 'title' => '60-day check-in', 'owner_type' => 'manager', 'is_mandatory' => false, 'sort_order' => 11],
            ['phase' => 'day_90', 'type' => 'task', 'title' => '90-day review and confirmation recommendation', 'owner_type' => 'manager', 'sort_order' => 12],
        ]);
    }

    /** A demo approval workflow and a welcome notification rule. */
    private function seedWorkflows(): void
    {
        $template = NotificationTemplate::query()->firstOrCreate(
            ['key' => 'welcome_joiner'],
            ['name' => 'Welcome new joiner', 'subject' => 'Welcome to {{ tenant.name }}, {{ employee.first_name }}!', 'body' => "Hi {{ employee.first_name }},\n\nWelcome aboard as {{ employee.designation }} in {{ employee.department }}. Your manager is {{ employee.manager }}.\n\nSee you on {{ employee.joining_date }}."],
        );
        NotificationRule::query()->firstOrCreate(
            ['name' => 'Welcome every joiner'],
            ['event' => 'employee.joined', 'audience' => [['type' => 'subject'], ['type' => 'manager']], 'channels' => ['in_app', 'email'], 'notification_template_id' => $template->id],
        );

        if (Workflow::query()->where('key', 'probation_confirmation')->exists()) {
            return;
        }

        $hrRole = Role::query()->where('slug', 'hr-manager')->first();
        $workflow = Workflow::create([
            'name' => 'Probation confirmation',
            'key' => 'probation_confirmation',
            'description' => 'Manager recommends, HR confirms, employee is told.',
            'trigger_event' => 'manual',
            'subject_type' => Employee::class,
        ]);
        app(Workflows::class)->draft($workflow, [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => 'Start', 'config' => []],
                ['id' => 'manager', 'type' => 'approval', 'name' => 'Manager recommendation', 'config' => ['mode' => 'single', 'approver' => ['type' => 'manager'], 'title' => 'Recommend confirmation for {{ employee.name }}', 'sla_hours' => 48, 'escalation' => [['after_hours' => 48, 'action' => 'remind'], ['after_hours' => 96, 'action' => 'escalate_to_manager']]]],
                ['id' => 'hr', 'type' => 'approval', 'name' => 'HR confirmation', 'config' => ['mode' => 'single', 'approver' => ['type' => 'role', 'role_id' => $hrRole?->id], 'title' => 'Confirm {{ employee.name }}', 'sla_hours' => 72]],
                ['id' => 'confirm', 'type' => 'automation', 'name' => 'Stamp confirmation date', 'config' => ['set' => ['confirmation_date' => '{{ context.today }}']]],
                ['id' => 'notify', 'type' => 'notification', 'name' => 'Tell the employee', 'config' => ['audience' => [['type' => 'subject'], ['type' => 'manager']], 'channels' => ['in_app', 'email'], 'subject' => 'Congratulations {{ employee.first_name }}, you are confirmed', 'body' => 'Your probation with {{ tenant.name }} is complete.']],
                ['id' => 'end', 'type' => 'end', 'name' => 'End', 'config' => []],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'manager', 'label' => null],
                ['from' => 'manager', 'to' => 'hr', 'label' => 'approved'],
                ['from' => 'hr', 'to' => 'confirm', 'label' => 'approved'],
                ['from' => 'confirm', 'to' => 'notify', 'label' => null],
                ['from' => 'notify', 'to' => 'end', 'label' => null],
            ],
        ]);
        app(Workflows::class)->publish($workflow, 'Development seed');
    }

    /** A small, idempotent demo workforce inside Demo Technologies. */
    private function seedEmployees(): void
    {
        $tech = Company::query()->where('code', 'DEMO-TECH')->first();
        $delhi = Location::query()->where('code', 'DEL')->first();
        $eng = Department::query()->where('code', 'ENG')->first();
        $fin = Department::query()->where('code', 'FIN')->first();

        $designation = fn (string $name, string $code, string $level) => Designation::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'level_id' => Level::query()->where('code', $level)->value('id')],
        );
        $cto = $designation('Chief Technology Officer', 'CTO', 'L6');
        $em = $designation('Engineering Manager', 'EM', 'L5');
        $se = $designation('Software Engineer', 'SE', 'L2');
        $fa = $designation('Finance Analyst', 'FA', 'L2');

        $hire = function (string $first, string $last, string $email, string $joined, Designation $designation, ?Department $department, ?Employee $manager) use ($tech, $delhi): Employee {
            return Employee::query()->where('work_email', $email)->first() ?? app(HireEmployeeAction::class)->handle(
                ['first_name' => $first, 'last_name' => $last, 'personal_email' => strtolower("{$first}.{$last}@example.test")],
                ['joining_date' => $joined, 'work_email' => $email],
                ['company_id' => $tech->id, 'location_id' => $delhi?->id, 'department_id' => $department?->id, 'designation_id' => $designation->id, 'level_id' => $designation->level_id],
                $manager?->id,
                'Development seed',
            );
        };

        $anita = $hire('Anita', 'Rao', 'anita.rao@demo.local', '2022-01-10', $cto, $eng, null);
        $amit = $hire('Amit', 'Verma', 'amit.verma@demo.local', '2023-04-03', $em, $eng, $anita);
        $hire('Rahul', 'Sharma', 'rahul.sharma@demo.local', '2025-05-28', $se, $eng, $amit);
        $hire('Priya', 'Nair', 'priya.nair@demo.local', '2024-08-19', $se, $eng, $amit);
        $hire('Vikram', 'Singh', 'vikram.singh@demo.local', '2024-11-04', $fa, $fin, $anita);
    }
}
