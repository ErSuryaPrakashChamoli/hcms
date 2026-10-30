<?php

namespace App\Domain\Performance\Services;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity HP-8f (PIP → PIP policy): the PIP policy (type `pip`) that applies to an employee — the plan's
 * period, how many extensions, letters when it starts and when its outcome is recorded, start and close checklists, and
 * whether the plan and its outcome need approval. Without a policy (or with a blank answer) plans work as before.
 */
final class ImprovementPlanRules
{
    public function __construct(private readonly PolicyResolver $policies) {}

    public function policyFor(?Employee $employee, CarbonInterface|string|null $on = null): ?PolicyVersion
    {
        return $employee === null ? null : $this->policies->resolve('pip', $employee, $on ?? today());
    }

    private static function yes(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'true'], true);
    }

    /** The end date of a plan starting on $start: the policy's period, else two months (the form's default before). */
    public function defaultEnd(?Employee $employee, CarbonInterface|string $start): Carbon
    {
        $days = $this->policyFor($employee)?->setting('duration_days');

        return is_numeric($days) && (int) $days > 0 ? Carbon::parse($start)->addDays((int) $days) : Carbon::parse($start)->addMonths(2);
    }

    /** Extensions allowed (0 = any, as before). */
    public function maxExtensions(?Employee $employee): int
    {
        $max = $this->policyFor($employee)?->setting('max_extensions');

        return is_numeric($max) ? max(0, (int) $max) : 0;
    }

    public function needsApproval(?Employee $employee): bool
    {
        return $this->policyFor($employee)?->setting('approval') === 'workflow';
    }

    public function needsEvaluationApproval(?Employee $employee): bool
    {
        return self::yes($this->policyFor($employee)?->setting('evaluation_approval'));
    }

    /** The letter template drafted when the plan starts ($when activate) or its outcome is recorded (close), if any. */
    public function letterTemplate(?Employee $employee, string $when): ?int
    {
        $policy = $this->policyFor($employee);
        $on = $when === 'close' ? 'letter_on_close' : 'letter_on_activate';
        $template = $policy?->setting($when === 'close' ? 'close_letter_template' : 'activate_letter_template');

        return self::yes($policy?->setting($on)) && is_numeric($template) ? (int) $template : null;
    }

    /**
     * The start or close checklist ($which start | close): items with a title, the days after the start (or the outcome)
     * they fall due and their owner.
     *
     * @return list<array{title: string, due_offset_days: int, owner: string}>
     */
    public function checklist(?Employee $employee, string $which): array
    {
        $policy = $this->policyFor($employee);
        if (! self::yes($policy?->setting($which === 'close' ? 'map_close_checklist' : 'map_start_checklist'))) {
            return [];
        }

        return array_values(array_map(fn (array $row) => [
            'title' => mb_strimwidth(trim((string) $row['title']), 0, 255), 'due_offset_days' => max(0, (int) ($row['due_offset_days'] ?? 0)),
            'owner' => in_array($row['owner'] ?? null, ['manager', 'hr', 'employee'], true) ? $row['owner'] : 'manager',
        ], array_filter((array) $policy->setting($which === 'close' ? 'close_checklist' : 'start_checklist', []), fn ($row) => is_array($row) && filled($row['title'] ?? null))));
    }

    /**
     * Checked when a PIP policy is saved or published: letters asked for name a template, checklists asked for have items.
     *
     * @param  array<string, mixed>  $settings
     */
    public function validatePolicySettings(array $settings): void
    {
        foreach (['letter_on_activate' => 'activate_letter_template', 'letter_on_close' => 'close_letter_template'] as $flag => $template) {
            if (self::yes($settings[$flag] ?? null) && ! is_numeric($settings[$template] ?? null)) {
                throw new ConfigurationException('Choose the letter template to send.');
            }
        }
        foreach (['map_start_checklist' => 'start_checklist', 'map_close_checklist' => 'close_checklist'] as $flag => $list) {
            $rows = array_filter((array) ($settings[$list] ?? []), fn ($row) => is_array($row) && filled($row['title'] ?? null));
            if (self::yes($settings[$flag] ?? null) && $rows === []) {
                throw new ConfigurationException('Add at least one checklist item, or answer no.');
            }
        }
    }
}
