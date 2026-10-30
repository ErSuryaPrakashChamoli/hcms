<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Pages\MyDay;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity RQ-1: resignation (My day → "Resign"). A resignation waits for acceptance only while an approval workflow
 * runs on it (P5); otherwise the notice period runs at once, so an open resignation counts as approved.
 */
final class ResignationRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'resignation';
    }

    public function label(): string
    {
        return 'Resignation';
    }

    public function description(): string
    {
        return 'Resign and see your notice period';
    }

    public function module(): string
    {
        return 'Separation';
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-right-start-on-rectangle';
    }

    public function keywords(): array
    {
        return ['resign', 'resignation', 'separation', 'quit', 'notice'];
    }

    public function permission(): string
    {
        return 'exit.resign';
    }

    /** The same rule as My day's "Resign": exit.resign, employed, not serving notice, no open exit. */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && MyDay::canAccess() && $user->can('exit.resign')
            && $employee->lifecycle_state->isEmployed() && $employee->lifecycle_state->value !== 'notice_period'
            && ExitCase::query()->where('employee_id', $employee->getKey())->whereIn('status', ExitCase::OPEN)->doesntExist();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(MyDay::getUrl(), 'resign');
    }

    public function model(): ?string
    {
        return ExitCase::class;
    }

    protected function resource(): ?string
    {
        return ExitCaseResource::class;
    }

    public function ownRecords(User $user, ?Employee $employee): ?Builder
    {
        return parent::ownRecords($user, $employee)?->where('exit_cases.type', 'resignation');
    }

    /** Module statuses; open ones are Pending only while a workflow decides the resignation (see whereStatus()). */
    public function statusMap(): array
    {
        return [
            'initiated' => RequestStatus::Approved, 'notice' => RequestStatus::Approved, 'clearance' => RequestStatus::Approved,
            'settlement' => RequestStatus::Approved, 'completed' => RequestStatus::Approved,
            'withdrawn' => RequestStatus::Withdrawn, 'cancelled' => RequestStatus::Withdrawn,
        ];
    }

    public function status(Model $record): RequestStatus
    {
        /** @var ExitCase $record */
        if ($this->waitsForAcceptance($record)) {
            return RequestStatus::Pending;
        }

        return parent::status($record);
    }

    public function whereStatus(Builder $query, RequestStatus $status): Builder
    {
        $waiting = fn (Builder $q) => $q->whereIn('exit_cases.status', ExitCase::OPEN)->whereNull('exit_cases.resignation_accepted_at')->whereNotNull('exit_cases.workflow_instance_id');

        return match ($status) {
            RequestStatus::Pending => $waiting($query),
            RequestStatus::Approved => parent::whereStatus($query, $status)->whereNot(fn (Builder $q) => $waiting($q)),
            default => parent::whereStatus($query, $status),
        };
    }

    public function countByStatus(Builder $query): array
    {
        $counts = [];
        foreach ((clone $query)->reorder()->get() as $record) {
            $status = $this->status($record)->value;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    public function statusLabel(Model $record): string
    {
        return $this->waitsForAcceptance($record) ? 'Waiting for acceptance' : (string) (config('peopleos.exit.statuses')[$this->rawStatus($record)] ?? $this->rawStatus($record));
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Resigned on', 'column' => 'created_at'],
            'effective' => ['label' => 'Last working day', 'column' => 'last_working_day'],
            'decided' => ['label' => 'Accepted on', 'column' => 'resignation_accepted_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['number', 'reason'];
    }

    public function summary(Model $record): array
    {
        /** @var ExitCase $record */
        return [
            'title' => $this->join($record->number, 'Resignation', $record->last_working_day ? 'last day '.$record->last_working_day->format('d M Y') : null),
            'detail' => $this->short($record->reason),
        ];
    }

    private function waitsForAcceptance(Model $record): bool
    {
        $attributes = $record->getAttributes();

        return in_array($attributes['status'] ?? null, ExitCase::OPEN, true) && ($attributes['resignation_accepted_at'] ?? null) === null && ($attributes['workflow_instance_id'] ?? null) !== null;
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['exit.resignation_submitted'];
    }

    public function builtInApprovers(): string
    {
        return 'HR (exit cases), unless an approval workflow runs on resignations';
    }

    public function approverPermissions(): array
    {
        return [];
    }

    public function knowledgeCategory(): ?string
    {
        return 'hr_policy';
    }
}
