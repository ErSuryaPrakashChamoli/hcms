<?php

namespace App\Livewire\Compensation;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Compensation\Services\SalaryAssignments;
use App\Domain\Compensation\Services\SalaryRevisions;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Letters\Services\Letters;
use App\Domain\Payroll\Models\VariablePayPlan;
use App\Domain\Payroll\Services\SalaryProposalPreview;
use App\Filament\Pages\ChangeIntelligencePage;
use App\Filament\Pages\MyCompensation;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\CompensationChangesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\CompensationRelationManager;
use App\Filament\Support\Compensation\SalaryChangeWizard;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Employee 360 → Rewards → Current salary (owner, 2026-11-27: "compensation is just showing full salary, not the
 * breakup"). One place for an employee's salary: whether one is assigned at all, the structure and CTC in force, the full
 * breakup a month (every earning, the deductions including the read-only statutory estimate, net pay, employer
 * contributions, CTC and the variable target), the statutory details payroll applies, the salary checks, and the analysis
 * of every revision since joining (how many, by how much, how often). The rows of that story stay in the Salary history
 * list under it, each with its change and a line-by-line Compare.
 *
 * From here, for someone who may propose compensation: Assign salary (none yet), Revise salary (a hike % or a new amount
 * on CTC, gross or net pay) and Edit salary in force (a correction on its own date) — the shared SalaryChangeWizard,
 * which only proposes: a reviewer, an approver and an executor decide every change, and the audit trail keeps each step.
 *
 * Who sees what (CompensationAccess): full readers everything; managers with compensation.team the salary, the breakup
 * and the analysis (statutory details only with employee.sensitive.view, never the open requests or reasons); the
 * employee is pointed to My compensation, where the organisation's own switches decide what they see. Opening it is an
 * audited sensitive read, as the Salary history list is.
 */
class CurrentSalary extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    #[Locked]
    public int $employeeId;

    #[Locked]
    public string $pageClass = ViewEmployee::class;

    private ?Employee $employee = null;

    /** The Employee 360 view list contract (EmployeeResource::VIEW_LISTS): who may see this panel for an employee. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Employee && app(CompensationAccess::class)->mayViewCompensation(auth()->user(), $ownerRecord);
    }

    /** Loaded when its view is first opened, as the 360's lists are. */
    public static function getDefaultProperties(): array
    {
        return ['lazy' => true];
    }

    public function mount(Model $ownerRecord, string $pageClass = ViewEmployee::class): void
    {
        abort_unless($ownerRecord instanceof Employee && static::canViewForRecord($ownerRecord, $pageClass), 403);
        $this->employeeId = (int) $ownerRecord->getKey();
        $this->pageClass = $pageClass;
        $this->employee = $ownerRecord;
        if ($this->level() !== 'self') {
            app(SensitiveAccessAuditor::class)->recordView($ownerRecord, 'compensation', 'current_salary');
        }
    }

    public function employee(): Employee
    {
        return $this->employee ??= Employee::query()->with('person')->findOrFail($this->employeeId);
    }

    /** 'full' | 'team' | 'self' (re-checked on every request; nothing for anyone else). */
    public function level(): string
    {
        $level = app(CompensationAccess::class)->level(auth()->user(), $this->employee());
        abort_if($level === null, 403);

        return $level;
    }

    /**
     * Everything the panel shows, worked out once per request.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function salary(): array
    {
        $employee = $this->employee();
        $level = $this->level();
        if ($level === 'self') {
            return ['level' => 'self', 'myUrl' => MyCompensation::canAccess() ? MyCompensation::getUrl() : null];
        }
        $user = auth()->user();
        $output = app(CompensationOutput::class);
        $revisions = app(SalaryRevisions::class);
        $snapshot = $output->on($employee, today());
        $history = $revisions->history($employee);
        $next = collect($history['rows'])->first(fn (array $row) => $row['from']->gt(today()));
        $full = $level === 'full';

        $breakup = null;
        $figures = null;
        $pay = null;
        $failure = null;
        if ($snapshot !== null) {
            try {
                $segment = $revisions->inForce($employee, today());
                if ($segment === null) {
                    $failure = 'The salary structure '.$snapshot->structureCode.' has no approved version in force today, so its breakup cannot be worked out. Payroll reports the same until a version is approved.';
                } else {
                    $figures = app(SalaryProposalPreview::class)->for($employee, $segment);
                    $breakup = $revisions->compare($employee, null, $segment, $figures);
                    $pay = $revisions->breakup($segment, $figures);
                }
            } catch (Throwable $e) {
                report($e);
                $failure = 'The breakup could not be worked out for this salary.';
            }
        }
        $open = $full ? SalaryChangeWizard::openRequest($employee) : null;
        $openPercent = $open !== null && $snapshot !== null && $open->currency === $snapshot->currency ? SalaryRevisions::percent($snapshot->ctcAnnual, (float) $open->ctc_annual) : null;
        $plans = VariablePayPlan::query()->with('component:id,name')->where('employee_id', $employee->id)->where('status', 'active')->orderBy('id')->get()
            ->map(fn (VariablePayPlan $plan) => ['name' => $plan->name ?: (string) $plan->component?->name, 'total' => array_sum($plan->targets()), 'months' => count($plan->targets())])->all();

        return [
            'level' => $level, 'full' => $full, 'snapshot' => $snapshot, 'next' => $next,
            'structure' => $snapshot !== null ? (string) (SalaryStructure::query()->whereKey($snapshot->structureId)->value('name') ?? $snapshot->structureCode) : null,
            'breakup' => $breakup, 'pay' => $pay, 'figures' => $figures, 'failure' => $failure,
            'statutory' => $user->can('viewSensitive', $employee) ? SalaryChangeWizard::statutoryDetails($employee) : null,
            'statutoryEdit' => $user->can('updateSensitive', $employee) && EmployeeResource::canEdit($employee) ? EmployeeResource::getUrl('edit', ['record' => $employee, 'step' => 'statutory']) : null,
            'open' => $open, 'openPercent' => $openPercent, 'openStatus' => $open ? (CompensationChange::STATUSES[$open->status] ?? $open->status) : null,
            'requestsUrl' => CompensationChangesRelationManager::canViewForRecord($employee, $this->pageClass) ? '#list-CompensationChangesRelationManager' : null,
            'historyUrl' => CompensationRelationManager::canViewForRecord($employee, $this->pageClass) ? '#list-CompensationRelationManager' : null,
            'auditUrl' => $full && $user->hasPermission('audit.view') ? ChangeIntelligencePage::getUrl(['employee' => $employee->id]) : null,
            'history' => $history, 'plans' => $plans,
        ];
    }

    // --- Actions -------------------------------------------------------------------------------------------------------

    /** Someone who may propose this employee's compensation here (the services check again on proposing). */
    public function mayPropose(): bool
    {
        $user = auth()->user();
        $employee = $this->employee();

        return $user !== null && $this->level() === 'full' && $user->hasPermission('compensation.propose') && (int) $employee->user_id !== (int) $user->id
            && app(AccessScopes::class)->allows($user, $employee);
    }

    /** @var bool|null whether a change is open for the employee (this request) */
    private ?bool $pending = null;

    /**
     * Nothing new while a change is open (draft, submitted, under review): the banner says so and links the request, as
     * SalaryAssignments refuses a second one. An approved change waiting for its date does not block another.
     */
    private function mayChange(): bool
    {
        $this->pending ??= CompensationChange::query()->where('employee_id', $this->employeeId)->whereIn('status', SalaryAssignments::OPEN)->exists();

        return $this->mayPropose() && ! $this->pending;
    }

    /** A lifecycle state that may still receive a salary now or later (exited and alumni only get an edit). */
    private function eligible(): bool
    {
        $state = $this->employee()->lifecycle_state;
        $state = $state instanceof \BackedEnum ? $state->value : (string) $state;

        return in_array($state, [...(array) config('peopleos.compensation.lifecycle.current', []), ...(array) config('peopleos.compensation.lifecycle.future', [])], true);
    }

    public function assignSalaryAction(): Action
    {
        return $this->wizard('assignSalary', 'assign')
            ->visible(fn () => $this->mayChange() && $this->eligible() && SalaryChangeWizard::purposeFor($this->employee()) === 'assign');
    }

    public function reviseSalaryAction(): Action
    {
        return $this->wizard('reviseSalary', 'revise')
            ->visible(fn () => $this->mayChange() && $this->eligible() && SalaryChangeWizard::purposeFor($this->employee()) === 'revise');
    }

    public function correctSalaryAction(): Action
    {
        return $this->wizard('correctSalary', 'correct')->color('gray')
            ->visible(fn () => $this->mayChange() && SalaryChangeWizard::base($this->employee()) !== null);
    }

    /** The salary in force as a printable breakup in the letter studio's layout (audited as an export). */
    public function printBreakupAction(): Action
    {
        return Action::make('printBreakup')->label('Print breakup')->icon(Heroicon::OutlinedPrinter)->color('gray')
            ->visible(fn () => $this->level() === 'full' && ($this->salary['breakup'] ?? null) !== null)
            ->action(fn () => $this->printBreakup());
    }

    public function printBreakup(): ?StreamedResponse
    {
        abort_unless($this->level() === 'full', 403);
        $employee = $this->employee();
        try {
            $segment = app(SalaryRevisions::class)->inForce($employee, today()) ?? throw new RuntimeException('No salary with an approved structure version is in force today.');
            $file = app(Letters::class)->breakup($employee, $segment, auth()->user(), null, proposal: false);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Breakup not printed')->body($e->getMessage())->persistent()->send();

            return null;
        }

        return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function wizard(string $name, string $purpose): Action
    {
        return Action::make($name)->label(SalaryChangeWizard::PURPOSES[$purpose])
            ->icon(match ($purpose) {
                'assign' => Heroicon::OutlinedBanknotes, 'revise' => Heroicon::OutlinedArrowTrendingUp, default => Heroicon::OutlinedPencilSquare
            })
            ->modalHeading(fn () => SalaryChangeWizard::PURPOSES[$purpose].' · '.$this->employee()->employee_code.' · '.($this->employee()->person?->display_name ?? ''))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Propose')
            ->mountUsing(function ($schema) use ($purpose) {
                SalaryChangeWizard::auditOpening($this->employee(), $purpose);
                $schema?->fill();
            })
            ->steps(fn () => SalaryChangeWizard::steps($this->employee(), $purpose))
            ->action(function (array $data) use ($purpose) {
                abort_unless($this->mayPropose(), 403);
                if (SalaryChangeWizard::submit($this->employee(), $data, $purpose) !== null) {
                    unset($this->salary);
                    $this->pending = null;
                    $this->dispatch('pos-salary-changed', employee: $this->employeeId);
                }
            });
    }

    public function placeholder(): View
    {
        return view('livewire.compensation.current-salary-placeholder');
    }

    public function render(): View
    {
        return view('livewire.compensation.current-salary', ['s' => $this->salary]);
    }
}
