<?php

namespace App\Domain\Organisation\Overrides;

use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Overrides\CompanyAwareOverrideFields;
use App\Domain\Configuration\Overrides\OverrideField;
use App\Domain\Configuration\Overrides\OverrideFields;
use App\Domain\Configuration\Overrides\ValidatesCompanyTimeline;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Services\LevelGradePairs;
use Illuminate\Database\Eloquent\Model;

/**
 * Master relationships R3: a shared designation's default level, grade and job family, which a company may set for
 * itself (Sourcing Manager is L5 / T4 at Markedge and L6 / T5 at FynnEdge). Stored as configuration overrides
 * (effective-dated, versioned, audited); ending one gives the company the shared value again. A company's value must
 * be one that company may use.
 */
final class DesignationOverrideFields implements CompanyAwareOverrideFields, OverrideFields, ValidatesCompanyTimeline
{
    /** column => [label, model] */
    private const LINKS = ['level_id' => ['Level', Level::class], 'grade_id' => ['Grade', Grade::class], 'job_family_id' => ['Job family', JobFamily::class]];

    public function fields(Model $record): array
    {
        $fields = [];
        foreach (self::LINKS as $column => [$label, $model]) {
            $options = $model::query()->withoutGlobalScope(AccessScope::class)->orderBy('name')->pluck('name', 'id')
                ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])->all();
            $fields[$column] = new OverrideField($column, $label, 'select', $options, ['nullable', 'integer'], null, 'Defaults for the company');
        }

        return $fields;
    }

    public function shared(Model $record, string $on): array
    {
        return collect(array_keys(self::LINKS))->mapWithKeys(fn (string $column) => [$column => $record->getAttribute($column) === null ? null : (int) $record->getAttribute($column)])->all();
    }

    /**
     * Across fields on one date: nothing here. The level and grade pair is checked on every date from the change on,
     * after it is stored (validateCompanyTimeline), so that setting both together is not refused halfway.
     */
    public function validate(Model $record, array $values): void {}

    /** Remediation I.6: a company's level and grade must agree (LevelGradePairs). */
    public function timelineFields(): array
    {
        return LevelGradePairs::FIELDS;
    }

    public function timelineSnapshot(Model $record, int $companyId, string $from): array
    {
        return $record instanceof Designation ? app(LevelGradePairs::class)->pairs($record, $from, company: $companyId) : [];
    }

    public function validateCompanyTimeline(Model $record, int $companyId, string $from, array $before): void
    {
        if ($record instanceof Designation) {
            $pairs = app(LevelGradePairs::class);
            $pairs->assertPairs($record, $pairs->pairs($record, $from, company: $companyId), $before);
        }
    }

    public function validateForCompany(Model $record, array $values, int $companyId): void
    {
        $applicability = app(EntityApplicability::class);
        foreach (self::LINKS as $column => [$label, $model]) {
            if (blank($values[$column] ?? null)) {
                continue;
            }
            $target = $model::query()->withoutGlobalScope(AccessScope::class)->find((int) $values[$column]);
            if ($target !== null && ! $applicability->appliesTo($target, $companyId)) {
                $company = Company::query()->withoutGlobalScope(AccessScope::class)->whereKey($companyId)->value('name') ?? 'this company';
                throw new ApplicabilityRefusedException("{$label} \"{$target->name}\" does not apply to {$company}: choose one it may use.");
            }
        }
    }
}
