<?php

namespace App\Domain\Tax\Models;

use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxRounding;
use App\Domain\Tax\Enums\TaxRuleStatus;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Support\TaxComponent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SaaS.7: one version of a commercial tax rule (platform catalogue; Markedge's tax on its own invoices, never
 * payroll compliance). Statutory values are data here, never constants in code: rates, treatments, conditions,
 * registration requirements, invoice wording, effective dates, and the statutory source they come from. A rule
 * applies only once verified by an operator other than its author (or the operator who loaded it from the shipped
 * statutory dataset), from its effective date until its expiry. Once submitted its content never changes: a change
 * in the law is a new version from a new date; only the status moves (verified, rejected, retired) with who and why.
 *
 * An outcome is either a list of components (the original form) or an object: components, treatment (e.g.
 * zero_rated, reverse_charge, not_taxable), conditions, the supplier registration it requires, the invoice wording
 * the law prescribes, and the reason code a failed condition reports.
 */
#[Fillable(['regime', 'country', 'subdivision', 'locality', 'tax_category', 'rule_code', 'version', 'effective_from', 'effective_to', 'outcomes', 'rounding_mode',
    'rounding_stage', 'classification', 'conditions', 'statutory_notes', 'amount_basis', 'source', 'source_reference', 'source_url', 'source_date', 'origin', 'dataset_version',
    'dataset_key', 'dataset_status', 'status', 'reason', 'created_by', 'submitted_by', 'submitted_at', 'verified_by', 'verified_at', 'verification_reference',
    'verification_notes', 'retired_by', 'retired_at', 'rejected_by', 'rejected_at'])]
class TaxRule extends Model
{
    public const ORIGIN_OPERATOR = 'operator';

    public const ORIGIN_DATASET = 'statutory_dataset';

    private const MUTABLE_AFTER_SUBMIT = ['status', 'verified_by', 'verified_at', 'verification_reference', 'verification_notes', 'retired_by', 'retired_at',
        'rejected_by', 'rejected_at', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $rule): void {
            $original = $rule->getRawOriginal('status');
            if ($original === TaxRuleStatus::Draft->value && array_diff(array_keys($rule->getDirty()), ['status', 'submitted_by', 'submitted_at', 'retired_by', 'retired_at', 'updated_at']) === []) {
                return;
            }
            $allowed = [TaxRuleStatus::Review->value => [TaxRuleStatus::Verified, TaxRuleStatus::Retired, TaxRuleStatus::Rejected], TaxRuleStatus::Verified->value => [TaxRuleStatus::Retired]];
            if (array_diff(array_keys($rule->getDirty()), self::MUTABLE_AFTER_SUBMIT) !== []
                || ($rule->isDirty('status') && ! in_array($rule->status, $allowed[$original] ?? [], true))) {
                throw new RuntimeException('A submitted tax rule never changes: draft a new version.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Tax rules are never deleted: issued invoices refer to them.');
        });
    }

    protected function casts(): array
    {
        return ['regime' => TaxRegime::class, 'status' => TaxRuleStatus::class, 'rounding_mode' => TaxRounding::class, 'version' => 'integer',
            'effective_from' => 'date', 'effective_to' => 'date', 'source_date' => 'date', 'outcomes' => 'array', 'classification' => 'array', 'conditions' => 'array', 'statutory_notes' => 'array',
            'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'retired_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    /**
     * The outcome as configured: components, treatment, conditions, required supplier registration, invoice wording.
     *
     * @return array{components: list<TaxComponent>, treatment: ?TaxTreatment, conditions: array<string, mixed>, requires_supplier_registration: ?string,
     *     wording: ?string, failure_code: ?string, description: ?string, taxable_percent: ?string}|null null when the rule does not define the outcome
     */
    public function outcome(string $outcome): ?array
    {
        $outcomes = $this->outcomes ?? [];
        if (! array_key_exists($outcome, $outcomes)) {
            return null;
        }
        $definition = $outcomes[$outcome];
        $object = is_array($definition) && ! array_is_list($definition);
        $components = $object ? ($definition['components'] ?? []) : $definition;

        return ['components' => array_map(fn (array $c) => new TaxComponent((string) $c['type'], (string) $c['rate']), array_values($components)),
            'treatment' => $object && isset($definition['treatment']) ? TaxTreatment::tryFrom((string) $definition['treatment']) : null,
            'conditions' => $object ? (array) ($definition['conditions'] ?? []) : [],
            'requires_supplier_registration' => $object ? ($definition['requires_supplier_registration'] ?? null) : null,
            'wording' => $object ? ($definition['wording'] ?? null) : null,
            'failure_code' => $object ? ($definition['failure_code'] ?? null) : null,
            'description' => $object ? ($definition['description'] ?? null) : null,
            'taxable_percent' => $object && isset($definition['taxable_percent']) ? (string) $definition['taxable_percent'] : null];
    }

    /** @return list<TaxComponent>|null null when the rule does not define the outcome */
    public function components(string $outcome): ?array
    {
        return $this->outcome($outcome)['components'] ?? null;
    }

    public function label(): string
    {
        $scope = ($this->subdivision !== '' ? $this->subdivision : $this->country).($this->locality !== '' && $this->locality !== null ? " / {$this->locality}" : '');

        return "{$this->regime->value} · {$scope} · {$this->tax_category} v{$this->version}";
    }

    public function scopeKey(): string
    {
        return "{$this->regime->value}|{$this->country}|{$this->subdivision}|{$this->locality}|{$this->tax_category}";
    }
}
