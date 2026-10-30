<?php

namespace App\Domain\Experience\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\RequestType;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Services\PerformanceRelationships;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * HROne parity RQ-1: "My requests" — one read model over every registered request type (HROne's request tracker:
 * Pending / Approved / Rejected / Undo / Drafts, a request-type filter, date ranges and a search).
 *
 * Own requests: each type's ownRecords() query (the model's tenant and access scopes apply), only for types whose module
 * list opens for the person (historyVisibleTo). A team member's requests (a manager looking at a report): only types
 * whose records belong to an employee, each record kept only when the module's own `view` policy lets the viewer see it
 * (fail closed). Read only: rows link to the module screen where the record is acted on.
 */
final class MyRequests
{
    public const PAGE = 25;

    /** Records read per type for a team member (each is authorised on its own). */
    public const TEAM_SCAN = 200;

    public const TABS = ['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn', 'draft' => 'Drafts'];

    public function __construct(private readonly RequestRegistry $registry, private readonly ExperiencePreferences $preferences) {}

    /**
     * @param  array{tab?: ?string, type?: ?string, date?: ?string, from?: ?string, until?: ?string, search?: ?string, hide_closed?: bool, show_hidden?: bool, limit?: int}  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, counts: array<string, int>, types: array<string, array{label: string, module: string, count: int}>, more: bool, hidden: int, team: bool}
     */
    public function for(User $viewer, ?Employee $subject = null, array $filters = []): array
    {
        $empty = ['rows' => collect(), 'counts' => array_fill_keys(array_keys(self::TABS), 0), 'types' => [], 'more' => false, 'hidden' => 0, 'team' => false];
        $own = $this->registry->employeeOf($viewer);
        if (! $this->signedIn($viewer)) {
            return $empty;
        }
        $team = $subject !== null && ($own === null || (int) $subject->getKey() !== (int) $own->getKey());
        if ($team && ! $this->mayFollow($viewer, $subject)) {
            return $empty;
        }
        $employee = $team ? $subject : $own;
        $hidden = $team ? [] : $this->hiddenKeys($viewer);
        $tab = array_key_exists((string) ($filters['tab'] ?? 'all'), self::TABS) ? (string) ($filters['tab'] ?? 'all') : 'all';
        $limit = max(1, (int) ($filters['limit'] ?? self::PAGE));

        $rows = collect();
        $counts = array_fill_keys(array_keys(self::TABS), 0);
        $types = [];
        $hiddenCount = 0;
        $chosen = filled($filters['type'] ?? null) ? (string) $filters['type'] : null;
        foreach ($this->types($viewer, $team) as $type) {
            $query = $this->attempt(fn () => $type->ownRecords($viewer, $employee));
            if (! $query instanceof Builder || ! $this->narrow($query, $type, $filters)) {
                continue;
            }
            if ($chosen !== null && $chosen !== $type->key()) {
                // Another type is chosen: this one only counts towards the request-type filter's options.
                $count = $team ? array_sum($this->teamRows($viewer, $type, $query, 'all', [])[1]) : (int) ($this->attempt(fn () => (clone $query)->count()) ?? 0);
                $types[$type->key()] = ['label' => $type->label(), 'module' => $type->module(), 'count' => $count];

                continue;
            }
            if ($team) {
                [$typeRows, $typeCounts] = $this->teamRows($viewer, $type, $query, $tab, $filters);
            } else {
                $hiddenIds = $hidden[$type->key()] ?? [];
                $hiddenCount += $hiddenIds === [] ? 0 : (clone $query)->whereKey($hiddenIds)->count();
                if (! ($filters['show_hidden'] ?? false) && $hiddenIds !== []) {
                    $query->whereKeyNot($hiddenIds);
                }
                $typeCounts = $this->attempt(fn () => $type->countByStatus(clone $query)) ?? [];
                $typeRows = $this->ownRows($viewer, $type, $query, $tab, $filters, $limit, $hidden[$type->key()] ?? []);
            }
            $total = array_sum($typeCounts);
            $types[$type->key()] = ['label' => $type->label(), 'module' => $type->module(), 'count' => $total];
            foreach ($typeCounts as $status => $count) {
                $counts[$status] = ($counts[$status] ?? 0) + $count;
            }
            $counts['all'] += ($filters['hide_closed'] ?? false) ? ($typeCounts['pending'] ?? 0) + ($typeCounts['draft'] ?? 0) : $total;
            $rows = $rows->merge($typeRows);
        }

        $rows = $rows->sortByDesc(fn (array $row) => [$row['requested']?->getTimestamp() ?? 0, $row['id']])->values();
        $more = $rows->count() > $limit || ($counts[$tab] ?? 0) > $limit;

        return ['rows' => $rows->take($limit)->values(), 'counts' => $counts, 'types' => $types, 'more' => $more, 'hidden' => $hiddenCount, 'team' => $team];
    }

    /**
     * The person's own requests waiting on someone else (Pending), newest first, for My work and the request forms.
     *
     * @param  list<string>  $only  type keys to read (empty = all)
     * @param  list<string>  $except  type keys to leave out
     * @return Collection<int, array<string, mixed>>
     */
    public function pending(User $user, array $only = [], array $except = [], int $perType = 10, ?string $family = null): Collection
    {
        $employee = $this->registry->employeeOf($user);
        if (! $this->signedIn($user)) {
            return collect();
        }
        $rows = collect();
        foreach ($this->types($user, false) as $type) {
            if (($only !== [] && ! in_array($type->key(), $only, true)) || in_array($type->key(), $except, true) || ($family !== null && $type->family() !== $family)) {
                continue;
            }
            $query = $this->attempt(fn () => $type->ownRecords($user, $employee));
            if ($query instanceof Builder) {
                $rows = $rows->merge($this->ownRows($user, $type, $query, RequestStatus::Pending->value, [], $perType, []));
            }
        }

        return $rows->sortByDesc(fn (array $row) => [$row['requested']?->getTimestamp() ?? 0, $row['id']])->values();
    }

    /** Whether the viewer may follow this person's requests: their own, or a current report of theirs (PerformanceRelationships). */
    public function mayFollow(User $viewer, Employee $subject): bool
    {
        $own = $this->registry->employeeOf($viewer);
        if ($own !== null && (int) $own->getKey() === (int) $subject->getKey()) {
            return true;
        }
        $relationships = app(PerformanceRelationships::class);

        return $own !== null && $relationships->manages($own, $subject->getKey());
    }

    /** @return Collection<int, Employee> the people whose requests the viewer may follow besides their own (their team) */
    public function team(User $viewer): Collection
    {
        $own = $this->registry->employeeOf($viewer);
        if ($own === null) {
            return collect();
        }
        $ids = app(PerformanceRelationships::class)->reportIds($own)->all();

        return $ids === [] ? collect() : Employee::query()->with('person')->whereKey($ids)->get()->sortBy(fn (Employee $e) => $e->person?->display_name)->values();
    }

    // -- internals -------------------------------------------------------------------------------------------------

    /** @return list<RequestType> */
    private function types(User $viewer, bool $team): array
    {
        return array_values(array_filter($this->registry->all(), function (RequestType $type) use ($viewer, $team) {
            if ($type->model() === null) {
                return false;
            }
            if ($team) {
                return $type->ownedByEmployee() && (bool) $this->attempt(fn () => Gate::forUser($viewer)->allows('viewAny', $type->model()));
            }

            return (bool) $this->attempt(fn () => $type->historyVisibleTo($viewer));
        }));
    }

    /** Apply the date range and the search; false when the type cannot match them (no such date, nothing to search). */
    private function narrow(Builder $query, RequestType $type, array $filters): bool
    {
        $from = $this->day($filters['from'] ?? null);
        $until = $this->day($filters['until'] ?? null);
        if ($from !== null || $until !== null) {
            $column = $type->dateFilters()[(string) ($filters['date'] ?? 'requested')]['column'] ?? null;
            if ($column === null) {
                return false;
            }
            $column = $query->getModel()->qualifyColumn($column);
            if ($from !== null) {
                $query->whereDate($column, '>=', $from->toDateString());
            }
            if ($until !== null) {
                $query->whereDate($column, '<=', $until->toDateString());
            }
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $columns = $type->searchColumns();
            $id = ctype_digit($search) ? (int) $search : null;
            if ($columns === [] && $id === null) {
                return false;
            }
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 100)).'%';
            $query->where(function (Builder $q) use ($columns, $like, $id) {
                foreach ($columns as $column) {
                    $q->orWhere($q->getModel()->qualifyColumn($column), 'like', $like);
                }
                if ($id !== null) {
                    $q->orWhere($q->getModel()->getQualifiedKeyName(), $id);
                }
            });
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    private function ownRows(User $viewer, RequestType $type, Builder $query, string $tab, array $filters, int $limit, array $hiddenIds): array
    {
        $query = clone $query;
        if ($tab !== 'all') {
            $type->whereStatus($query, RequestStatus::from($tab));
        } elseif ($filters['hide_closed'] ?? false) {
            $query->where(fn (Builder $q) => $q->where(fn (Builder $a) => $type->whereStatus($a, RequestStatus::Pending))->orWhere(fn (Builder $b) => $type->whereStatus($b, RequestStatus::Draft)));
        }
        $requested = $type->dateFilters()['requested']['column'] ?? 'created_at';
        $records = $this->attempt(fn () => $query->orderByDesc($query->getModel()->qualifyColumn($requested))->orderByDesc($query->getModel()->getQualifiedKeyName())->limit($limit + 1)->get()) ?? collect();

        return $records->map(fn (Model $record) => $this->row($viewer, $type, $record, in_array($record->getKey(), $hiddenIds, false)))->filter()->values()->all();
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, int>} */
    private function teamRows(User $viewer, RequestType $type, Builder $query, string $tab, array $filters): array
    {
        $requested = $type->dateFilters()['requested']['column'] ?? 'created_at';
        $records = $this->attempt(fn () => (clone $query)->orderByDesc($query->getModel()->qualifyColumn($requested))->limit(self::TEAM_SCAN)->get()) ?? collect();
        $records = $records->filter(fn (Model $record) => (bool) $this->attempt(fn () => Gate::forUser($viewer)->allows('view', $record)));
        $counts = [];
        $rows = [];
        foreach ($records as $record) {
            $status = $type->status($record);
            $counts[$status->value] = ($counts[$status->value] ?? 0) + 1;
            $visible = $tab === 'all' ? (! ($filters['hide_closed'] ?? false) || ! $status->isClosed()) : $status->value === $tab;
            if ($visible && ($row = $this->row($viewer, $type, $record, false)) !== null) {
                $rows[] = $row;
            }
        }

        return [$rows, $counts];
    }

    /** @return array<string, mixed>|null */
    private function row(User $viewer, RequestType $type, Model $record, bool $hidden): ?array
    {
        try {
            $summary = $type->summary($record);
            $dates = $type->dates($record);
            $status = $type->status($record);

            return [
                'key' => $type->key().':'.$record->getKey(), 'id' => (int) $record->getKey(), 'type' => $type->key(), 'type_label' => $type->label(),
                'module' => $type->module(), 'icon' => $type->icon(), 'title' => (string) $summary['title'], 'detail' => $summary['detail'] ?? null,
                'status' => $status, 'status_label' => $type->statusLabel($record), 'requested' => $dates['requested'], 'effective' => $dates['effective'],
                'decided' => $dates['decided'], 'url' => $type->recordUrl($viewer, $record), 'hidden' => $hidden,
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array<string, list<int>> type key => hidden record ids (the person's "Move to archive") */
    private function hiddenKeys(User $user): array
    {
        $out = [];
        foreach ((array) ($this->preferences->for($user)['hidden_requests'] ?? []) as $key) {
            [$type, $id] = array_pad(explode(':', (string) $key, 2), 2, null);
            if ($type !== null && ctype_digit((string) $id)) {
                $out[$type][] = (int) $id;
            }
        }

        return $out;
    }

    private function day(mixed $value): ?CarbonInterface
    {
        try {
            return filled($value) ? Carbon::parse((string) $value)->startOfDay() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function signedIn(User $user): bool
    {
        return auth()->check() && (int) auth()->id() === (int) $user->getKey();
    }

    private function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
