<?php

namespace App\Domain\Tax\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxRounding;
use App\Domain\Tax\Enums\TaxRuleState;
use App\Domain\Tax\Enums\TaxRuleStatus;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Exceptions\TaxUnavailableException;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Support\TaxComponent;
use App\Domain\Tax\Support\TaxJurisdiction;
use App\Support\Commercial\OperatorChange;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * SaaS.7: the commercial tax rule catalogue (statutory values as versioned data). A rule version is drafted (or
 * loaded from the shipped statutory dataset), submitted for verification, and verified by a different operator with
 * a reference (maker-checker), or rejected; it may be retired. Only verified rules apply, from their effective date
 * until their expiry; a change in the law is a new version from a new date, never an edit, so issued invoices keep
 * the version they were issued under. Rules are refused for regimes without a determination (supplier or
 * destination side).
 */
final class TaxRules
{
    public function __construct(private readonly AuditRecorder $audit, private readonly TaxRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $outcomes  outcome => components list, or an object (components, treatment, conditions,
     *                                          requires_supplier_registration, wording, failure_code, description)
     * @param  array{effective_to?: ?string, rule_code?: ?string, conditions?: ?array, amount_basis?: ?string, source?: ?string,
     *     source_reference?: ?string, source_url?: ?string, source_date?: ?string}  $details
     */
    public function draft(TaxRegime $regime, string $country, ?string $subdivision, string $category, string $effectiveFrom, array $outcomes,
        string $rounding, ?array $classification, string $reason, User $actor, array $details = []): TaxRule
    {
        OperatorChange::assert($actor, $reason, 'tax rules');
        $from = $this->day($effectiveFrom);
        if ($from < now()->toDateString()) {
            throw new RuntimeException('A tax rule takes effect today or later: issued invoices keep the rule they were issued under.');
        }

        return $this->create($regime, $country, $subdivision, $category, $from, $outcomes, $rounding, $classification, $details,
            ['status' => TaxRuleStatus::Draft, 'reason' => $reason, 'created_by' => $actor->id, 'origin' => TaxRule::ORIGIN_OPERATOR], $actor);
    }

    /**
     * Loads one rule of the shipped statutory dataset, submitted for verification (maker = the loading operator). Its
     * effective date is the law's (it may be in the past: the dataset describes the law in force). Idempotent per
     * dataset version and key.
     *
     * @param  array<string, mixed>  $entry
     */
    public function loadFromDataset(string $datasetVersion, array $entry, User $actor): TaxRule
    {
        if (($existing = TaxRule::query()->where(['dataset_version' => $datasetVersion, 'dataset_key' => $entry['key']])->first()) !== null) {
            return $existing;
        }
        $regime = TaxRegime::tryFrom((string) $entry['regime']) ?? throw new RuntimeException("Unknown regime {$entry['regime']} in the dataset.");

        $entry['statutory_notes'] = array_filter((array) ($entry['statutory_notes'] ?? []) + ['dataset_verification' => $entry['verification'] ?? null], fn ($v) => $v !== null);

        return $this->create($regime, (string) $entry['country'], $entry['subdivision'] ?? null, (string) $entry['tax_category'], $this->day((string) $entry['effective_from']),
            (array) $entry['outcomes'], (string) ($entry['rounding'] ?? 'half_up'), $entry['classification'] ?? null, $entry,
            ['status' => TaxRuleStatus::Review, 'reason' => mb_substr("Statutory dataset {$datasetVersion}: ".($entry['summary'] ?? $entry['key']), 0, 500),
                'created_by' => $actor->id, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'origin' => TaxRule::ORIGIN_DATASET, 'dataset_version' => $datasetVersion,
                'dataset_key' => (string) $entry['key'], 'dataset_status' => (string) ($entry['verification']['status'] ?? 'pending_verification')], $actor);
    }

    /** @param  array<string, mixed>  $details  @param  array<string, mixed>  $attributes */
    private function create(TaxRegime $regime, string $country, ?string $subdivision, string $category, string $from, array $outcomes, string $rounding,
        ?array $classification, array $details, array $attributes, User $actor): TaxRule
    {
        $locality = blank($details['locality'] ?? null) ? null : strtoupper(trim((string) $details['locality']));
        try {
            $jurisdiction = new TaxJurisdiction(strtoupper($country), $subdivision === null || $subdivision === '' ? null : strtoupper($subdivision), $locality);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
        if (JurisdictionCatalogue::regimeFor($jurisdiction->country) !== $regime) {
            throw new RuntimeException("{$regime->value} is not the tax regime of {$jurisdiction->country}.");
        }
        $known = $this->registry->outcomes($regime);
        if ($known === []) {
            throw new RuntimeException("{$regime->label()} has no determination built yet: its rules could never apply.");
        }
        if (($unknown = array_diff(array_keys($outcomes), array_keys($known))) !== []) {
            throw new RuntimeException('Unknown outcome '.implode(', ', $unknown).'; '.$regime->value.' determines: '.implode(', ', array_keys($known)).'.');
        }
        if (preg_match('/^[a-z0-9._-]{2,64}$/', $category) !== 1) {
            throw new RuntimeException('The tax category is a lowercase key (letters, digits, dot, dash, underscore).');
        }
        $to = blank($details['effective_to'] ?? null) ? null : $this->day((string) $details['effective_to']);
        if ($to !== null && $to < $from) {
            throw new RuntimeException('A tax rule expires on or after the day it takes effect.');
        }
        $rounding = TaxRounding::tryFrom($rounding) ?? throw new RuntimeException('The rounding mode must be half_up or half_even.');
        $normalised = $this->outcomes($regime, $outcomes);
        $conditions = $this->conditions((array) ($details['conditions'] ?? []));
        $classification = array_filter(array_map(fn ($v) => trim((string) $v), $classification ?? []), fn (string $v) => $v !== '');
        $basis = (string) ($details['amount_basis'] ?? 'exclusive');
        if (! in_array($basis, ['exclusive', 'inclusive'], true)) {
            throw new RuntimeException('The amount basis is exclusive or inclusive.');
        }
        $text = fn (string $key, int $max) => blank($details[$key] ?? null) ? null : mb_substr(trim((string) $details[$key]), 0, $max);
        $sourceDate = blank($details['source_date'] ?? null) ? null : $this->day((string) $details['source_date']);
        if (! blank($details['source_url'] ?? null) && preg_match('~^https://[^\s<>"]+$~', trim((string) $details['source_url'])) !== 1) {
            throw new RuntimeException('A source URL is an https:// address.');
        }
        $ruleCode = $text('rule_code', 64);
        if ($ruleCode !== null && preg_match('/^[A-Z0-9._:-]{2,64}$/', $ruleCode) !== 1) {
            throw new RuntimeException('A rule code is capital letters, digits, dot, colon, dash or underscore.');
        }

        return DB::transaction(function () use ($regime, $jurisdiction, $category, $from, $to, $normalised, $rounding, $classification, $conditions, $basis, $text,
            $sourceDate, $ruleCode, $attributes, $actor) {
            $subdivision = $jurisdiction->subdivision ?? '';
            $locality = $jurisdiction->locality ?? '';
            $version = (int) TaxRule::query()->where(['regime' => $regime, 'country' => $jurisdiction->country, 'subdivision' => $subdivision, 'locality' => $locality,
                'tax_category' => $category])->lockForUpdate()->max('version') + 1;
            $rule = TaxRule::query()->create(['regime' => $regime, 'country' => $jurisdiction->country, 'subdivision' => $subdivision, 'locality' => $locality, 'tax_category' => $category,
                'rule_code' => $ruleCode, 'version' => $version, 'effective_from' => $from, 'effective_to' => $to, 'outcomes' => $normalised, 'rounding_mode' => $rounding,
                'rounding_stage' => 'line', 'classification' => $classification === [] ? null : $classification, 'conditions' => $conditions === [] ? null : $conditions,
                'statutory_notes' => blank($details['statutory_notes'] ?? null) ? null : (array) $details['statutory_notes'],
                'amount_basis' => $basis, 'source' => $text('source', 150), 'source_reference' => $text('source_reference', 500), 'source_url' => $text('source_url', 500),
                'source_date' => $sourceDate] + $attributes);
            $this->record(AuditAction::TaxRuleDrafted, $rule, null, $rule->status->value, (string) $rule->reason, $actor, ['outcomes' => $normalised, 'rounding' => $rounding->value,
                'classification' => $classification, 'conditions' => $conditions, 'effective_to' => $to, 'source' => $rule->source, 'source_reference' => $rule->source_reference,
                'origin' => $rule->origin, 'dataset_version' => $rule->dataset_version]);

            return $rule;
        }, 3);   // MySQL: two drafts of one scope can deadlock on the version's gap lock; a top-level call retries
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
            // Before/after for the audit trail: the version this one supersedes for its scope, if any.
            $previous = TaxRule::query()->where(['regime' => $locked->regime, 'country' => $locked->country, 'subdivision' => $locked->subdivision,
                'locality' => $locked->locality, 'tax_category' => $locked->tax_category, 'status' => TaxRuleStatus::Verified])->where('id', '<>', $locked->id)->orderByDesc('effective_from')->orderByDesc('version')->first();

            return $this->transition($locked, [TaxRuleStatus::Review], TaxRuleStatus::Verified, AuditAction::TaxRuleVerified, $reference, $actor,
                ['verified_by' => $actor->id, 'verified_at' => now(), 'verification_reference' => Str::limit(trim($reference), 200, ''),
                    'verification_notes' => $notes === null ? null : Str::limit(trim($notes), 1000, '')],
                ['before' => $previous === null ? null : ['version' => $previous->version, 'effective_from' => $previous->effective_from->toDateString(), 'outcomes' => $previous->outcomes],
                    'after' => ['version' => $locked->version, 'effective_from' => $locked->effective_from->toDateString(), 'outcomes' => $locked->outcomes],
                    'maker_id' => $locked->created_by, 'checker_id' => $actor->id, 'source' => $locked->source, 'source_reference' => $locked->source_reference,
                    'correlation_id' => "tax_rule:{$locked->id}:verify"]);
        });
    }

    /** A second operator rejects a rule submitted for verification (it never applies). */
    public function reject(TaxRule $rule, string $reason, User $actor): TaxRule
    {
        OperatorChange::assert($actor, $reason, 'tax rules');

        return DB::transaction(function () use ($rule, $reason, $actor) {
            $locked = TaxRule::query()->lockForUpdate()->findOrFail($rule->id);
            if (in_array($actor->id, [$locked->created_by, $locked->submitted_by], true) && $locked->status === TaxRuleStatus::Review) {
                throw new RuntimeException('A tax rule is rejected by an operator other than the one who drafted or submitted it (the maker retires their own).');
            }

            return $this->transition($locked, [TaxRuleStatus::Review], TaxRuleStatus::Rejected, AuditAction::TaxRuleRejected, $reason, $actor,
                ['rejected_by' => $actor->id, 'rejected_at' => now()], ['maker_id' => $locked->created_by, 'checker_id' => $actor->id, 'correlation_id' => "tax_rule:{$locked->id}:reject"]);
        });
    }

    public function retire(TaxRule $rule, string $reason, User $actor): TaxRule
    {
        OperatorChange::assert($actor, $reason, 'tax rules');

        return $this->transition($rule, [TaxRuleStatus::Draft, TaxRuleStatus::Review, TaxRuleStatus::Verified], TaxRuleStatus::Retired, AuditAction::TaxRuleRetired,
            $reason, $actor, ['retired_by' => $actor->id, 'retired_at' => now()]);
    }

    /**
     * The verified rule in force on $day: a subdivision-specific one before a country-wide one; the latest started
     * version (then the highest), unless it has expired (an expired rule never falls back to an older version). With
     * $locality: only the rule of that local tax jurisdiction (never the state's or another locality's).
     */
    public function inForce(TaxRegime $regime, string $country, ?string $subdivision, string $category, string $day, ?string $locality = null): ?TaxRule
    {
        $latest = $this->latestStarted($regime, $country, $subdivision, $category, $day, exact: $locality !== null, locality: $locality);

        return $latest === null || ($latest->effective_to !== null && $latest->effective_to->toDateString() < $day) ? null : $latest;
    }

    /** Why no rule is in force: 'expired', 'pending' (a version waits for verification) or 'missing'. */
    public function whyNotInForce(TaxRegime $regime, string $country, ?string $subdivision, string $category, string $day, ?string $locality = null): string
    {
        if ($this->latestStarted($regime, $country, $subdivision, $category, $day, exact: $locality !== null, locality: $locality) !== null) {
            return 'expired';
        }
        $pending = TaxRule::query()->where(['regime' => $regime, 'country' => $country, 'tax_category' => $category, 'locality' => (string) $locality])
            ->whereIn('subdivision', $locality !== null ? [(string) $subdivision] : array_values(array_unique(['', (string) $subdivision])))
            ->whereIn('status', [TaxRuleStatus::Draft, TaxRuleStatus::Review])->exists();

        return $pending ? 'pending' : 'missing';
    }

    /** What a rule version means on $day (CURRENT, SCHEDULED, SUPERSEDED, EXPIRED, PENDING_VERIFICATION …). */
    public function state(TaxRule $rule, ?string $day = null): TaxRuleState
    {
        $day ??= now()->toDateString();

        return match ($rule->status) {
            TaxRuleStatus::Draft => TaxRuleState::Draft,
            TaxRuleStatus::Review => TaxRuleState::PendingVerification,
            TaxRuleStatus::Rejected => TaxRuleState::Rejected,
            TaxRuleStatus::Retired => TaxRuleState::Retired,
            TaxRuleStatus::Verified => match (true) {
                $rule->effective_from->toDateString() > $day => TaxRuleState::Scheduled,
                $this->latestStarted($rule->regime, $rule->country, $rule->subdivision === '' ? null : $rule->subdivision, $rule->tax_category, $day, exact: true,
                    locality: $rule->locality === '' ? null : $rule->locality)?->id !== $rule->id => TaxRuleState::Superseded,
                $rule->effective_to !== null && $rule->effective_to->toDateString() < $day => TaxRuleState::Expired,
                default => TaxRuleState::Current,
            },
        };
    }

    /**
     * state() for many rules at once, from the rules given (a page listing them): every version of a scope must be in
     * $rules. One pass, no query per rule.
     *
     * @param  Collection<int, TaxRule>  $rules
     * @return array<int, TaxRuleState> by rule id
     */
    public function states(Collection $rules, ?string $day = null): array
    {
        $day ??= now()->toDateString();
        $latest = $rules->filter(fn (TaxRule $r) => $r->status === TaxRuleStatus::Verified && $r->effective_from->toDateString() <= $day)
            ->groupBy(fn (TaxRule $r) => $r->scopeKey())
            ->map(fn (Collection $scope) => $scope->sortByDesc(fn (TaxRule $r) => [$r->effective_from->toDateString(), $r->version])->first()?->id);
        $states = [];
        foreach ($rules as $rule) {
            $states[$rule->id] = match ($rule->status) {
                TaxRuleStatus::Draft => TaxRuleState::Draft,
                TaxRuleStatus::Review => TaxRuleState::PendingVerification,
                TaxRuleStatus::Rejected => TaxRuleState::Rejected,
                TaxRuleStatus::Retired => TaxRuleState::Retired,
                TaxRuleStatus::Verified => match (true) {
                    $rule->effective_from->toDateString() > $day => TaxRuleState::Scheduled,
                    ($latest[$rule->scopeKey()] ?? null) !== $rule->id => TaxRuleState::Superseded,
                    $rule->effective_to !== null && $rule->effective_to->toDateString() < $day => TaxRuleState::Expired,
                    default => TaxRuleState::Current,
                },
            };
        }

        return $states;
    }

    /** Every version of a rule's scope, newest first (the history page). @return Collection<int, TaxRule> */
    public function history(TaxRule $rule): Collection
    {
        return TaxRule::query()->where(['regime' => $rule->regime, 'country' => $rule->country, 'subdivision' => $rule->subdivision, 'locality' => $rule->locality,
            'tax_category' => $rule->tax_category])
            ->orderByDesc('version')->get();
    }

    private function latestStarted(TaxRegime $regime, string $country, ?string $subdivision, string $category, string $day, bool $exact = false, ?string $locality = null): ?TaxRule
    {
        $subdivisions = $exact ? [(string) $subdivision] : array_values(array_unique(['', (string) $subdivision]));

        return TaxRule::query()->where(['regime' => $regime, 'country' => $country, 'tax_category' => $category, 'status' => TaxRuleStatus::Verified, 'locality' => (string) $locality])
            ->whereIn('subdivision', $subdivisions)
            ->whereDate('effective_from', '<=', $day)
            ->get()
            ->sortByDesc(fn (TaxRule $r) => [$r->subdivision !== '' ? 1 : 0, $r->effective_from->toDateString(), $r->version])
            ->first();
    }

    /** @param  list<TaxRuleStatus>  $from  @param  array<string, mixed>  $attributes  @param  array<string, mixed>  $metadata */
    private function transition(TaxRule $rule, array $from, TaxRuleStatus $to, AuditAction $action, string $reason, User $actor, array $attributes, array $metadata = []): TaxRule
    {
        return DB::transaction(function () use ($rule, $from, $to, $action, $reason, $actor, $attributes, $metadata) {
            $locked = TaxRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($locked->status === $to) {
                return $locked;
            }
            if (! in_array($locked->status, $from, true)) {
                throw new RuntimeException("A {$locked->status->value} tax rule cannot become {$to->value}.");
            }
            $before = $locked->status->value;
            $locked->forceFill(['status' => $to] + $attributes)->save();
            $this->record($action, $locked, $before, $to->value, $reason, $actor, $metadata);

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

    /** @return array<string, mixed> outcome => components list, or the object form when more than components is given */
    private function outcomes(TaxRegime $regime, array $outcomes): array
    {
        if ($outcomes === []) {
            throw new RuntimeException('A tax rule defines at least one outcome.');
        }
        $normalised = [];
        foreach ($outcomes as $key => $definition) {
            if (! is_string($key) || preg_match('/^[a-z0-9_]{2,48}$/', $key) !== 1 || ! is_array($definition)) {
                throw new RuntimeException('Each outcome is a lowercase key with a list of components (or an outcome definition).');
            }
            $object = ! array_is_list($definition);
            $components = $this->components($regime, $key, $object ? (array) ($definition['components'] ?? []) : $definition);
            if (! $object) {
                $normalised[$key] = $components;

                continue;
            }
            if (($unknown = array_diff(array_keys($definition), ['components', 'treatment', 'conditions', 'requires_supplier_registration', 'wording', 'failure_code', 'description', 'taxable_percent'])) !== []) {
                throw new RuntimeException("The outcome {$key} has unknown settings: ".implode(', ', $unknown).'.');
            }
            $treatment = isset($definition['treatment']) ? (TaxTreatment::tryFrom((string) $definition['treatment'])
                ?? throw new RuntimeException("The outcome {$key} has an unknown treatment.")) : null;
            $registration = $definition['requires_supplier_registration'] ?? null;
            if ($registration !== null && preg_match('/^[A-Z0-9_:-]{2,40}$/', (string) $registration) !== 1) {
                throw new RuntimeException("The outcome {$key} names an invalid registration type.");
            }
            $share = $definition['taxable_percent'] ?? null;
            if ($share !== null && (preg_match('/^\d{1,3}(\.\d{1,4})?$/', (string) $share) !== 1 || ! BigDecimal::of((string) $share)->isPositive()
                || BigDecimal::of((string) $share)->isGreaterThan(100))) {
                throw new RuntimeException("The outcome {$key} has a taxable share outside 0–100 %.");
            }
            $failure = $definition['failure_code'] ?? null;
            if ($failure !== null && ! defined(TaxUnavailableException::class.'::'.$failure)) {
                throw new RuntimeException("The outcome {$key} names an unknown failure code.");
            }
            $normalised[$key] = array_filter(['components' => $components, 'treatment' => $treatment?->value, 'conditions' => $this->conditions((array) ($definition['conditions'] ?? [])) ?: null,
                'requires_supplier_registration' => $registration, 'wording' => isset($definition['wording']) ? mb_substr(trim((string) $definition['wording']), 0, 500) : null,
                'failure_code' => $failure, 'description' => isset($definition['description']) ? mb_substr(trim((string) $definition['description']), 0, 1000) : null,
                'taxable_percent' => $share === null ? null : (string) $share],
                fn ($v) => $v !== null);
            $normalised[$key]['components'] = $components;
        }

        return $normalised;
    }

    /** @return list<array{type: string, rate: string}> */
    private function components(TaxRegime $regime, string $key, array $components): array
    {
        $types = [];
        $normalised = [];
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
            $normalised[] = ['type' => $type, 'rate' => $rate];
        }

        return $normalised;
    }

    /** @return array<string, mixed> */
    private function conditions(array $conditions): array
    {
        if (($unknown = array_diff(array_keys($conditions), TaxEngine::CONDITIONS)) !== []) {
            throw new RuntimeException('Unknown condition '.implode(', ', $unknown).'; PeopleOS evaluates: '.implode(', ', TaxEngine::CONDITIONS).'.');
        }

        return $conditions;
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
