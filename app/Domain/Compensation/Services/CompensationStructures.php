<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Events\CompensationEvent;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Models\SalaryStructureComponent;
use App\Domain\Compensation\Models\SalaryStructureVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Payroll\Contracts\PayrollClosureReader;
use App\Domain\Payroll\Models\SalaryComponent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 11 — versioned compensation structures (§6). A structure is an identity (code, name); its
 * composition is a series of effective-dated versions:
 *
 *   draft ──submit──► pending_approval ──approve (another person)──► scheduled ──(its date)──► active ──► superseded
 *     ▲                     │
 *     └──── return ─────────┘                       archive: drafts, pending versions, superseded versions
 *
 * Approval closes the previous version the day before and never reaches into a finalized payroll
 * period, so historical payroll keeps resolving to the composition in force at the time. Payroll reads
 * versions only through CompensationOutput.
 */
final class CompensationStructures
{
    public function __construct(private readonly AuditRecorder $audit, private readonly PayrollClosureReader $closure) {}

    /** @param  array<string, mixed>  $data  name, code, description, effective_from, currency, pay_frequency, company_id, grade_ids, components */
    public function create(array $data, User $actor): SalaryStructure
    {
        $this->authorise($actor, 'compensation.configure');
        foreach (['name', 'code'] as $field) {
            if (blank($data[$field] ?? null)) {
                throw new CompensationRuleViolation("A structure needs a {$field}.");
            }
        }
        if (SalaryStructure::query()->where('code', strtoupper(trim((string) $data['code'])))->exists()) {
            throw new CompensationRuleViolation('A structure with that code already exists.');
        }

        return DB::transaction(function () use ($data, $actor) {
            $structure = SalaryStructure::query()->create(['name' => $data['name'], 'code' => $data['code'], 'description' => $data['description'] ?? null, 'status' => 'active']);
            $version = SalaryStructureVersion::query()->create([
                'salary_structure_id' => $structure->id, 'version' => 1, 'status' => 'draft', 'prepared_by' => $actor->id,
                ...$this->definition($data + ['effective_from' => now()->toDateString()]),
            ]);
            if (! empty($data['components'])) {
                $this->writeComponents($version, $data['components']);
            }

            return $structure;
        });
    }

    /** A correction or change: a new draft version copied from the latest approved one. */
    public function newVersion(SalaryStructure $structure, User $actor, CarbonInterface|string|null $from = null): SalaryStructureVersion
    {
        $this->authorise($actor, 'compensation.configure');

        return DB::transaction(function () use ($structure, $actor, $from) {
            SalaryStructure::query()->whereKey($structure->id)->lockForUpdate()->firstOrFail();
            if (SalaryStructureVersion::query()->where('salary_structure_id', $structure->id)->whereIn('status', ['draft', 'pending_approval'])->exists()) {
                throw new CompensationRuleViolation('This structure already has a version in preparation.');
            }
            $latest = SalaryStructureVersion::query()->where('salary_structure_id', $structure->id)->whereIn('status', SalaryStructureVersion::APPROVED)->orderByDesc('version')->with('components')->first();
            $number = (int) SalaryStructureVersion::query()->where('salary_structure_id', $structure->id)->max('version') + 1;
            $start = $from ? Carbon::parse($from) : Carbon::today()->max($latest ? $latest->effective_from->copy()->addDay() : Carbon::today());
            $version = SalaryStructureVersion::query()->create([
                'salary_structure_id' => $structure->id, 'version' => $number, 'status' => 'draft', 'prepared_by' => $actor->id,
                'effective_from' => $start->toDateString(), 'currency' => $latest?->currency ?? 'INR', 'pay_frequency' => $latest?->pay_frequency ?? 'monthly',
                'company_id' => $latest?->company_id, 'grade_ids' => $latest?->grade_ids,
            ]);
            $this->writeComponents($version, ($latest?->components ?? collect())->map(fn (SalaryStructureComponent $c) => $c->only(['salary_component_id', 'formula_override', 'pay_nature', 'frequency', 'sort_order']))->all());

            return $version;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateDraft(SalaryStructureVersion $version, array $data, User $actor): SalaryStructureVersion
    {
        $this->authorise($actor, 'compensation.configure');
        $this->assertStatus($version, ['draft']);
        $version->update($this->definition($data + $version->only(['effective_from', 'currency', 'pay_frequency', 'company_id', 'grade_ids', 'change_note'])));
        if (array_key_exists('components', $data)) {
            DB::transaction(fn () => $this->writeComponents($version, (array) $data['components']));
        }

        return $version->refresh();
    }

    public function submit(SalaryStructureVersion $version, User $actor): SalaryStructureVersion
    {
        $this->authorise($actor, 'compensation.configure');

        return $this->transition($version, ['draft'], function (SalaryStructureVersion $version) use ($actor) {
            if ($version->components()->doesntExist()) {
                throw new CompensationRuleViolation('A structure version needs at least one component.');
            }
            $version->update(['status' => 'pending_approval', 'submitted_at' => now(), 'prepared_by' => $version->prepared_by ?? $actor->id]);
            $this->audit->record(AuditAction::Submitted, 'compensation', $version, [['field' => 'status', 'before' => 'draft', 'after' => 'pending_approval']], null, actor: $actor, effectiveDate: $version->effective_from);
        });
    }

    public function approve(SalaryStructureVersion $version, User $actor, ?string $note = null): SalaryStructureVersion
    {
        $this->authorise($actor, 'compensation.approve');
        if ((int) $version->prepared_by === (int) $actor->id) {
            throw new CompensationRuleViolation('The person who prepared a structure version cannot approve it.');
        }

        return $this->transition($version, ['pending_approval'], function (SalaryStructureVersion $version) use ($actor, $note) {
            SalaryStructure::query()->whereKey($version->salary_structure_id)->lockForUpdate()->firstOrFail();
            if ((int) $version->prepared_by === (int) $actor->id) {
                throw new CompensationRuleViolation('The person who prepared a structure version cannot approve it.');
            }
            $from = $version->effective_from->copy()->startOfDay();
            $closed = $this->closure->latestClosedPeriodEnd();
            if ($closed !== null && $from->lte($closed)) {
                throw new CompensationRuleViolation('Payroll is finalized through '.Carbon::parse($closed)->toDateString().'; a structure version from '.$from->toDateString().' would change a closed payroll period.');
            }
            $approved = SalaryStructureVersion::query()->where('salary_structure_id', $version->salary_structure_id)->whereIn('status', SalaryStructureVersion::APPROVED)->orderBy('effective_from')->lockForUpdate()->get();
            if ($approved->contains(fn (SalaryStructureVersion $v) => $v->effective_from->gte($from))) {
                throw new CompensationRuleViolation('A structure version must start after every approved version (latest from '.$approved->last()->effective_from->toDateString().').');
            }
            $previous = $approved->last();
            $dueNow = $from->lte(now()->startOfDay());
            if ($previous && ($previous->effective_to === null || $previous->effective_to->gte($from))) {
                $previous->update(['effective_to' => $from->copy()->subDay(), ...($dueNow && $previous->status === 'active' ? ['status' => 'superseded'] : [])]);
            }
            $version->update(['status' => $dueNow ? 'active' : 'scheduled', 'approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note ?: null, 'checksum' => $this->checksum($version)]);
            $this->audit->record(AuditAction::Approved, 'compensation', $version, [['field' => 'status', 'before' => 'pending_approval', 'after' => $version->status]], null, actor: $actor, effectiveDate: $from, metadata: ['structure' => $version->structure?->code, 'version' => $version->version]);
            CompensationEvent::dispatch('compensation.structure.approved', null, $version, ['structure' => $version->structure?->code, 'version' => $version->version, 'effective_date' => $from->toDateString()], array_filter([(int) $version->prepared_by]));
        });
    }

    public function returnToDraft(SalaryStructureVersion $version, User $actor, string $note): SalaryStructureVersion
    {
        $this->authorise($actor, 'compensation.approve');
        if (trim($note) === '') {
            throw new CompensationRuleViolation('Returning a structure version needs a note.');
        }

        return $this->transition($version, ['pending_approval'], function (SalaryStructureVersion $version) use ($actor, $note) {
            $version->update(['status' => 'draft', 'submitted_at' => null, 'decision_note' => $note]);
            $this->audit->record(AuditAction::Rejected, 'compensation', $version, [['field' => 'status', 'before' => 'pending_approval', 'after' => 'draft']], $note, actor: $actor);
        });
    }

    public function archive(SalaryStructureVersion $version, User $actor, string $reason): SalaryStructureVersion
    {
        $this->authorise($actor, 'compensation.configure');
        if (trim($reason) === '') {
            throw new CompensationRuleViolation('Archiving a structure version needs a reason.');
        }

        return $this->transition($version, ['draft', 'pending_approval', 'superseded'], function (SalaryStructureVersion $version) use ($actor, $reason) {
            $before = $version->status;
            $version->update(['status' => 'archived', 'archived_at' => now()]);
            $this->audit->record(AuditAction::Archive, 'compensation', $version, [['field' => 'status', 'before' => $before, 'after' => 'archived']], $reason, actor: $actor);
        });
    }

    /** Scheduled versions whose date has come become active; the version they follow is superseded. */
    public function promoteDue(CarbonInterface|string|null $on = null): int
    {
        $day = Carbon::parse($on ?? now())->toDateString();
        $count = 0;
        SalaryStructureVersion::query()->where('status', 'scheduled')->where('effective_from', '<=', $day.' 23:59:59')->orderBy('effective_from')->pluck('id')
            ->each(function (int $id) use (&$count) {
                try {
                    DB::transaction(function () use ($id, &$count) {
                        $version = SalaryStructureVersion::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                        if ($version->status !== 'scheduled') {
                            return;
                        }
                        SalaryStructureVersion::query()->where('salary_structure_id', $version->salary_structure_id)->where('status', 'active')->where('effective_from', '<', $version->effective_from->toDateString())
                            ->get()->each(fn (SalaryStructureVersion $v) => $v->update(['status' => 'superseded']));
                        $version->update(['status' => 'active']);
                        $count++;
                    });
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }

    /** The approved version of a structure in force on a date (null when none). */
    public function versionOn(SalaryStructure|int $structure, CarbonInterface|string|null $date = null): ?SalaryStructureVersion
    {
        return SalaryStructureVersion::query()->where('salary_structure_id', $structure instanceof SalaryStructure ? $structure->id : $structure)
            ->whereIn('status', SalaryStructureVersion::APPROVED)->effectiveOn($date)->orderByDesc('effective_from')->first();
    }

    /** @return array<string, mixed> */
    private function definition(array $data): array
    {
        try {
            $from = Carbon::parse((string) ($data['effective_from'] ?? ''))->toDateString();
        } catch (Throwable) {
            throw new CompensationRuleViolation('A valid effective date is required.');
        }
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'INR')));
        if (! in_array($currency, config('peopleos.compensation.currencies', []), true)) {
            throw new CompensationRuleViolation("[{$currency}] is not a supported ISO 4217 currency.");
        }
        $frequency = (string) ($data['pay_frequency'] ?? 'monthly');
        if (! array_key_exists($frequency, config('peopleos.compensation.pay_frequencies', []))) {
            throw new CompensationRuleViolation("Pay frequency [{$frequency}] is not supported by Payroll.");
        }
        if (filled($data['company_id'] ?? null) && ! Company::query()->whereKey($data['company_id'])->exists()) {
            throw new CompensationRuleViolation('That company does not exist.');
        }
        $grades = array_values(array_unique(array_map('intval', array_filter((array) ($data['grade_ids'] ?? [])))));
        if ($grades !== [] && Grade::query()->whereIn('id', $grades)->count() !== count($grades)) {
            throw new CompensationRuleViolation('A grade in the applicability list does not exist.');
        }

        return ['effective_from' => $from, 'currency' => $currency, 'pay_frequency' => $frequency, 'company_id' => filled($data['company_id'] ?? null) ? (int) $data['company_id'] : null, 'grade_ids' => $grades ?: null, 'change_note' => $data['change_note'] ?? null];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function writeComponents(SalaryStructureVersion $version, array $rows): void
    {
        $this->assertStatus($version, ['draft']);
        $seen = [];
        $clean = [];
        foreach (array_values($rows) as $i => $row) {
            $componentId = (int) ($row['salary_component_id'] ?? 0);
            $component = SalaryComponent::query()->find($componentId);
            if ($component === null) {
                throw new CompensationRuleViolation('A structure component refers to a payroll component that does not exist.');
            }
            if (isset($seen[$componentId])) {
                throw new CompensationRuleViolation("Component {$component->code} appears twice.");
            }
            $seen[$componentId] = true;
            $nature = (string) ($row['pay_nature'] ?? 'fixed');
            $frequency = (string) ($row['frequency'] ?? 'monthly');
            if (! array_key_exists($nature, config('peopleos.compensation.pay_natures', [])) || ! array_key_exists($frequency, config('peopleos.compensation.component_frequencies', []))) {
                throw new CompensationRuleViolation("Component {$component->code}: unknown pay nature or frequency.");
            }
            $clean[] = ['salary_structure_id' => $version->salary_structure_id, 'salary_component_id' => $componentId, 'formula_override' => filled($row['formula_override'] ?? null) ? (string) $row['formula_override'] : null, 'pay_nature' => $nature, 'frequency' => $frequency, 'sort_order' => (int) ($row['sort_order'] ?? ($i + 1) * 10)];
        }
        $version->components()->get()->each(fn (SalaryStructureComponent $c) => $c->delete());
        foreach ($clean as $row) {
            $version->components()->create($row);
        }
    }

    private function checksum(SalaryStructureVersion $version): string
    {
        return hash('sha256', json_encode([
            $version->only(['salary_structure_id', 'version', 'currency', 'pay_frequency', 'company_id', 'grade_ids']), $version->effective_from->toDateString(),
            $version->components()->get()->map(fn ($c) => $c->only(['salary_component_id', 'formula_override', 'pay_nature', 'frequency', 'sort_order']))->all(),
        ]));
    }

    /** @param  list<string>  $from */
    private function transition(SalaryStructureVersion $version, array $from, callable $step): SalaryStructureVersion
    {
        return DB::transaction(function () use ($version, $from, $step) {
            $current = SalaryStructureVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, $from, true)) {
                throw new CompensationRuleViolation('This structure version is '.str_replace('_', ' ', $current->status).'; that step is not available.');
            }
            $version->setRawAttributes($current->getAttributes(), true);
            $step($version);
            $version->update(['lock_version' => (int) $current->lock_version + 1]);

            return $version;
        });
    }

    /** @param  list<string>  $statuses */
    private function assertStatus(SalaryStructureVersion $version, array $statuses): void
    {
        $status = SalaryStructureVersion::query()->whereKey($version->id)->value('status');
        if (! in_array($status, $statuses, true)) {
            throw new CompensationRuleViolation('Only a draft structure version is edited; a correction is a new version.');
        }
    }

    private function authorise(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new CompensationRuleViolation("This needs {$permission}.");
        }
    }
}
