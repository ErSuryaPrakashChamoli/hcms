<?php

namespace App\Domain\Organisation\Resolution\Index;

use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Resolution\MasterSpec;
use App\Domain\Organisation\Resolution\NameKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A master loaded whole, once per session: one query for its rows, one for the actor's access (only when the actor is
 * scoped) and one per company asked about, through the applicability framework (EntityApplicability::constrain, which
 * covers company-owned units, explicit company choices and companies that joined later). Masters are small (a few
 * hundred to a few thousand rows), so every later lookup is in memory.
 */
final class MasterIndex implements ReferenceIndex
{
    /** Statuses that mean "not in use". Used to prefer the live record among namesakes and to warn, never to refuse. */
    public const INACTIVE = ['inactive', 'archived', 'retired', 'disabled', 'closed', 'ended', 'deprecated', 'superseded', 'deleted'];

    /** @var array<int, array{id: int, name: string, code: ?string, owner: ?int, live: bool, hints: array<string, ?int>}> */
    private array $rows = [];

    /** @var array<string, int> */
    private array $codes = [];

    /** @var array<string, list<int>> */
    private array $names = [];

    /** @var array<int, array<int, true>> company id => ids that apply to it */
    private array $applicable = [];

    public function __construct(private readonly MasterSpec $spec, private readonly ?User $actor, private readonly string $on)
    {
        $this->load();
    }

    public function byCode(string $key, string $typed): ?int
    {
        return $this->codes[$key] ?? null;
    }

    public function byName(string $key, string $typed): array
    {
        return $this->names[$key] ?? [];
    }

    public function row(int $id): array
    {
        return $this->rows[$id];
    }

    public function find(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function appliesTo(int $id, int $companyId): bool
    {
        if (! $this->spec->applicability) {
            return true;
        }
        if (! isset($this->applicable[$companyId])) {
            /** @var Model $model */
            $model = new ($this->spec->model);
            $query = $model->newQueryWithoutScope(AccessScope::class)->select($model->qualifyColumn($model->getKeyName()));
            $ids = app(EntityApplicability::class)->constrain($query, $companyId, $this->on)->toBase()->pluck($model->getKeyName());
            $this->applicable[$companyId] = array_fill_keys($ids->map(fn ($v) => (int) $v)->all(), true);
        }

        return isset($this->applicable[$companyId][$id]);
    }

    public function suggestible(): iterable
    {
        return $this->rows;
    }

    private function load(): void
    {
        /** @var Model $model */
        $model = new ($this->spec->model);
        $table = $model->getTable();
        $status = Columns::has($table, 'status') ? 'status' : null;
        $from = Columns::has($table, 'effective_from') ? 'effective_from' : null;
        $to = Columns::has($table, 'effective_to') ? 'effective_to' : null;
        $names = Columns::existing($table, $this->spec->nameColumns);
        $hints = Columns::existing($table, $this->spec->hintColumns);
        $code = $this->spec->codeColumn !== null && Columns::has($table, $this->spec->codeColumn) ? $this->spec->codeColumn : null;
        $select = Columns::existing($table, [$model->getKeyName(), $code, ...$names, ...$hints, $status, $from, $to]);

        $visible = ActorVisibility::ids($this->spec->model, $this->actor);
        $day = substr($this->on, 0, 10);

        foreach ($model->newQueryWithoutScope(AccessScope::class)->select($select)->where($this->spec->where)->toBase()->cursor() as $raw) {
            $id = (int) $raw->{$model->getKeyName()};
            if ($visible !== null && ! isset($visible[$id])) {
                continue; // outside the actor's access: never matched, suggested or named
            }
            $live = ($status === null || ! in_array(mb_strtolower((string) $raw->{$status}), self::INACTIVE, true))
                && ($from === null || $raw->{$from} === null || substr((string) $raw->{$from}, 0, 10) <= $day)
                && ($to === null || $raw->{$to} === null || substr((string) $raw->{$to}, 0, 10) >= $day);
            $row = [
                'id' => $id,
                'name' => (string) ($names !== [] ? ($raw->{$names[0]} ?? '') : ''),
                'code' => $code !== null && $raw->{$code} !== null ? (string) $raw->{$code} : null,
                'owner' => $this->spec->ownerColumn !== null && isset($raw->{$this->spec->ownerColumn}) ? (int) $raw->{$this->spec->ownerColumn} : null,
                'live' => $live,
                'hints' => array_map(fn ($v) => $v === null ? null : (int) $v, array_intersect_key((array) $raw, array_flip($hints))),
            ];
            $this->rows[$id] = $row;
            if ($row['code'] !== null && ($key = NameKey::key($row['code'])) !== '') {
                $this->codes[$key] = $id;
            }
            foreach ($names as $column) {
                $key = NameKey::key(isset($raw->{$column}) ? (string) $raw->{$column} : null);
                if ($key !== '' && ! in_array($id, $this->names[$key] ?? [], true)) {
                    $this->names[$key][] = $id;
                }
            }
        }
    }
}
