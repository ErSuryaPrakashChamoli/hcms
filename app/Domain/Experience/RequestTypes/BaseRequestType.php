<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\DescribesApprovalRoute;
use App\Domain\Experience\Contracts\RequestType;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * HROne parity RQ-1: defaults for request types. A type names its model, its status map and date columns; the
 * records query is the model's own query (tenant and access scopes apply) narrowed to the person's employee id, and a
 * record opens on the module's view page when the person may view it, else on the module's list.
 *
 * HROne parity RQ-2: also the neutral answers of DescribesApprovalRoute (no raised events, no knowledge-base category, the
 * record itself is what waits), which adapters override with what their module does.
 */
abstract class BaseRequestType implements DescribesApprovalRoute, RequestType
{
    public function description(): string
    {
        return '';
    }

    public function keywords(): array
    {
        return [];
    }

    public function onBehalfPermission(): ?string
    {
        return null;
    }

    public function onBehalfUrl(User $user): ?string
    {
        return null;
    }

    public function model(): ?string
    {
        return null;
    }

    public function family(): ?string
    {
        return null;
    }

    public function ownedByEmployee(): bool
    {
        return true;
    }

    public function statusMap(): array
    {
        return [];
    }

    public function searchColumns(): array
    {
        return [];
    }

    public function dateFilters(): array
    {
        return ['requested' => ['label' => 'Requested on', 'column' => 'created_at']];
    }

    /** The Filament resource whose list (and view page) shows these records, if any. */
    protected function resource(): ?string
    {
        return null;
    }

    /** The column holding the module status. */
    protected function statusColumn(): string
    {
        return 'status';
    }

    /** The column holding the employee the request is for. */
    protected function employeeColumn(): string
    {
        return 'employee_id';
    }

    /** @return list<string> relations the summary reads (eager-loaded: strict mode forbids lazy loading) */
    protected function with(): array
    {
        return [];
    }

    public function historyVisibleTo(User $user): bool
    {
        $resource = $this->resource();

        return $resource !== null && $this->signedIn($user) && $resource::canAccess();
    }

    public function ownRecords(User $user, ?Employee $employee): ?Builder
    {
        $model = $this->model();
        if ($model === null || $employee === null || ! $employee->exists) {
            return null;
        }
        /** @var Model $instance */
        $instance = new $model;

        return $model::query()->with($this->with())->where($instance->qualifyColumn($this->employeeColumn()), $employee->getKey());
    }

    public function whereStatus(Builder $query, RequestStatus $status): Builder
    {
        $values = array_keys(array_filter($this->statusMap(), fn (RequestStatus $mapped) => $mapped === $status));

        return $values === [] ? $query->whereRaw('1 = 0') : $query->whereIn($query->getModel()->qualifyColumn($this->statusColumn()), $values);
    }

    public function countByStatus(Builder $query): array
    {
        $column = $query->getModel()->qualifyColumn($this->statusColumn());
        $rows = (clone $query)->reorder()->toBase()->select($column.' as tracker_status')->selectRaw('count(*) as aggregate')->groupBy($column)->get();
        $counts = [];
        foreach ($rows as $row) {
            $status = $this->statusMap()[(string) $row->tracker_status] ?? null;
            if ($status !== null) {
                $counts[$status->value] = ($counts[$status->value] ?? 0) + (int) $row->aggregate;
            }
        }

        return $counts;
    }

    public function status(Model $record): RequestStatus
    {
        return $this->statusMap()[$this->rawStatus($record)] ?? RequestStatus::Pending;
    }

    public function statusLabel(Model $record): string
    {
        return $this->statusText($this->rawStatus($record));
    }

    public function statusField(): string
    {
        return $this->statusColumn();
    }

    public function statusText(string $raw): string
    {
        $model = $this->model();
        $labels = $model !== null && defined($model.'::STATUSES') ? (array) constant($model.'::STATUSES') : [];

        return (string) ($labels[$raw] ?? ucfirst(str_replace('_', ' ', $raw)));
    }

    public function dates(Model $record): array
    {
        $filters = $this->dateFilters();
        $read = fn (?string $kind) => $kind !== null && isset($filters[$kind]) ? $this->date($record, $filters[$kind]['column']) : null;

        return ['requested' => $read('requested'), 'effective' => $read('effective'), 'decided' => $read('decided')];
    }

    public function recordUrl(User $user, Model $record): ?string
    {
        $resource = $this->resource();
        if ($resource === null || ! $this->signedIn($user) || ! $resource::canAccess()) {
            return null;
        }
        try {
            if ($resource::hasPage('view') && $user->can('view', $record)) {
                return $resource::getUrl('view', ['record' => $record]);
            }

            return $resource::getUrl('index');
        } catch (Throwable) {
            return null;
        }
    }

    // -- approval route (RQ-2) ---------------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return [];
    }

    public function workflowSubject(): ?string
    {
        return $this->model();
    }

    public function builtInApprovers(): string
    {
        return 'The people who approve '.mb_strtolower($this->label()).' requests, in their Approval Center';
    }

    public function approverPermissions(): array
    {
        return [];
    }

    public function approvalKind(Model $record): ?string
    {
        return null;
    }

    public function waitingRecords(Model $record): array
    {
        return [$record];
    }

    public function knowledgeCategory(): ?string
    {
        return null;
    }

    public function owns(Model $record): bool
    {
        $model = $this->model();

        return $model !== null && $record instanceof $model;
    }

    public function employeeOf(Model $record): ?Employee
    {
        $id = $record->getAttributes()[$this->employeeColumn()] ?? null;

        return $id ? Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->find($id) : null;
    }

    /** "cancel_requested" waits as a cancellation (the Approval Center lists it under the kind's cancellation). */
    protected function kindByStatus(Model $record, string $kind, ?string $cancellation = null): string
    {
        return $cancellation !== null && $this->rawStatus($record) === 'cancel_requested' ? $cancellation : $kind;
    }

    // -- helpers ---------------------------------------------------------------------------------------------------

    protected function rawStatus(Model $record): string
    {
        $value = $record->getAttributes()[$this->statusColumn()] ?? null;

        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }

    protected function date(Model $record, string $column): ?CarbonInterface
    {
        if (! array_key_exists($column, $record->getAttributes())) {
            return null;
        }
        $value = $record->getAttribute($column);
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        try {
            return filled($value) ? Carbon::parse((string) $value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Destinations check access with static canAccess(), which reads the signed-in person: answer only for them. */
    protected function signedIn(User $user): bool
    {
        return auth()->check() && (int) auth()->id() === (int) $user->getKey();
    }

    /**
     * A page or resource URL that opens one of its existing actions (Filament mounts `?action=` on load).
     *
     * @param  array<string, scalar>  $arguments
     */
    protected function withAction(string $url, string $action, array $arguments = []): string
    {
        $query = ['action' => $action] + ($arguments !== [] ? ['actionArguments' => $arguments] : []);

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    /** A short date range for titles ("12 Mar" or "12 Mar → 14 Mar"). */
    protected function span(?CarbonInterface $from, ?CarbonInterface $to = null): string
    {
        if ($from === null) {
            return '';
        }

        return $from->format('d M Y').($to !== null && ! $to->isSameDay($from) ? ' → '.$to->format('d M Y') : '');
    }

    /** "Title · detail" without empty parts. */
    protected function join(?string ...$parts): string
    {
        return implode(' · ', array_values(array_filter(array_map(fn ($p) => $p === null ? null : trim($p), $parts), fn ($p) => $p !== null && $p !== '')));
    }

    protected function short(?string $text, int $length = 80): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_strimwidth($text, 0, $length, '…');
    }
}
