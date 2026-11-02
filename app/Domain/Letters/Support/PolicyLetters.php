<?php

namespace App\Domain\Letters\Support;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * HROne setup parity: a policy may name a letter template to draft when something is decided (confirmation, exit).
 * The letter is generated for review like any other; a missing template or a generation error never blocks the decision.
 *
 * HROne parity WO-13: the letter keeps the hook that drafted it (`process`, `{policy type}.{setting}`), which the Letters
 * register shows as "Process" (HROne's "Letter process"); HOOKS names every hook, including the new ones (resignation
 * accepted, transfer approved, transfer in effect), and template() reads a hook's template from the employee's policy for
 * bulk generation "as per policy".
 */
final class PolicyLetters
{
    /**
     * Values a transfer letter may print besides the employee's: {{ transfer.effective_date }}, {{ transfer.changes }} (what
     * the transfer changes, as approved) and {{ transfer.reason }}.
     *
     * @return array{transfer: array{effective_date: mixed, changes: string, reason: ?string}}
     */
    public static function transferValues(Model $request): array
    {
        return ['transfer' => ['effective_date' => $request->getAttribute('effective_date'), 'changes' => method_exists($request, 'summary') ? (string) $request->summary() : '',
            'reason' => $request->getRelationValue('reason')?->name]];
    }

    /** policy type.setting => what drafts the letter (the register's "Process"). */
    public const HOOKS = [
        'confirmation.letter_on_confirm' => 'Confirmation',
        'confirmation.letter_on_extend' => 'Probation extension',
        'confirmation.letter_on_terminate' => 'Termination during probation',
        'exit.letter_on_exit' => 'Exit started',
        'exit.letter_on_acceptance' => 'Resignation accepted',
        'exit.letter_on_completion' => 'Exit completed (relieving)',
        'transfer.letter_on_request_approval' => 'Transfer approved (intimation)',
        'transfer.letter_on_effect' => 'Transfer in effect',
    ];

    /** Other processes a letter can come from (not a policy hook). */
    public const PROCESSES = [
        'schedule' => 'Letter schedule',
        'bulk' => 'Bulk generation',
    ];

    /** Source models that name a process when the letter has none recorded (letters from before WO-13, other modules). */
    private const SOURCES = [
        'ConfirmationReview' => 'Confirmation', 'ExitCase' => 'Separation', 'TransferRequest' => 'Transfer', 'Appraisal' => 'Performance',
        'ImprovementPlan' => 'Performance improvement', 'Ticket' => 'HR request', 'AlumniRequest' => 'Alumni request', 'Recognition' => 'Recognition',
        'WorkflowInstance' => 'Workflow',
    ];

    /** @param  array<string, mixed>  $extra  values for the template beyond the employee's (e.g. transfer.effective_date) */
    public static function draft(mixed $templateId, Employee $employee, ?User $actor, Model $source, ?string $process = null, array $extra = []): ?Letter
    {
        if (! is_numeric($templateId)) {
            return null;
        }
        try {
            $template = LetterTemplate::query()->where('status', 'active')->find((int) $templateId);
            if ($template !== null) {
                return app(Letters::class)->generate($template, $employee, $extra, $actor, $source, process: $process);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    /** The template a policy hook names for the employee on the date (null when the policy names none). */
    public static function template(string $hook, Employee $employee, ?CarbonInterface $on = null): ?LetterTemplate
    {
        if (! array_key_exists($hook, self::HOOKS)) {
            return null;
        }
        [$type, $setting] = explode('.', $hook, 2);
        $id = app(PolicyResolver::class)->resolve($type, $employee, $on ?? now())?->setting($setting);

        return is_numeric($id) ? LetterTemplate::query()->where('status', 'active')->find((int) $id) : null;
    }

    /** "Process" on the register: the hook, the schedule or bulk run, else the source record's module, else Manual. */
    public static function label(Letter $letter): string
    {
        if ($letter->process !== null) {
            return self::HOOKS[$letter->process] ?? self::PROCESSES[$letter->process] ?? $letter->process;
        }
        if ($letter->source_type !== null) {
            $class = Relation::getMorphedModel($letter->source_type) ?? $letter->source_type;

            return self::SOURCES[class_basename($class)] ?? 'Manual';
        }

        return 'Manual';
    }

    /** @return array<string, string> filter options: every process key a letter may carry */
    public static function options(): array
    {
        return self::HOOKS + self::PROCESSES;
    }
}
