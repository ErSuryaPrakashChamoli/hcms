<?php

namespace App\Domain\Tax\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxRounding;
use App\Domain\Tax\Enums\TaxRuleStatus;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Support\TaxComponent;
use App\Domain\Tax\Support\TaxJurisdiction;
use App\Support\Commercial\OperatorChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * SaaS.7: the commercial tax rule catalogue. A rule version is drafted, submitted for review and verified by a
 * different operator with a reference to the tax review (the maker-checker of statutory rule verification), or
 * retired. Only verified rules apply, from their effective date (today or later). No rule, rate or SAC is shipped:
 * every value comes from a reviewed operator entry. Rules are refused for regimes without a determiner.
 */
final class TaxRules
{
    public function __construct(private readonly AuditRecorder $audit, private readonly TaxRegistry $registry) {}

    /** @param  array<string, list<array{type: string, rate: string|int|float}>>  $outcomes */
    public function draft(TaxRegime $regime, string $country, ?string $subdivision, string $category, string $effectiveFrom, array $outcomes,
        string $rounding, ?array $classification, string $reason, User $actor): TaxRule
    {
        OperatorChange::assert($actor, $reason, 'tax rules');
        $jurisdiction = new TaxJurisdiction(strtoupper($country), $subdivision === null || $subdivision === '' ? null : strtoupper($subdivision));
        if (JurisdictionCatalogue::regimeFor($jurisdiction->country) !== $regime) {
            throw new RuntimeException("{$regime->value} is not the tax regime of {$jurisdiction->country}.");
        }
        $determiner = $this->registry->determiner($regime) ?? throw new RuntimeException("{$regime->label()} has no determination built yet: its rules could never apply.");
        if (($unknown = array_diff(array_keys($outcomes), array_keys($determiner->outcomes()))) !== []) {
            throw new RuntimeException('Unknown outcome '.implode(', ', $unknown).'; '.$regime->value.' determines: '.implode(', ', array_keys($determiner->outcomes())).'.');
        }
        if (preg_match('/^[a-z0-9._-]{2,64}$/', $category) !== 1) {
            throw new RuntimeException('The tax category is a lowercase key (letters, digits, dot, dash, underscore).');
        }
        $from = $this->day($effectiveFrom);
        if ($from < now()->toDateString()) {
            throw new RuntimeException('A tax rule takes effect today or later: issued invoices keep the rule they were issued under.');
        }
        $rounding = TaxRounding::tryFrom($rounding) ?? throw new RuntimeException('The rounding mode must be half_up or half_even.');
        $normalised = $this->outcomes($regime, $outcomes);
        $classification = array_filter(array_map(fn ($v) => trim((string) $v), $classification ?? []), fn (string $v) => $v !== '');
        foreach ($regime->requiredClassification() as $key) {
            if (! isset($classification[$key])) {
                throw new RuntimeException("A {$regime->value} rule needs its \"{$key}\" classification.");
            }
        }

        return DB::transaction(function () use ($regime, $jurisdiction, $category, $from, $normalised, $rounding, $classification, $reason, $actor) {
            $subdivision = $jurisdiction->subdivision ?? '';
            $version = (int) TaxRule::query()->where(['regime' => $regime, 'country' => $jurisdiction->country, 'subdivision' => $subdivision, 'tax_category' => $category])
                ->lockForUpdate()->max('version') + 1;
            $rule = TaxRule::query()->create(['regime' => $regime, 'country' => $jurisdiction->country, 'subdivision' => $subdivision, 'tax_category' => $category,
                'version' => $version, 'effective_from' => $from, 'outcomes' => $normalised, 'rounding_mode' => $rounding, 'rounding_stage' => 'line',
                'classification' => $classification === [] ? null : $classification, 'status' => TaxRuleStatus::Draft, 'reason' => $reason, 'created_by' => $actor->id]);
            $this->record(AuditAction::TaxRuleDrafted, $rule, null, 'draft', $reason, $actor, ['outcomes' => $normalised, 'rounding' => $rounding->value, 'classification' => $classification]);

            return $rule;
        });
    }

    public function submit(TaxRule $rule, string $reason, User $actor): TaxRule
    {
        OperatorChange::assert($actor, $reason, 'tax rules');

        return $this->transition($rule, [TaxRuleStatus::Draft], TaxRuleStatus::Review, AuditAction::TaxRuleSubmitted, $reason, $actor,
            ['submitted_by' => $actor->id, 'submitted_at' => now()]);
    }

    /** A second operator verifies the rule against the tax review identified by $reference. */
    public function verify(TaxRule $rule, string $reference, ?string $notes, User $actor): TaxRule
    {
        OperatorChange::assert($actor, $reference, 'tax rules');

        return DB::transaction(function () use ($rule, $reference, $notes, $actor) {
            $locked = TaxRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($locked->status === TaxRuleStatus::Verified) {
                return $locked;
            }
            if (in_array($actor->id, [$locked->created_by, $locked->submitted_by], true)) {
                throw new RuntimeException('A tax rule is verified by an operator other than the one who drafted or submitted it.');
            }

            return $this->transition($locked, [TaxRuleStatus::Review], TaxRuleStatus::Verified, AuditAction::TaxRuleVerified, $reference, $actor,
                ['verified_by' => $actor->id, 'verified_at' => now(), 'verification_reference' => Str::limit(trim($reference), 200, ''),
                    'verification_notes' => $notes === null ? null : Str::limit(trim($notes), 1000, '')]);
        });
    }

    public function retire(TaxRule $rule, string $reason, User $actor): TaxRule
    {
        OperatorChange::assert($actor, $reason, 'tax rules');

        return $this->transition($rule, [TaxRuleStatus::Draft, TaxRuleStatus::Review, TaxRuleStatus::Verified], TaxRuleStatus::Retired, AuditAction::TaxRuleRetired,
            $reason, $actor, ['retired_by' => $actor->id, 'retired_at' => now()]);
    }

    /** The verified rule in force on $day: a subdivision-specific one before a country-wide one; latest start, then version. */
    public function inForce(TaxRegime $regime, string $country, ?string $subdivision, string $category, string $day): ?TaxRule
    {
        return TaxRule::query()->where(['regime' => $regime, 'country' => $country, 'tax_category' => $category, 'status' => TaxRuleStatus::Verified])
            ->whereIn('subdivision', array_values(array_unique(['', (string) $subdivision])))
            ->whereDate('effective_from', '<=', $day)
            ->get()
            ->sortByDesc(fn (TaxRule $r) => [$r->subdivision !== '' ? 1 : 0, $r->effective_from->toDateString(), $r->version])
            ->first();
    }

    /** @param  list<TaxRuleStatus>  $from  @param  array<string, mixed>  $attributes */
    private function transition(TaxRule $rule, array $from, TaxRuleStatus $to, AuditAction $action, string $reason, User $actor, array $attributes): TaxRule
    {
        return DB::transaction(function () use ($rule, $from, $to, $action, $reason, $actor, $attributes) {
            $locked = TaxRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($locked->status === $to) {
                return $locked;
            }
            if (! in_array($locked->status, $from, true)) {
                throw new RuntimeException("A {$locked->status->value} tax rule cannot become {$to->value}.");
            }
            $before = $locked->status->value;
            $locked->forceFill(['status' => $to] + $attributes)->save();
            $this->record($action, $locked, $before, $to->value, $reason, $actor, []);

            return $locked;
        });
    }

    /** @param  array<string, mixed>  $metadata */
    private function record(AuditAction $action, TaxRule $rule, ?string $before, string $after, string $reason, User $actor, array $metadata): void
    {
        $this->audit->record($action, 'tax', $rule, [['field' => 'status', 'before' => $before ?? 'none', 'after' => $after]], $reason,
            effectiveDate: $rule->effective_from->toDateString(), entityLabel: "Tax rule {$rule->label()}", actor: $actor,
            metadata: $metadata + ['tax_rule_id' => $rule->id, 'regime' => $rule->regime->value, 'country' => $rule->country], platform: true);
    }

    /** @return array<string, list<array{type: string, rate: string}>> */
    private function outcomes(TaxRegime $regime, array $outcomes): array
    {
        if ($outcomes === []) {
            throw new RuntimeException('A tax rule defines at least one outcome.');
        }
        $normalised = [];
        foreach ($outcomes as $key => $components) {
            if (! is_string($key) || preg_match('/^[a-z_]{2,48}$/', $key) !== 1 || ! is_array($components)) {
                throw new RuntimeException('Each outcome is a lowercase key with a list of components.');
            }
            $types = [];
            foreach (array_values($components) as $component) {
                $type = strtoupper(trim((string) ($component['type'] ?? '')));
                if (! in_array($type, $regime->taxTypes(), true)) {
                    throw new RuntimeException("{$type} is not a tax type of {$regime->value} (".implode(', ', $regime->taxTypes()).').');
                }
                if (in_array($type, $types, true)) {
                    throw new RuntimeException("The outcome {$key} names {$type} twice.");
                }
                try {
                    $rate = (new TaxComponent($type, trim((string) ($component['rate'] ?? ''))))->rate;
                } catch (InvalidArgumentException $e) {
                    throw new RuntimeException($e->getMessage());
                }
                $types[] = $type;
                $normalised[$key][] = ['type' => $type, 'rate' => $rate];
            }
            $normalised[$key] ??= [];
        }

        return $normalised;
    }

    private function day(string $day): string
    {
        try {
            return Carbon::createFromFormat('!Y-m-d', $day)->toDateString();
        } catch (\Throwable) {
            throw new RuntimeException("{$day} is not a date (YYYY-MM-DD).");
        }
    }
}
