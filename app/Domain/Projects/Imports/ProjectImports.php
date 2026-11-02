<?php

namespace App\Domain\Projects\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Models\Company;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Customer;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\Projects;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-1 (Project Management → Projects): one row per project, by code, with customer, company, dates,
 * manager, billable flag, status and description, and optionally the employees on it (Member codes, separated by ;).
 * Members are added through the Projects service, as on the project's Members tab: an employee already on the project
 * is left as is, and nobody is taken off a project by an upload. Employees are found within the uploader's
 * organisation scope, and a new member or manager is a current or joining employee (decision D4), as on the screen.
 */
final class ProjectImports extends MasterImports
{
    use ReadsMasterRows;

    public const TYPE = 'projects';

    /** Text appended to the row result by the member step of the current row. */
    private string $memberNote = '';

    public static function fields(): array
    {
        return [
            'code' => ['Code', true], 'name' => ['Name', true], 'customer_code' => ['Customer code', false], 'company_code' => ['Company code', false],
            'start_date' => ['Start date', false], 'end_date' => ['End date', false], 'manager_code' => ['Manager code', false], 'is_billable' => ['Billable', false],
            'status' => ['Status', false], 'description' => ['Description', false], 'member_codes' => ['Member codes', false],
        ];
    }

    public function label(): string
    {
        return 'Projects upload';
    }

    protected function module(): string
    {
        return 'projects';
    }

    protected function model(): string
    {
        return Project::class;
    }

    /** Employees in the uploader's reach, and those of them who left or never joined (decision D4). */
    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['gone' => array_fill_keys(Employee::query()->whereIn('lifecycle_state', Projects::goneStates())->pluck('id')->map(fn ($id) => (int) $id)->all(), true)];
    }

    protected function createDefaults(): array
    {
        return ['status' => ProjectStatus::Planned->value, 'is_billable' => false];
    }

    protected function aliases(): array
    {
        return ['project_code' => 'code', 'project_name' => 'name', 'project' => 'name', 'customer' => 'customer_code', 'client_code' => 'customer_code', 'company' => 'company_code',
            'start' => 'start_date', 'end' => 'end_date', 'project_manager' => 'manager_code', 'manager' => 'manager_code', 'manager_employee_code' => 'manager_code',
            'billable' => 'is_billable', 'members' => 'member_codes', 'team' => 'member_codes', 'employee_codes' => 'member_codes'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $start = $this->dateCell($r['start_date'] ?? null, 'Start date', $errors);
        $end = $this->dateCell($r['end_date'] ?? null, 'End date', $errors);
        $from = $start ?? $existing?->start_date?->toDateString();
        $to = $end ?? $existing?->end_date?->toDateString();
        if ($from !== null && $to !== null && $to < $from) {
            $errors[] = 'A project ends on or after the day it starts.';
        }
        $manager = null;
        if (($r['manager_code'] ?? '') !== '') {
            $manager = $context['employees'][mb_strtolower($r['manager_code'])] ?? null;
            if ($manager === null) {
                $errors[] = "Manager code '{$r['manager_code']}' is not an employee you can reach.";
            } elseif (isset($context['gone'][$manager]) && (int) ($existing?->getAttributes()['manager_employee_id'] ?? 0) !== $manager) {
                // Decision D4: as on the project screen, a new manager is a current or joining employee.
                $errors[] = "Manager code '{$r['manager_code']}' has left or never joined: a project manager is a current or joining employee.";
            }
        }
        foreach ($this->memberIds($r, $context, $errors) as $code => $employeeId) {
            if (isset($context['gone'][$employeeId]) && ! ($existing instanceof Project && app(Projects::class)->isMember($existing, $employeeId))) {
                $errors[] = "Member code '{$code}' has left or never joined: only current or joining employees are put on a project.";
            }
        }

        return array_filter([
            'name' => $this->text($r['name'] ?? null, 160, 'Name', $errors),
            'customer_id' => $this->lookup($context, Customer::class, $r['customer_code'] ?? null, 'Customer code', $errors),
            'company_id' => $this->lookup($context, Company::class, $r['company_code'] ?? null, 'Company code', $errors),
            'start_date' => $start,
            'end_date' => $end,
            'manager_employee_id' => $manager,
            'is_billable' => $this->flag($r['is_billable'] ?? null, 'Billable', $errors),
            'status' => $this->choice($r['status'] ?? null, collect(ProjectStatus::cases())->mapWithKeys(fn (ProjectStatus $s) => [$s->value => $s->getLabel()])->all(), 'Status', $errors),
            'description' => $this->text($r['description'] ?? null, 5000, 'Description', $errors),
        ], fn ($v) => $v !== null);
    }

    /** A project whose fields are on file is still changed when the file puts new people on it. */
    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $decision = parent::check($r, $context, $row, $import);
        if ($decision['action'] !== 'skip' || ! isset($decision['match']['id'])) {
            return $decision;
        }
        $errors = [];
        $project = Project::query()->find($decision['match']['id']);
        $new = array_filter($this->memberIds($r, $context, $errors), fn (int $id) => ! app(Projects::class)->isMember($project, $id));

        return $new === [] ? $decision : ['action' => 'update', 'match' => ['id' => $project->getKey(), 'changes' => ['members']]];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $this->memberNote = '';

        return parent::apply($r, $row, $import, $user).$this->memberNote;
    }

    protected function created(Model $record, array $r, array $context): void
    {
        $this->addMembers($record, $r, $context);
    }

    protected function updated(Model $record, array $r, array $context): void
    {
        $this->addMembers($record, $r, $context);
    }

    private function addMembers(Model $project, array $r, array $context): void
    {
        $errors = [];
        $added = [];
        foreach ($this->memberIds($r, $context, $errors) as $employeeId) {
            /** @var Project $project */
            if (app(Projects::class)->isMember($project, $employeeId)) {
                continue;
            }
            $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($employeeId);
            app(Projects::class)->addMember($project, $employee, [], $this->label());
            $added[] = $employee->employee_code;
        }
        if ($added !== []) {
            $this->memberNote = '; added '.count($added).' member(s): '.implode(', ', $added);
        }
    }

    /** @return array<string, int> code as given => employee id, for the employees named in Member codes (within the uploader's scope) @param list<string> $errors */
    private function memberIds(array $r, array $context, array &$errors): array
    {
        $ids = [];
        foreach (array_filter(array_map('trim', preg_split('/[;,]/', (string) ($r['member_codes'] ?? '')))) as $code) {
            $id = $context['employees'][mb_strtolower($code)] ?? null;
            if ($id === null) {
                $errors[] = "Member code '{$code}' is not an employee you can reach.";

                continue;
            }
            if (! in_array((int) $id, $ids, true)) {
                $ids[$code] = (int) $id;
            }
        }

        return $ids;
    }

    /** @param list<string> $errors */
    private function dateCell(?string $value, string $what, array &$errors): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $date = $this->date(trim($value));
        if ($date === null) {
            $errors[] = "{$what} must be a date (YYYY-MM-DD).";
        }

        return $date;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'The project code (up to 32 characters). A code on file updates that project.', 'GLX-PORTAL'],
                ['Name', 'Yes', 'The project name.', 'Globex customer portal'],
                ['Customer code', 'No', 'Code of the customer it is for (Customers upload or the Customers screen). Blank for an internal project.', 'GLOBEX'],
                ['Company code', 'No', 'Code of the company that runs it (Organisation → Companies).', 'ACME'],
                ['Start date', 'No', 'YYYY-MM-DD.', '2026-04-01'],
                ['End date', 'No', 'YYYY-MM-DD, on or after the start date.', '2027-03-31'],
                ['Manager code', 'No', 'Employee code of the project manager (a current or joining employee).', 'EMP0001'],
                ['Billable', 'No', 'yes or no (blank for a new project is no).', 'yes'],
                ['Status', 'No', 'One of: '.implode(', ', array_map(fn (ProjectStatus $s) => $s->value, ProjectStatus::cases())).'. Blank for a new project is planned.', 'active'],
                ['Description', 'No', 'What the project delivers.', 'Self-service portal for Globex customers'],
                ['Member codes', 'No', 'Employee codes of the people on the project, separated by ; (current or joining employees; they are added, nobody is taken off).', 'EMP0002;EMP0003'],
            ],
            'notes' => [
                'Rows are matched on Code (any case). A new code adds a project; a code on file updates it; a row whose values are already on file (and adds nobody) is skipped.',
                'Blank cells keep what is on file. Member codes add people to the project (with no role or allocation: set those on the project\'s Members tab); an upload never takes anyone off.',
            ],
        ];
    }

    protected function examples(): array
    {
        $codes = Employee::query()->orderBy('id')->limit(3)->pluck('employee_code')->all();
        $manager = $codes[0] ?? 'EMP0001';
        $members = implode(';', array_slice($codes, 1)) ?: '';
        $company = Company::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('code') ?? '';
        $customer = Customer::query()->orderBy('id')->value('code') ?? '';

        return [
            ['GLX-PORTAL', 'Customer portal', $customer, $company, '2026-04-01', '2027-03-31', $manager, 'yes', 'active', 'Self-service portal for customers', $members],
            ['INT-HRMS', 'HRMS roll-out', '', $company, '2026-06-01', '', $manager, 'no', 'planned', 'Internal HR system roll-out', ''],
        ];
    }
}
