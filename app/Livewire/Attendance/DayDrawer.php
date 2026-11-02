<?php

namespace App\Livewire\Attendance;

use App\Domain\Attendance\Contracts\LeaveOnDay;
use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceDayDetails;
use App\Domain\Attendance\Services\AttendanceOverrides;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\PunchCorrections;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\BulkOperations\ChangeAttendanceStatusOperation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\AttendanceCalendar;
use App\Filament\Support\CheckInEvidence;
use App\Http\Controllers\ParityFileController;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * HROne parity TX-7 (matrix 24 §24.5.2 #3 — the HR day drawer; shared with matrix 20 RQ-3): one employee's day in a drawer,
 * opened from Attendance records, the attendance calendar, the roster board, the paid-days breakdown and the punch register
 * with the browser event `pos-day-drawer-open` {employee, date[, readOnly]}. It shows the processed day, the punches (source,
 * device, geo status and matched location, excluded / refused marks; coordinates, photos and network only for the geo-access
 * audience, CheckInEvidence), HR's override, the day's leave (LeaveOnDay contract) and regularisations, and — for people
 * allowed — adds, excludes and restores punches (PunchCorrections), cancels leave (Leave's own cancellation), sets the
 * day's status with TX-6's form (AttendanceOverrides::setRange) or removes the override, and reprocesses the day.
 *
 * Every action re-checks the viewer's rights on the server; the drawer is read-only when mounted with readOnly, when opened
 * with readOnly, or simply for a viewer without those rights. After a change it dispatches `pos-attendance-day-changed` so
 * the page underneath can refresh. Rendered once per page by the panel shell (for holders of attendance.view).
 */
class DayDrawer extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** The host's choice: show the day without any action (RQ-3's employee calendar, for instance). */
    #[Locked]
    public bool $readOnly = false;

    #[Locked]
    public ?int $employeeId = null;

    #[Locked]
    public ?string $date = null;

    /** This opening asked for a read-only view. */
    #[Locked]
    public bool $viewOnly = false;

    /** The inline form open: add | override | clear | reprocess | exclude:{id} | restore:{id} | cancel:{id} */
    public ?string $form = null;

    public string $reason = '';

    public ?string $punchDate = null;

    public ?string $punchTime = null;

    public string $direction = 'auto';

    /** @var array<string, mixed>|null TX-6's status form */
    public ?array $overrideData = [];

    public ?string $message = null;

    public ?string $error = null;

    /** Who may open the drawer at all (the shell renders it for them): HR and managers with attendance.view. */
    public static function available(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && $user->hasPermission('attendance.view');
    }

    #[On('pos-day-drawer-open')]
    public function show(int|string $employee, string $date, bool $readOnly = false): void
    {
        $this->reset(['form', 'reason', 'punchDate', 'punchTime', 'direction', 'overrideData', 'message', 'error']);
        $this->employeeId = (int) $employee;
        $this->date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && Carbon::hasFormat($date, 'Y-m-d') ? $date : null;
        $this->viewOnly = $readOnly;
        unset($this->details, $this->employee);
    }

    public function close(): void
    {
        $this->reset(['employeeId', 'date', 'viewOnly', 'form', 'reason', 'punchDate', 'punchTime', 'direction', 'overrideData', 'message', 'error']);
        unset($this->details, $this->employee);
    }

    /** The employee of the open day, when the viewer may see them (the Employee query is access-scoped) and the day. */
    #[Computed]
    public function employee(): ?Employee
    {
        $user = auth()->user();
        if ($user === null || $this->employeeId === null || $this->date === null) {
            return null;
        }
        $employee = Employee::query()->with('person')->find($this->employeeId);
        if ($employee === null) {
            return null;
        }
        $own = $employee->user_id !== null && (int) $employee->user_id === (int) $user->id;

        return $own || $user->hasPermission('attendance.view') ? $employee : null;
    }

    /** @return array<string, mixed>|null */
    #[Computed]
    public function details(): ?array
    {
        $employee = $this->employee;

        return $employee === null ? null : app(AttendanceDayDetails::class)->day($employee, (string) $this->date, auth()->user());
    }

    // --- What the viewer may do ------------------------------------------------------------------------------------------

    public function actionable(): bool
    {
        return ! $this->readOnly && ! $this->viewOnly && $this->employee !== null;
    }

    public function canChangePunches(): bool
    {
        return $this->actionable() && app(PunchCorrections::class)->mayChange(auth()->user(), $this->employee);
    }

    /** TX-6's rule for "Edit": attendance.override in scope, never one's own day, a day that has started, locked only with corrections after lock. */
    public function canOverride(): bool
    {
        $details = $this->details;
        if (! $this->actionable() || $details === null || $this->own() || ! $this->inScope('attendance.override') || $details['date']->gt(today())) {
            return false;
        }

        return ! $details['locked'] || $this->correctionsAfterLock();
    }

    /** As the records list's "Reprocess": attendance.process in scope, not on a locked day, a day that has started. */
    public function canReprocess(): bool
    {
        $details = $this->details;

        return $this->actionable() && $details !== null && ! $details['locked'] && ! $details['date']->gt(today()) && $this->inScope('attendance.process');
    }

    public function evidenceVisible(): bool
    {
        return $this->employee !== null && CheckInEvidence::visibleTo(auth()->user(), $this->employee);
    }

    /** @return list<string> signed links to the punch's photos (only for the geo-access audience) */
    public function photoLinks(AttendancePunch $punch): array
    {
        if (! $this->evidenceVisible()) {
            return [];
        }

        return array_map(fn (int $i) => ParityFileController::url('punch-photo', $punch, $i), array_keys(array_values(array_filter((array) ($punch->photos ?? [])))));
    }

    public function calendarUrl(): ?string
    {
        $details = $this->details;

        return $details !== null && AttendanceCalendar::canAccess() ? AttendanceCalendar::getUrl(['employee' => $details['employee']->id, 'month' => $details['date']->format('Y-m')]) : null;
    }

    // --- Inline forms ----------------------------------------------------------------------------------------------------

    public function startForm(string $form): void
    {
        $this->form = $form;
        $this->reason = '';
        $this->error = null;
        $this->message = null;
        $this->resetValidation();
        if ($form === 'add') {
            $shift = $this->details['shift'] ?? null;
            $this->punchDate = $this->date;
            $this->punchTime = $shift?->start_time ? substr((string) $shift->start_time, 0, 5) : '09:00';
            $this->direction = 'auto';
        }
        if ($form === 'override' && $this->details !== null) {
            $this->overrideForm->fill(ChangeAttendanceStatusOperation::defaultsFor($this->details['record'] ?? new AttendanceRecord(['employee_id' => $this->employeeId, 'date' => Carbon::parse((string) $this->date)])));
        }
    }

    public function cancelForm(): void
    {
        $this->form = null;
        $this->reason = '';
        $this->error = null;
        $this->resetValidation();
    }

    public function overrideForm(Schema $schema): Schema
    {
        return $schema->components([
            ...ChangeAttendanceStatusOperation::statusFields(true),
            Textarea::make('reason')->label('Reason')->required()->maxLength(500),
        ])->statePath('overrideData');
    }

    // --- Actions ---------------------------------------------------------------------------------------------------------

    public function excludePunch(int $punch): void
    {
        $this->attempt(function () use ($punch) {
            app(PunchCorrections::class)->exclude($this->punch($punch), $this->reason, auth()->user());

            return 'Punch excluded: the day is recalculated without it. It stays on record and can be restored.';
        });
    }

    public function restorePunch(int $punch): void
    {
        $this->attempt(function () use ($punch) {
            app(PunchCorrections::class)->restore($this->punch($punch), $this->reason, auth()->user());

            return 'Punch restored: the day is recalculated with it.';
        });
    }

    public function addPunch(): void
    {
        $this->attempt(function () {
            $timezone = (string) ($this->details['timezone'] ?? config('app.timezone'));
            if (! filled($this->punchDate) || ! filled($this->punchTime)) {
                throw new RuntimeException('Give the date and time of the punch.');
            }
            try {
                $at = Carbon::parse($this->punchDate.' '.$this->punchTime, $timezone)->setTimezone(config('app.timezone'));
            } catch (Throwable) {
                throw new RuntimeException('Give the date and time of the punch.');
            }
            $punch = app(PunchCorrections::class)->add($this->employee ?? throw new RuntimeException('This day is not available.'), $at, $this->direction, $this->reason, auth()->user());

            return $punch === null ? throw new RuntimeException('A punch at that minute and direction is already on record (restore it if it was excluded).')
                : 'Punch added: the day is recalculated.';
        });
    }

    public function cancelLeave(int $leave): void
    {
        $this->attempt(function () use ($leave) {
            if (! collect($this->details['leave'] ?? [])->contains(fn (array $l) => $l['id'] === $leave && $l['can_cancel'])) {
                throw new RuntimeException('You may not cancel this leave.');
            }

            return app(LeaveOnDay::class)->cancel($leave, $this->reason, auth()->user());
        });
    }

    public function saveOverride(): void
    {
        if (! $this->canOverride()) {
            $this->error = 'You may not change this day.';

            return;
        }
        $data = $this->overrideForm->getState(); // TX-6's field rules (a status, both halves, the reason) show under the fields
        $this->attempt(function () use ($data) {
            [$status, $first, $second] = ChangeAttendanceStatusOperation::statusesFrom($data);
            $result = app(AttendanceOverrides::class)->setRange([$this->employee], (string) $this->date, (string) $this->date, $status, $first, $second, (string) ($data['reason'] ?? ''), auth()->user(),
                filled($data['worked_minutes'] ?? null) ? (int) $data['worked_minutes'] : null);
            if ($result['done'] === []) {
                throw new RuntimeException($result['refused'][0]['reason'] ?? 'Nothing was changed.');
            }

            return 'Day recalculated: '.$result['done'][0]['message'];
        });
    }

    public function clearOverride(): void
    {
        $this->attempt(function () {
            $override = $this->details['override'] ?? null;
            if (! $this->canOverride() || ! $override instanceof AttendanceOverride) {
                throw new RuntimeException('You may not change this day.');
            }
            $reason = trim($this->reason);
            if ($reason === '') {
                throw new RuntimeException('Give the reason for removing the override.');
            }
            app(AttendanceOverrides::class)->clear($override, mb_substr($reason, 0, 500));

            return 'Override removed: the day is recalculated from its punches, leave and requests.';
        });
    }

    public function reprocess(): void
    {
        $this->attempt(function () {
            if (! $this->canReprocess()) {
                throw new RuntimeException('You may not reprocess this day.');
            }
            $record = app(AttendanceProcessor::class)->process($this->employee, Carbon::parse((string) $this->date));

            return 'Reprocessed: '.$record->statusLabel().'.';
        });
    }

    public function render(): View
    {
        return view('livewire.attendance.day-drawer');
    }

    // --- Helpers ---------------------------------------------------------------------------------------------------------

    /** @param  callable(): string  $change */
    private function attempt(callable $change): void
    {
        $this->error = null;
        $this->message = null;
        if (! $this->actionable()) {
            $this->error = 'This day is read-only here.';

            return;
        }
        try {
            $this->message = $change();
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->form = null;
        $this->reason = '';
        unset($this->details);
        $this->dispatch('pos-attendance-day-changed', employee: $this->employeeId, date: $this->date);
    }

    /** A punch of the open employee (the punch query is access-scoped). */
    private function punch(int $id): AttendancePunch
    {
        return AttendancePunch::query()->where('employee_id', $this->employeeId)->find($id) ?? throw new RuntimeException('This punch is not available.');
    }

    private function own(): bool
    {
        $employee = $this->employee;

        return $employee !== null && $employee->user_id !== null && (int) $employee->user_id === (int) auth()->id();
    }

    /** The narrower time-office right (attendance.manage also grants it) on the employee's attendance, inside the viewer's scope. */
    private function inScope(string $permission): bool
    {
        $user = auth()->user();
        if ($user === null || $this->employee === null || ! $user->hasPermission($permission)) {
            return false;
        }
        $scopes = app(AccessScopes::class);

        return $scopes->allowsEmployeeId($user, (int) $this->employee->id, $scopes->moduleFor(AttendanceRecord::class));
    }

    private function correctionsAfterLock(): bool
    {
        return (bool) app(SettingsRepository::class)->get('attendance.allow_corrections_after_lock', false);
    }
}
