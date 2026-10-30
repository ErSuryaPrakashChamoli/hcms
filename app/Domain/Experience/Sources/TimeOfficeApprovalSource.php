<?php

namespace App\Domain\Experience\Sources;

use App\Domain\Attendance\Models\LocationRequest;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\GeoLocations;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Experience\Contracts\ApprovalSource;
use App\Domain\Experience\Contracts\DescribesApprovalKinds;
use App\Domain\Experience\Contracts\SupportsSendBack;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveDonation;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveDonations;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Filament\Resources\LeaveDonations\LeaveDonationResource;
use App\Filament\Resources\LeaveEncashments\LeaveEncashmentResource;
use App\Filament\Resources\LocationRequests\LocationRequestResource;
use App\Filament\Resources\TimeOfficeRequests\TimeOfficeRequestResource;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity HP-1 decisions in the Approval Center: time-office requests and their cancellations,
 * check-in location requests, leave donations and leave encashments. Requests already running a
 * workflow are decided as workflow tasks, so they are not listed twice.
 */
final class TimeOfficeApprovalSource implements ApprovalSource, DescribesApprovalKinds, SupportsSendBack
{
    private const SCAN = 200;

    public function types(): array
    {
        return ['time_office', 'time_office_cancellation', 'location_request', 'leave_donation', 'leave_encashment'];
    }

    /** HROne setup parity P3: request names for Setup → Inbox actions. */
    public function kindLabels(): array
    {
        return ['time_office' => 'Time office request (short leave, on duty, shift change, overtime, comp-off, restricted holiday)', 'time_office_cancellation' => 'Time office cancellation',
            'location_request' => 'Check-in location request', 'leave_donation' => 'Leave donation', 'leave_encashment' => 'Leave encashment'];
    }

    /** HROne setup parity P3: a pending time-office request may go back to its requester (it stays pending meanwhile). */
    public function sendBackTypes(): array
    {
        return ['time_office'];
    }

    public function pending(User $user, ?int $about = null): array
    {
        return [...$this->timeOffice($user, $about), ...$this->locations($user, $about), ...$this->donations($user, $about), ...$this->encashments($user, $about)];
    }

    public function decide(ApprovalItem $item, User $user, string $decision, ?string $note): string
    {
        $record = $item->record;
        $approve = $decision === 'approve';

        return match ($item->type) {
            'time_office' => tap($approve ? 'Approved' : 'Rejected', fn () => $approve
                ? app(TimeOfficeRequests::class)->approve($record, $note, $user)
                : app(TimeOfficeRequests::class)->reject($record, (string) $note, $user)),
            'time_office_cancellation' => tap($approve ? 'Cancellation approved' : 'Request kept', fn () => $approve
                ? app(TimeOfficeRequests::class)->approveCancellation($record, $note, $user)
                : app(TimeOfficeRequests::class)->rejectCancellation($record, (string) $note, $user)),
            'location_request' => tap($approve ? 'Location approved' : 'Location rejected', fn () => $approve
                ? app(GeoLocations::class)->approve($record, $note, $user)
                : app(GeoLocations::class)->reject($record, (string) $note, $user)),
            'leave_donation' => tap($approve ? 'Donation approved' : 'Donation rejected', fn () => $approve
                ? app(LeaveDonations::class)->approve($record, $note, $user)
                : app(LeaveDonations::class)->reject($record, (string) $note, $user)),
            'leave_encashment' => tap($approve ? 'Encashment approved' : 'Encashment rejected', fn () => $this->asUser($user, fn () => $approve
                ? app(LeaveAdjustments::class)->approveEncashment($record, $note)
                : app(LeaveAdjustments::class)->rejectEncashment($record, (string) $note))),
            default => throw new AuthorizationException('Unsupported item.'),
        };
    }

    /** @return list<ApprovalItem> */
    private function timeOffice(User $user, ?int $about): array
    {
        if (! $user->hasPermission('attendance.approve') && ! $user->hasPermission('attendance.manage')) {
            return [];
        }
        $rows = TimeOfficeRequest::query()->with(['employee.person', 'requester', 'shift', 'holiday'])
            ->whereIn('status', ['pending', 'cancel_requested'])->when($about !== null, fn ($q) => $q->where('employee_id', $about))
            ->orderBy('from_date')->limit(self::SCAN)->get();
        $rows = $this->withoutWorkflow($rows, TimeOfficeRequest::class);
        app(AccessScopes::class)->primeEmployeeIds($user, $rows->pluck('employee_id'));

        $items = [];
        foreach ($rows as $r) {
            if (! $user->can('approve', $r)) {
                continue;
            }
            $cancellation = $r->status === 'cancel_requested';
            $items[] = new ApprovalItem(
                id: ($cancellation ? 'time_office_cancellation:' : 'time_office:').$r->id,
                type: $cancellation ? 'time_office_cancellation' : 'time_office',
                typeLabel: 'Time office',
                title: ($cancellation ? 'Cancel '.strtolower($r->typeLabel()) : $r->typeLabel()).' · '.$this->span($r),
                subject: $r->employee?->display_name,
                subjectEmployeeId: $r->employee_id,
                reason: $cancellation ? $r->cancel_reason : $r->reason,
                impact: $this->impact($r, $cancellation),
                effectiveOn: $r->from_date,
                dueAt: ($cancellation ? $r->cancel_requested_at : $r->created_at)?->copy()->addDays(2),
                requestedAt: $cancellation ? $r->cancel_requested_at : $r->created_at,
                requestedBy: $r->requester?->name,
                risk: $r->from_date !== null && $r->from_date->lt(now()->subDays(20)) ? 'high' : 'normal',
                riskReason: $r->from_date !== null && $r->from_date->lt(now()->subDays(20)) ? 'Close to the payroll cut-off' : null,
                decisions: ['approve', 'reject'],
                url: $this->url(fn () => TimeOfficeRequestResource::getUrl('index')),
                record: $r,
                changes: $this->changes($r),
                decisionLabels: $cancellation ? ['approve' => 'Approve cancellation', 'reject' => 'Keep request'] : [],
            );
        }

        return $items;
    }

    /** @return list<ApprovalItem> */
    private function locations(User $user, ?int $about): array
    {
        if (! $user->hasPermission('attendance.approve') && ! $user->hasPermission('attendance.manage')) {
            return [];
        }
        $rows = $this->withoutWorkflow(LocationRequest::query()->with(['employee.person', 'requester'])->where('status', 'pending')
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(self::SCAN)->get(), LocationRequest::class);
        app(AccessScopes::class)->primeEmployeeIds($user, $rows->pluck('employee_id'));

        return $rows->filter(fn (LocationRequest $r) => $user->can('approve', $r))->map(fn (LocationRequest $r) => new ApprovalItem(
            id: 'location_request:'.$r->id, type: 'location_request', typeLabel: 'Time office',
            title: 'Check-in location · '.$r->name, subject: $r->employee?->display_name, subjectEmployeeId: $r->employee_id,
            reason: $r->reason, impact: 'The employee may check in within '.$r->radius_m.' m of this place', effectiveOn: null,
            dueAt: $r->created_at?->copy()->addDays(2), requestedAt: $r->created_at, requestedBy: $r->requester?->name, risk: 'normal', riskReason: null,
            decisions: ['approve', 'reject'], url: $this->url(fn () => LocationRequestResource::getUrl('index')), record: $r,
            changes: [['label' => 'Place', 'before' => null, 'after' => sprintf('%.5f, %.5f (%d m)', $r->latitude, $r->longitude, $r->radius_m)]],
        ))->values()->all();
    }

    /** @return list<ApprovalItem> */
    private function donations(User $user, ?int $about): array
    {
        if (! $user->hasPermission('leave.approve')) {
            return [];
        }
        $rows = $this->withoutWorkflow(LeaveDonation::query()->with(['employee.person', 'recipient.person', 'leaveType', 'requester'])->where('status', 'pending')
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(self::SCAN)->get(), LeaveDonation::class);
        app(AccessScopes::class)->primeEmployeeIds($user, $rows->pluck('employee_id'));

        return $rows->filter(fn (LeaveDonation $d) => $user->can('approve', $d))->map(fn (LeaveDonation $d) => new ApprovalItem(
            id: 'leave_donation:'.$d->id, type: 'leave_donation', typeLabel: 'Leave',
            title: 'Leave donation · '.$this->days((float) $d->days).' '.($d->leaveType?->code ?? ''), subject: $d->employee?->display_name, subjectEmployeeId: $d->employee_id,
            reason: $d->reason, impact: 'Moves '.$this->days((float) $d->days).' of '.($d->leaveType?->name ?? 'leave').' to '.($d->recipient?->display_name ?? 'a colleague'), effectiveOn: null,
            dueAt: $d->created_at?->copy()->addDays(3), requestedAt: $d->created_at, requestedBy: $d->requester?->name, risk: 'normal', riskReason: null,
            decisions: ['approve', 'reject'], url: $this->url(fn () => LeaveDonationResource::getUrl('index')), record: $d,
        ))->values()->all();
    }

    /** @return list<ApprovalItem> */
    private function encashments(User $user, ?int $about): array
    {
        if (! $user->hasPermission('leave.manage')) {
            return [];
        }
        $rows = $this->withoutWorkflow(LeaveEncashment::query()->with(['employee.person', 'leaveType'])->where('status', 'pending')
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(self::SCAN)->get(), LeaveEncashment::class);
        app(AccessScopes::class)->primeEmployeeIds($user, $rows->pluck('employee_id'));

        return $rows->filter(fn (LeaveEncashment $e) => $user->can('approve', $e))->map(fn (LeaveEncashment $e) => new ApprovalItem(
            id: 'leave_encashment:'.$e->id, type: 'leave_encashment', typeLabel: 'Leave',
            title: 'Encash '.$this->days((float) $e->days).' '.($e->leaveType?->code ?? ''), subject: $e->employee?->display_name, subjectEmployeeId: $e->employee_id,
            reason: $e->reason, impact: 'Takes '.$this->days((float) $e->days).' off the '.($e->leaveType?->name ?? 'leave').' balance for payment', effectiveOn: null,
            dueAt: $e->created_at?->copy()->addDays(5), requestedAt: $e->created_at, requestedBy: null, risk: 'normal', riskReason: null,
            decisions: ['approve', 'reject'], url: $this->url(fn () => LeaveEncashmentResource::getUrl('index')), record: $e,
        ))->values()->all();
    }

    /**
     * @template T of Model
     *
     * @param  Collection<int, T>  $rows
     * @return Collection<int, T>
     */
    private function withoutWorkflow(Collection $rows, string $class): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }
        $running = WorkflowInstance::query()->whereIn('subject_type', [$class, (new $class)->getMorphClass()])->whereIn('subject_id', $rows->pluck('id'))
            // HROne setup parity P3: a run sent back to its initiator still owns the decision.
            ->whereIn('status', [InstanceStatus::Running, InstanceStatus::Returned])->pluck('subject_id')->map(fn ($id) => (int) $id)->all();

        return $rows->reject(fn (Model $r) => in_array((int) $r->getKey(), $running, true))->values();
    }

    private function span(TimeOfficeRequest $r): string
    {
        $span = $r->from_date?->format('D, d M');
        if ($r->to_date && $r->from_date && ! $r->to_date->equalTo($r->from_date)) {
            $span .= ' – '.$r->to_date->format('D, d M');
        }
        if ($r->start_time && $r->end_time) {
            $span .= ' · '.substr((string) $r->start_time, 0, 5).'–'.substr((string) $r->end_time, 0, 5);
        }

        return (string) $span;
    }

    private function impact(TimeOfficeRequest $r, bool $cancellation): string
    {
        if ($cancellation) {
            return $r->type === 'comp_off' ? 'The comp-off credit is taken back' : 'Attendance is recalculated without this request';
        }

        return match ($r->type) {
            'comp_off' => 'Credits '.$this->days((float) $r->days).' of comp-off'.($r->expires_on ? ', valid until '.$r->expires_on->format('d M Y') : ''),
            'shift_change' => 'The employee works '.($r->shift?->name ?? 'another shift').' on these days',
            'overtime' => $r->minutes.' minutes become approved overtime',
            'restricted_holiday' => ($r->holiday?->name ?? 'The optional holiday').' becomes a holiday for the employee',
            default => 'Attendance for these days is recalculated on approval',
        };
    }

    /** @return list<array{label: string, before: ?string, after: string}> */
    private function changes(TimeOfficeRequest $r): array
    {
        return array_values(array_filter([
            $r->minutes ? ['label' => $r->type === 'comp_off' ? 'Worked' : 'Minutes', 'before' => null, 'after' => (string) $r->minutes] : null,
            $r->shift ? ['label' => 'Shift', 'before' => null, 'after' => $r->shift->name] : null,
            $r->type === 'on_duty' && $r->session !== 'full' ? ['label' => 'Part', 'before' => null, 'after' => str_replace('_', ' ', (string) $r->session)] : null,
        ]));
    }

    private function days(float $days): string
    {
        return rtrim(rtrim(number_format($days, 2), '0'), '.').' day'.($days == 1.0 ? '' : 's');
    }

    private function url(Closure $resolve): ?string
    {
        try {
            return $resolve();
        } catch (Throwable) {
            return null;
        }
    }

    /** Encashment decisions record the signed-in user as reviewer; make sure that is the deciding user. */
    private function asUser(User $user, Closure $callback): mixed
    {
        if (auth()->id() === $user->id) {
            return $callback();
        }
        $previous = auth()->user();
        auth()->setUser($user);
        try {
            return $callback();
        } finally {
            $previous ? auth()->setUser($previous) : auth()->forgetUser();
        }
    }
}
