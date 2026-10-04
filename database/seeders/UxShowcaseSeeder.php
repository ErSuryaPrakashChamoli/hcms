<?php

namespace Database\Seeders;

use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Actions\PromoteEmployeeAction;
use App\Domain\Employment\Actions\TransferEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\Exits;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

/**
 * Experience Transformation: a richer, fictional demo population for UX review and the visual
 * regression references (docs/ux/visual-regression). It adds people across departments, one sign-in per
 * experience lens (employee, manager, HR, HR admin, payroll, executive) and a few open requests so the
 * Home, My work, Approvals, People and Org map screens have something to show.
 *
 * It refuses to run against anything but a disposable database whose name ends in "_showcase" (or the
 * test database), so it can never add fictional people to a real tenant. Every person is invented.
 */
class UxShowcaseSeeder extends Seeder
{
    public function run(TenantContext $tenants): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! app()->environment('testing') && ! str_ends_with($database, '_showcase')) {
            throw new RuntimeException("UxShowcaseSeeder only runs on a disposable *_showcase database (current: {$database}).");
        }

        $this->call(DatabaseSeeder::class);
        $tenant = $tenants->bypass(fn () => Tenant::query()->where('slug', 'demo')->firstOrFail());
        $tenants->runAs($tenant, fn () => $this->populate());
    }

    private function populate(): void
    {
        $tech = Company::query()->where('code', 'DEMO-TECH')->firstOrFail();
        $locations = Location::query()->pluck('id', 'code');
        $dept = fn (string $code) => Department::query()->where('code', $code)->first();
        $designation = fn (string $name, string $code, string $level) => Designation::query()->firstOrCreate(['code' => $code], ['name' => $name, 'level_id' => Level::query()->where('code', $level)->value('id')]);
        $hire = function (string $first, string $last, string $joined, Designation $d, ?Department $department, ?Employee $manager, string $loc = 'DEL') use ($tech, $locations): Employee {
            $email = strtolower($first.'.'.$last).'@demo.local';

            return Employee::query()->where('work_email', $email)->first() ?? app(HireEmployeeAction::class)->handle(
                ['first_name' => $first, 'last_name' => $last, 'personal_email' => strtolower("{$first}.{$last}@example.test")],
                ['joining_date' => $joined, 'work_email' => $email],
                ['company_id' => $tech->id, 'location_id' => $locations[$loc] ?? null, 'department_id' => $department?->id, 'designation_id' => $d->id, 'level_id' => $d->level_id],
                $manager?->id,
                'UX showcase seed',
            );
        };

        $anita = Employee::query()->where('work_email', 'anita.rao@demo.local')->firstOrFail();
        $amit = Employee::query()->where('work_email', 'amit.verma@demo.local')->firstOrFail();
        $priya = Employee::query()->where('work_email', 'priya.nair@demo.local')->firstOrFail();
        $rahul = Employee::query()->where('work_email', 'rahul.sharma@demo.local')->firstOrFail();

        $ceo = $designation('Chief Executive Officer', 'CEO', 'L6');
        $hrbp = $designation('HR Business Partner', 'HRBP', 'L4');
        $hrhead = $designation('Head of People', 'HOP', 'L5');
        $payrollLead = $designation('Payroll Lead', 'PRL', 'L4');
        $designer = $designation('Product Designer', 'PD', 'L3');
        $se = Designation::query()->where('code', 'SE')->firstOrFail();
        $em = Designation::query()->where('code', 'EM')->firstOrFail();
        $consultant = $designation('Consultant', 'CONSL', 'L3');
        $designLead = $designation('Design Lead', 'DL', 'L5');

        $meera = $hire('Meera', 'Iyer', '2021-06-14', $ceo, null, null);
        if ($anita->currentManager === null) {
            $admin = User::query()->where('email', 'admin@fynnedge.com')->firstOrFail();
            app(ChangeManagerAction::class)->change($anita, $meera, $admin, 'line', now()->subYear()->toDateString(), 'UX showcase seed');
        }
        $kavya = $hire('Kavya', 'Menon', '2022-03-01', $hrhead, null, $meera, 'BLR');
        $neha = $hire('Neha', 'Kapoor', '2023-02-13', $hrbp, null, $kavya);
        $arjun = $hire('Arjun', 'Bose', '2022-09-05', $payrollLead, $dept('FIN'), $anita);
        $sara = $hire('Sara', 'Thomas', '2023-07-17', $designLead, $dept('DSN'), $meera, 'BLR');
        foreach ([['Ishaan', 'Gupta', '2024-02-05'], ['Zoya', 'Khan', '2025-01-20'], ['Dev', 'Malhotra', '2025-09-08']] as [$f, $l, $j]) {
            $hire($f, $l, $j, $designer, $dept('DSN'), $sara, 'BLR');
        }
        $ravi = $hire('Ravi', 'Kumar', '2023-11-06', $em, $dept('ENG'), $anita);
        foreach ([['Fatima', 'Sheikh', '2024-04-15'], ['Karan', 'Mehta', '2025-03-03'], ['Ananya', 'Das', '2025-08-25'], ['Tenzin', 'Norbu', '2024-12-02']] as [$f, $l, $j]) {
            $hire($f, $l, $j, $se, $dept('ENG'), $ravi);
        }
        foreach ([['Rohan', 'Pillai', '2025-06-16'], ['Leela', 'Chandran', '2025-09-15']] as [$f, $l, $j]) {
            $hire($f, $l, $j, $se, $dept('ENG'), $amit);
        }
        foreach ([['Omar', 'Farooq', '2023-05-22'], ['Nisha', 'Reddy', '2024-10-07']] as [$f, $l, $j]) {
            $hire($f, $l, $j, $consultant, $dept('CONS'), $meera, 'BLR');
        }

        // One sign-in per lens (fictional people; password "password" on a disposable database only).
        $this->account($priya, 'employee');
        $this->account($amit, 'manager', 'employee');
        $this->account($neha, 'hr-manager', 'employee');
        $this->account($kavya, 'tenant-hr-admin', 'employee');
        $this->account($arjun, 'payroll-admin', 'employee');
        $this->account($meera, 'executive', 'employee');
        $this->account($rahul, 'employee');

        $this->openRequests($priya, $rahul, $amit);
        $this->syntheticActivity($amit, $sara, $ravi);
    }

    /**
     * UX.15: synthetic, clearly fictional activity so decision and change screens have realistic content:
     * pending requests for one manager's team, a promotion, a location transfer, non-line relationships
     * (dotted, project, mentor, functional) and a resignation. Everything goes through the existing domain
     * actions and services; every reason says "UX showcase seed (synthetic)".
     */
    private function syntheticActivity(Employee $amit, Employee $sara, Employee $ravi): void
    {
        $reason = 'UX showcase seed (synthetic)';
        $by = fn (string $email) => Employee::query()->where('work_email', $email)->first();
        $team = array_filter(['priya' => $by('priya.nair@demo.local'), 'rahul' => $by('rahul.sharma@demo.local'), 'rohan' => $by('rohan.pillai@demo.local'), 'leela' => $by('leela.chandran@demo.local')]);
        // Balances come from the leave domain's own accrual run (as the scheduler would), not from direct writes.
        rescue(fn () => Artisan::call('peopleos:leave:accrue', ['--tenant' => app(TenantContext::class)->current()?->slug]), null, true);
        $monday = now()->next('Monday');
        $friday = now()->next('Friday');
        foreach ([
            ['rohan', $friday->copy()->addWeek(), $friday->copy()->addWeek(), 'Moving house'],
            ['leela', $monday->copy()->addWeeks(3), $monday->copy()->addWeeks(3)->addDay(), 'Visiting family in Madurai'],
        ] as [$key, $from, $to, $why]) {
            if (isset($team[$key]) && ! $team[$key]->leaveRequests()->where('status', 'pending')->exists()) {
                $this->requestLeave($team[$key], $from->toDateString(), $to->toDateString(), $why);
            }
        }
        try {
            if (isset($team['leela']) && ! $team['leela']->attendanceRegularisations()->where('status', 'pending')->exists()) {
                $day = now()->subWeekdays(3);
                app(Regularisations::class)->request($team['leela'], $day->toDateString(), array_key_first(config('peopleos.attendance.regularisation_types')), 'Badge reader was down at the main gate',
                    $day->copy()->setTime(9, 5)->toDateTimeString(), $day->copy()->setTime(18, 20)->toDateTimeString(), $team['leela']->user);
            }
        } catch (Throwable $e) {
            report($e);
        }

        // Workforce movement: a promotion and a location transfer, recorded as of a few days ago.
        try {
            $fatima = $by('fatima.sheikh@demo.local');
            $senior = Designation::query()->firstOrCreate(['code' => 'SSE'], ['name' => 'Senior Software Engineer', 'level_id' => Level::query()->where('code', 'L4')->value('id')]);
            if ($fatima && ! $fatima->positions()->where('change_type', 'promotion')->exists()) {
                app(PromoteEmployeeAction::class)->handle($fatima, ['designation_id' => $senior->id, 'level_id' => $senior->level_id], now()->subDays(4)->toDateString(), null, $reason);
            }
        } catch (Throwable $e) {
            report($e);
        }
        try {
            $tenzin = $by('tenzin.norbu@demo.local');
            $blr = Location::query()->where('code', 'BLR')->value('id');
            if ($tenzin && $blr && ! $tenzin->positions()->where('change_type', 'transfer')->exists()) {
                app(TransferEmployeeAction::class)->handle($tenzin, ['location_id' => $blr], now()->subDays(2)->toDateString(), null, $reason);
            }
        } catch (Throwable $e) {
            report($e);
        }

        // Relationships beyond the line: dotted, project, mentor and functional.
        foreach ([['karan.mehta@demo.local', $amit, 'dotted'], ['ananya.das@demo.local', $sara, 'project'], ['zoya.khan@demo.local', $by('ishaan.gupta@demo.local'), 'mentor'], ['leela.chandran@demo.local', $ravi, 'functional']] as [$email, $manager, $type]) {
            try {
                $employee = $by($email);
                if ($employee && $manager && ! ReportingRelationship::query()->where('employee_id', $employee->id)->where('type', $type)->exists()) {
                    app(ChangeManagerAction::class)->handle($employee, $manager, $type, now()->subDays(10)->toDateString(), $reason);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        // A resignation in notice.
        try {
            $omar = $by('omar.farooq@demo.local');
            if ($omar && ! ExitCase::query()->where('employee_id', $omar->id)->exists()) {
                app(Exits::class)->resign($omar, 'Moving abroad for family reasons ('.$reason.')', now()->addDays(30)->toDateString());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Request leave with the first leave type the employee may take today (probation rules apply). */
    private function requestLeave(Employee $who, string $from, string $to, string $why): void
    {
        foreach (['CL', 'SL', 'EL'] as $code) {
            $type = LeaveType::query()->where('code', $code)->where('status', 'active')->first();
            if ($type === null) {
                continue;
            }
            try {
                app(Leaves::class)->request($who, $type, $from, $to, $why, 'full', 'full', null, $who->user);

                return;
            } catch (Throwable) {
                // Not this type for this person (for example earned leave during probation): try the next.
            }
        }
    }

    private function account(Employee $employee, string ...$roles): User
    {
        $employee->loadMissing('person');
        $user = $employee->user_id ? User::query()->find($employee->user_id) : null;
        $user ??= User::query()->firstOrCreate(['email' => $employee->work_email], [
            'tenant_id' => app(TenantContext::class)->id(), 'name' => $employee->person->display_name, 'password' => 'password', 'status' => 'active',
        ]);
        $user->roles()->syncWithoutDetaching(Role::query()->whereIn('slug', $roles)->pluck('id'));
        if ($employee->user_id === null) {
            LifecycleEngine::unguarded(fn () => $employee->forceFill(['user_id' => $user->id])->save());
        }

        return $user;
    }

    private function openRequests(Employee $priya, Employee $rahul, Employee $amit): void
    {
        $next = now()->next('Monday');
        foreach ([[$priya, $next->copy()->addDays(7), $next->copy()->addDays(8), 'Family wedding in Kochi'], [$rahul, now()->nextWeekday(), now()->nextWeekday(), 'Medical appointment']] as [$who, $from, $to, $why]) {
            if (! $who->leaveRequests()->where('status', 'pending')->exists()) {
                $this->requestLeave($who, $from->toDateString(), $to->toDateString(), $why);
            }
        }
        try {
            if (! $rahul->attendanceRegularisations()->where('status', 'pending')->exists()) {
                app(Regularisations::class)->request($rahul, now()->subDays(2)->toDateString(), array_key_first(config('peopleos.attendance.regularisation_types')), 'Forgot to punch out after the release call',
                    now()->subDays(2)->setTime(9, 30)->toDateTimeString(), now()->subDays(2)->setTime(19, 10)->toDateTimeString(), $rahul->user);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
