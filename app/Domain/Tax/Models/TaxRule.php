<?php

namespace App\Domain\Tax\Models;

use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxRounding;
use App\Domain\Tax\Enums\TaxRuleStatus;
use App\Domain\Tax\Support\TaxComponent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SaaS.7: one version of a commercial tax rule (platform catalogue; Markedge's tax on its own invoices, never
 * payroll compliance). Its outcomes price what a regime's determiner decides; it applies only once verified by an
 * operator other than its author, from its effective date. Once submitted its content never changes: only the
 * status moves (review → verified, or retired) with who and why.
 */
#[Fillable(['regime', 'country', 'subdivision', 'tax_category', 'version', 'effective_from', 'outcomes', 'rounding_mode', 'rounding_stage',
    'classification', 'status', 'reason', 'created_by', 'submitted_by', 'submitted_at', 'verified_by', 'verified_at', 'verification_reference',
    'verification_notes', 'retired_by', 'retired_at'])]
class TaxRule extends Model
{
    private const MUTABLE_AFTER_SUBMIT = ['status', 'verified_by', 'verified_at', 'verification_reference', 'verification_notes', 'retired_by', 'retired_at', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $rule): void {
            $original = $rule->getRawOriginal('status');
            if ($original === TaxRuleStatus::Draft->value && array_diff(array_keys($rule->getDirty()), ['status', 'submitted_by', 'submitted_at', 'retired_by', 'retired_at', 'updated_at']) === []) {
                return;
            }
            $allowed = [TaxRuleStatus::Review->value => [TaxRuleStatus::Verified, TaxRuleStatus::Retired], TaxRuleStatus::Verified->value => [TaxRuleStatus::Retired]];
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
            'effective_from' => 'date', 'outcomes' => 'array', 'classification' => 'array', 'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    /** @return list<TaxComponent>|null null when the rule does not define the outcome */
    public function components(string $outcome): ?array
    {
        $outcomes = $this->outcomes ?? [];
        if (! array_key_exists($outcome, $outcomes)) {
            return null;
        }

        return array_map(fn (array $c) => new TaxComponent((string) $c['type'], (string) $c['rate']), array_values($outcomes[$outcome]));
    }

    public function label(): string
    {
        $scope = $this->subdivision !== '' ? $this->subdivision : $this->country;

        return "{$this->regime->value} · {$scope} · {$this->tax_category} v{$this->version}";
    }
}
