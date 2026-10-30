<?php

namespace App\Domain\Letters\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * HROne setup parity: a policy may name a letter template to draft when something is decided (confirmation, exit).
 * The letter is generated for review like any other; a missing template or a generation error never blocks the decision.
 */
final class PolicyLetters
{
    public static function draft(mixed $templateId, Employee $employee, ?User $actor, Model $source): void
    {
        if (! is_numeric($templateId)) {
            return;
        }
        try {
            $template = LetterTemplate::query()->where('status', 'active')->find((int) $templateId);
            if ($template !== null) {
                app(Letters::class)->generate($template, $employee, [], $actor, $source);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
