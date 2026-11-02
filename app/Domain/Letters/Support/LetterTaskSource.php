<?php

namespace App\Domain\Letters\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\Letter;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Letters\LetterResource;
use Illuminate\Support\Collection;

/**
 * HROne parity WO-13 (§23.11 #5–7, 12–16, Inbox Insights letter task kinds): "My tasks" items for people who issue letters —
 * one per approved letter not yet issued (confirmation, extension, termination, relieving, resignation acceptance,
 * transfer intimation, transfer, contract and every other type), oldest approval first. Letters waiting for approval are
 * Approval Center items already. Off unless the organisation switches `letters.tasks.issue_queue` on.
 */
final class LetterTaskSource implements ExperienceTaskSource
{
    private const LIMIT = 50;

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if (! (bool) app(SettingsRepository::class)->get('letters.tasks.issue_queue', false) || ! $user->hasPermission('letter.issue')) {
            return collect();
        }

        return Letter::query()->with('employee.person')->where('status', 'approved')->orderBy('approved_at')->orderBy('id')->limit(self::LIMIT)->get()
            ->map(fn (Letter $letter) => new ExperienceTask(
                'letters', 'issue',
                'Issue the '.mb_strtolower((string) config("peopleos.letters.types.{$letter->type}", $letter->type)).' letter of '.($letter->employee?->person?->full_name ?? $letter->employee?->employee_code ?? 'an employee'),
                $letter->number,
                null,
                LetterResource::getUrl('view', ['record' => $letter]),
                ['letter_type' => $letter->type, 'employee_id' => $letter->employee_id],
            ));
    }
}
