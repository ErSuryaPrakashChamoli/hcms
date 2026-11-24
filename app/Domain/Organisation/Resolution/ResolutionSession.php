<?php

namespace App\Domain\Organisation\Resolution;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Resolution\Index\EmployeeIndex;
use App\Domain\Organisation\Resolution\Index\MasterIndex;
use App\Domain\Organisation\Resolution\Index\ReferenceIndex;
use App\Domain\Organisation\Resolution\Models\MasterReferenceMapping;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;
use LogicException;

/**
 * Resolves the references of one file (or one worksheet) for one actor. Each master is loaded once, on first use, and
 * every distinct value is decided once, so a 10 000-row file naming 40 departments costs a handful of queries.
 *
 * Order of evidence, the same for every master:
 *  1. the code, compared without case (exactly what code-based imports have always done, so existing files resolve
 *     the same way);
 *  2. the exact name (NameKey::key: spacing, case, typographic quotes and dashes ignored, nothing else);
 *  3. a mapping a person confirmed for this upload, then one remembered for every upload.
 * With a company, only records that apply to it count (the applicability framework decides). With an actor, records
 * outside their organisation access are invisible: never matched, suggested or named. A near miss is SUGGESTED and
 * never applied; several live namesakes are AMBIGUOUS; a name and a code that disagree are a CONFLICT.
 */
final class ResolutionSession
{
    /** The uploads' "remove this link" value (App\Support\Imports\ClearValue::NONE): resolved as Blank. */
    public const CLEAR = '(none)';

    /** @var array<string, ReferenceIndex> */
    private array $indexes = [];

    /** @var array<string, array<string, list<array{company: ?int, scope: ?string, record: int}>>> master => value key => mappings */
    private array $mappings = [];

    /** @var array<string, list<array{name: ?string, code: ?string, name_key: string, code_key: string, company: ?int}>> */
    private array $planned = [];

    /** @var array<string, Resolution> */
    private array $decided = [];

    /** @var array<int, string>|null */
    private ?array $companies = null;

    private readonly int $tenantId;

    private readonly ResolutionReport $report;

    public function __construct(
        private readonly MasterCatalogue $catalogue,
        public readonly ?User $actor,
        public readonly ?string $scope,
        public readonly string $on,
    ) {
        $this->tenantId = app(TenantContext::class)->id() ?? throw new LogicException('Resolving references needs an organisation (no tenant is bound).');
        $this->report = new ResolutionReport($scope);
    }

    /** A record an earlier worksheet of the same workbook will create: it matches as Planned (id -1) until it exists. */
    public function plan(string $master, ?string $name = null, ?string $code = null, ?int $companyId = null): void
    {
        $spec = $this->catalogue->for($master);
        $this->planned[$spec->key][] = ['name' => $name, 'code' => $code, 'name_key' => NameKey::key($name), 'code_key' => NameKey::key($code), 'company' => $companyId];
        $this->decided = [];
    }

    /**
     * Optional, for large files: hand over a column's values before resolving its rows one by one, so a master that is
     * fetched per value (employees) is fetched in a few batches instead. Masters loaded whole need nothing; the
     * answers are the same with or without it.
     *
     * @param  iterable<mixed>  $values
     */
    public function prime(string $master, iterable $values): void
    {
        if (app(TenantContext::class)->id() !== $this->tenantId) {
            throw new LogicException('A resolution session belongs to one organisation; start a new session for another.');
        }
        $index = $this->index($master);
        if (! $index instanceof EmployeeIndex) {
            return;
        }
        $clean = [];
        foreach ($values as $value) {
            if (is_scalar($value) && ($value = self::clean((string) $value)) !== null) {
                $clean[] = $value;
            }
        }
        $index->prime($clean);
    }

    /**
     * Resolve one reference. Give `value` for a single column that may hold a name or a code, or `name` and/or `code`
     * for separate columns (both must then describe the same record). `within` narrows names by the record's own
     * columns (e.g. ['parent_department_id' => 4]); `sheet`, `row` and `column` say where the value was, for the review.
     *
     * @param  array<string, int|null>  $within
     */
    public function resolve(
        string $master,
        ?string $value = null,
        ?int $companyId = null,
        ?string $name = null,
        ?string $code = null,
        array $within = [],
        ?string $sheet = null,
        ?int $row = null,
        ?string $column = null,
    ): Resolution {
        if (app(TenantContext::class)->id() !== $this->tenantId) {
            throw new LogicException('A resolution session belongs to one organisation; start a new session for another.');
        }
        $spec = $this->catalogue->for($master);
        foreach (array_keys($within) as $hint) {
            if (! in_array($hint, $spec->hintColumns, true)) {
                throw new InvalidArgumentException("{$spec->label} records cannot be narrowed by \"{$hint}\".");
            }
        }
        [$value, $name, $code] = [self::clean($value), self::clean($name), self::clean($code)];

        $memo = implode("\x1F", [$spec->key, $companyId ?? '', NameKey::key($value), NameKey::key($name), NameKey::key($code), json_encode($within)]);
        $result = $this->decided[$memo] ??= $this->decide($spec, $value, $name, $code, $companyId, $within);
        $this->report->record($result, $value, $name, $code, $this->companyName($companyId), $sheet, $row, $column);

        return $result;
    }

    public function report(): ResolutionReport
    {
        return $this->report;
    }

    /** Drop what was loaded (after a mapping is confirmed or a record created) so the next resolve sees it. */
    public function refresh(): void
    {
        $this->indexes = [];
        $this->mappings = [];
        $this->decided = [];
    }

    public function companyName(?int $companyId): ?string
    {
        if ($companyId === null) {
            return null;
        }
        $this->companies ??= Company::query()->withoutGlobalScope(AccessScope::class)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();

        return $this->companies[$companyId] ?? "company #{$companyId}";
    }

    /** @param  array<string, int|null>  $within */
    private function decide(MasterSpec $spec, ?string $value, ?string $name, ?string $code, ?int $companyId, array $within): Resolution
    {
        if ($value === null && $name === null && $code === null) {
            return $this->result($spec, ResolutionStatus::Blank, '', $companyId);
        }

        if ($value !== null) { // one column: a code or a name
            $byCode = $this->byCode($spec, $value, $companyId);
            if ($byCode->ok()) {
                $byName = $this->byName($spec, $value, $companyId, $within, mappings: false);
                if ($byName->ok() && $byName->id !== $byCode->id) {
                    return $this->withWarning($byCode, "{$spec->label} '{$value}' was matched by its code ({$byCode->record}); it is also the name of {$byName->record}. Use the other record's code if you meant it.");
                }

                return $byCode;
            }
            $byName = $this->byName($spec, $value, $companyId, $within);
            // The text is another company's code and nothing here has that name: say so rather than "does not exist".
            if ($byCode->status === ResolutionStatus::NotApplicable && in_array($byName->status, [ResolutionStatus::Unmatched, ResolutionStatus::Suggested], true)) {
                return $byCode;
            }

            return $byName;
        }

        if ($code === null) {
            return $this->byName($spec, (string) $name, $companyId, $within);
        }
        $byCode = $this->byCode($spec, $code, $companyId);
        if ($name === null) {
            return $byCode;
        }
        $supplied = "{$name} / {$code}";
        if (! $byCode->ok()) {
            if ($byCode->status === ResolutionStatus::Unmatched) {
                $byName = $this->byName($spec, $name, $companyId, $within);
                if ($byName->ok()) {
                    return $this->result($spec, ResolutionStatus::Conflict, $supplied, $companyId, candidates: $this->candidates($spec, [(int) $byName->id]),
                        error: "{$spec->label} code '{$code}' does not exist, but the name '{$name}' is {$byName->record}. Correct the code or leave it blank.");
                }
            }

            return $byCode;
        }
        $index = $this->index($spec);
        $own = $byCode->id > 0 ? $index->row((int) $byCode->id) : null;
        if ($own !== null && NameKey::key($own['name']) === NameKey::key($name)) {
            return $byCode;
        }
        $byName = $this->byName($spec, $name, $companyId, $within);
        if ($byName->ok() && $byName->id === $byCode->id) {
            return $byCode;
        }
        $ids = array_values(array_filter([(int) $byCode->id, $byName->ok() ? (int) $byName->id : 0], fn (int $id) => $id > 0));

        return $this->result($spec, ResolutionStatus::Conflict, $supplied, $companyId, candidates: $this->candidates($spec, array_unique($ids)),
            error: "{$spec->label} code '{$code}' is {$byCode->record}, but the name says '{$name}'. Make the name and the code describe the same {$spec->lower()}, or give only one of them.");
    }

    private function byCode(MasterSpec $spec, string $typed, ?int $companyId): Resolution
    {
        $key = NameKey::key($typed);
        foreach ($this->planned[$spec->key] ?? [] as $plan) {
            if ($plan['code_key'] === $key && ($companyId === null || $plan['company'] === null || $plan['company'] === $companyId)) {
                return $this->result($spec, ResolutionStatus::Planned, $typed, $companyId, id: -1, via: Resolution::VIA_PLANNED, record: $plan['name'] ?? $plan['code']);
            }
        }
        $index = $this->index($spec);
        $id = $spec->codeColumn === null ? null : $index->byCode($key, $typed);
        if ($id === null) {
            return $this->result($spec, ResolutionStatus::Unmatched, $typed, $companyId, error: "{$spec->label} code '{$typed}' does not exist.");
        }
        $row = $index->row($id);
        if ($companyId !== null && ! $spec->isEmployee() && ! $index->appliesTo($id, $companyId)) {
            return $this->result($spec, ResolutionStatus::NotApplicable, $typed, $companyId, candidates: $this->candidates($spec, [$id]),
                error: $this->notApplicable($spec, $row, $typed, $companyId));
        }

        return $this->matched($spec, $typed, $companyId, $row, Resolution::VIA_CODE);
    }

    /** @param  array<string, int|null>  $within */
    private function byName(MasterSpec $spec, string $typed, ?int $companyId, array $within, bool $mappings = true): Resolution
    {
        $key = NameKey::key($typed);
        $index = $this->index($spec);
        $fits = fn (array $row) => array_diff_assoc(array_map(fn ($v) => $v === null ? null : (int) $v, $within), $row['hints']) === [];

        $ids = array_values(array_filter($index->byName($key, $typed), fn (int $id) => $fits($index->row($id))));
        $planned = array_values(array_filter($this->planned[$spec->key] ?? [],
            fn (array $p) => $p['name_key'] === $key && ($companyId === null || $p['company'] === null || $p['company'] === $companyId)));

        $outside = [];
        if ($companyId !== null) {
            $here = array_values(array_filter($ids, fn (int $id) => $index->appliesTo($id, $companyId)));
            if ($spec->isEmployee()) {
                $here = $here !== [] || count($ids) !== 1 ? ($here !== [] ? $here : $ids) : $ids; // a company narrows namesakes; it never hides the only one
            } else {
                $outside = array_values(array_diff($ids, $here));
            }
            $ids = $here;
        }
        $live = array_values(array_filter($ids, fn (int $id) => $index->row($id)['live']));
        $pool = $live !== [] ? $live : $ids;
        $mapped = $mappings ? $this->mapping($spec, $key, $companyId) : null;

        if (count($pool) + count($planned) === 1) {
            if ($planned !== []) {
                return $this->result($spec, ResolutionStatus::Planned, $typed, $companyId, id: -1, via: Resolution::VIA_PLANNED, record: $planned[0]['name'] ?? $planned[0]['code']);
            }
            if ($mapped !== null && $mapped !== $pool[0]) {
                return $this->result($spec, ResolutionStatus::Ambiguous, $typed, $companyId, candidates: $this->candidates($spec, [$pool[0], $mapped]),
                    error: "{$spec->label} '{$typed}' is the name of one record, but a saved mapping says it means another. Retire the mapping or use the code.");
            }

            return $this->matched($spec, $typed, $companyId, $index->row($pool[0]), Resolution::VIA_NAME);
        }
        if (count($pool) + count($planned) > 1) {
            if ($mapped !== null && in_array($mapped, $pool, true)) {
                return $this->matched($spec, $typed, $companyId, $index->row($mapped), Resolution::VIA_MAPPING);
            }
            $labels = array_map(fn (Candidate $c) => $c->label(), $candidates = $this->candidates($spec, $pool));
            if ($planned !== []) {
                $labels[] = ($planned[0]['name'] ?? $planned[0]['code']).' (created by this workbook)';
            }

            return $this->result($spec, ResolutionStatus::Ambiguous, $typed, $companyId, candidates: $candidates,
                error: "{$spec->label} '{$typed}' matches ".count($labels).' records: '.implode('; ', $labels).'. Use the code instead, or choose one in the review.');
        }

        if ($mapped !== null) {
            $row = $index->find($mapped);
            if ($row === null || ! $fits($row)) {
                return $this->result($spec, ResolutionStatus::Unmatched, $typed, $companyId,
                    error: "{$spec->label} '{$typed}' is mapped to a record you cannot use here. Choose another in the review.");
            }
            if ($companyId !== null && ! $spec->isEmployee() && ! $index->appliesTo($mapped, $companyId)) {
                return $this->result($spec, ResolutionStatus::NotApplicable, $typed, $companyId, candidates: $this->candidates($spec, [$mapped]),
                    error: $this->notApplicable($spec, $row, $typed, $companyId));
            }

            return $this->matched($spec, $typed, $companyId, $row, Resolution::VIA_MAPPING);
        }
        if ($outside !== []) {
            return $this->result($spec, ResolutionStatus::NotApplicable, $typed, $companyId, candidates: $this->candidates($spec, $outside),
                error: $this->notApplicable($spec, $index->row($outside[0]), $typed, (int) $companyId));
        }

        $suggestions = [];
        foreach ($index->suggestible() as $row) {
            if (! $fits($row) || ($companyId !== null && ! $index->appliesTo($row['id'], $companyId))) {
                continue;
            }
            $score = NameKey::similarity($typed, $row['name']);
            if ($score >= 0.75) {
                $suggestions[] = [$score + ($row['live'] ? 0.001 : 0), $row['id']];
            }
        }
        rsort($suggestions);
        if ($suggestions !== []) {
            $scores = array_column(array_slice($suggestions, 0, 3), 0, 1);
            $candidates = $this->candidates($spec, array_keys($scores), $scores);

            return $this->result($spec, ResolutionStatus::Suggested, $typed, $companyId, candidates: $candidates,
                error: "{$spec->label} '{$typed}' does not exist".$this->forCompany($companyId).'. Did you mean '.implode(' or ', array_map(fn (Candidate $c) => $c->label(), $candidates)).'? Confirm it in the review, or correct the file.');
        }

        return $this->result($spec, ResolutionStatus::Unmatched, $typed, $companyId,
            error: "{$spec->label} '{$typed}' does not exist".$this->forCompany($companyId).'.');
    }

    /** @param  array{id: int, name: string, code: ?string, owner: ?int, live: bool, hints: array<string, ?int>}  $row */
    private function matched(MasterSpec $spec, string $typed, ?int $companyId, array $row, string $via): Resolution
    {
        $record = $this->label($spec, $row);
        $warnings = $row['live'] ? [] : ["{$spec->label} {$record} is inactive or no longer in force."];

        return $this->result($spec, ResolutionStatus::Matched, $typed, $companyId, id: $row['id'], via: $via, record: $record, warnings: $warnings);
    }

    /** @param  array{id: int, name: string, code: ?string, owner: ?int, live: bool, hints: array<string, ?int>}  $row */
    private function notApplicable(MasterSpec $spec, array $row, string $typed, int $companyId): string
    {
        $company = $this->companyName($companyId);
        if ($spec->ownerColumn !== null && $row['owner'] !== null) {
            return "{$spec->label} '{$typed}' ({$this->label($spec, $row)}) belongs to {$this->companyName($row['owner'])}, not {$company}.";
        }

        return "{$spec->label} '{$typed}' ({$this->label($spec, $row)}) is not available to {$company}. Extend where it applies, or use one that applies to {$company}.";
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, float>  $scores
     * @return list<Candidate>
     */
    private function candidates(MasterSpec $spec, array $ids, array $scores = []): array
    {
        $index = $this->index($spec);

        return array_values(array_map(function (int $id) use ($index, $spec, $scores) {
            $row = $index->find($id) ?? ['id' => $id, 'name' => "#{$id}", 'code' => null, 'owner' => null, 'live' => true, 'hints' => []];

            return new Candidate($id, $row['name'], $row['code'], $spec->ownerColumn !== null || $spec->isEmployee() ? $this->companyName($row['owner']) : null, $scores[$id] ?? 1.0, $row['live']);
        }, array_values(array_unique($ids))));
    }

    /** @param  array{id: int, name: string, code: ?string, owner: ?int, live: bool, hints: array<string, ?int>}  $row */
    private function label(MasterSpec $spec, array $row): string
    {
        return $row['name'].($row['code'] !== null && $row['code'] !== '' && $row['code'] !== $row['name'] ? " ({$row['code']})" : '');
    }

    private function mapping(MasterSpec $spec, string $key, ?int $companyId): ?int
    {
        if (! isset($this->mappings[$spec->key])) {
            $this->mappings[$spec->key] = [];
            MasterReferenceMapping::query()->active()->where('master', $spec->key)
                ->where(fn ($q) => $q->whereNull('scope')->when($this->scope !== null, fn ($q) => $q->orWhere('scope', $this->scope)))
                ->get(['value_key', 'company_id', 'scope', 'record_id'])
                ->each(function (MasterReferenceMapping $m) use ($spec) {
                    $this->mappings[$spec->key][$m->value_key][] = ['company' => $m->company_id, 'scope' => $m->scope, 'record' => (int) $m->record_id];
                });
        }
        $best = null;
        $rank = -1;
        foreach ($this->mappings[$spec->key][$key] ?? [] as $m) {
            if ($m['company'] !== null && $m['company'] !== $companyId) {
                continue;
            }
            // This upload's own choice beats a remembered one; a company's own beats one for any company.
            $r = ($m['scope'] !== null ? 2 : 0) + ($m['company'] !== null ? 1 : 0);
            if ($r > $rank) {
                [$best, $rank] = [$m['record'], $r];
            }
        }

        return $best;
    }

    public function index(MasterSpec|string $master): ReferenceIndex
    {
        $spec = $master instanceof MasterSpec ? $master : $this->catalogue->for($master);

        return $this->indexes[$spec->key] ??= $spec->isEmployee()
            ? new EmployeeIndex($this->actor, $this->on)
            : new MasterIndex($spec, $this->actor, $this->on);
    }

    /**
     * @param  list<Candidate>  $candidates
     * @param  list<string>  $warnings
     */
    private function result(MasterSpec $spec, ResolutionStatus $status, string $supplied, ?int $companyId, ?int $id = null, ?string $via = null,
        ?string $record = null, array $candidates = [], array $warnings = [], ?string $error = null): Resolution
    {
        return new Resolution($spec->key, $spec->label, $status, $supplied, $companyId, $id, $via, $record, $candidates, $warnings, $error);
    }

    private function withWarning(Resolution $r, string $warning): Resolution
    {
        return new Resolution($r->master, $r->masterLabel, $r->status, $r->supplied, $r->companyId, $r->id, $r->via, $r->record, $r->candidates, [...$r->warnings, $warning], $r->error());
    }

    private function forCompany(?int $companyId): string
    {
        return $companyId === null ? '' : ' for '.$this->companyName($companyId);
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', strtr($value, ["\u{00A0}" => ' '])) ?? $value);

        // "(none)" is the uploads' explicit "remove this link" (the imports' ClearValue rule: any case, spaces trimmed):
        // never a name or a code to match. The import decides what clearing means before it asks; here it is only kept
        // from being matched, suggested or reported. Spelled out here so name resolution stands on its own.
        return $value === '' || mb_strtolower($value) === self::CLEAR ? null : $value;
    }
}
