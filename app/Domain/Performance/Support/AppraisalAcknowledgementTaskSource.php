<?php

namespace App\Domain\Performance\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\Appraisal;
use App\Filament\Resources\Appraisals\AppraisalResource;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity GO-9 (matrix 27 §27.11 #16, HROne "Ack overall rating"): a "My tasks" item for the employee whose rating has
 * been published (HP-8b) and not yet acknowledged, while the appraisal can still be acknowledged (not locked by the cycle's
 * close). Only when the employee may see that rating (AppraisalVisibility: the performance policy's "Final Rating is
 * visible to" may hide it from them). No due date; the title never carries the rating.
 */
final class AppraisalAcknowledgementTaskSource implements ExperienceTaskSource
{
    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if ($employee === null) {
            return collect();
        }

        return Appraisal::query()->withoutGlobalScope(AccessScope::class)->with(['cycle', 'reviews'])
            ->where('employee_id', $employee->id)->where('status', 'finalized')->whereNotNull('published_at')->whereNull('locked_at')
            ->orderBy('id')->get()
            ->filter(fn (Appraisal $appraisal) => AppraisalVisibility::ratingsVisible($appraisal, $user))
            ->map(fn (Appraisal $appraisal) => new ExperienceTask('performance', 'rating_acknowledgement',
                'Acknowledge your performance rating for '.($appraisal->cycle?->name ?? 'the cycle'), $appraisal->cycle?->name, null,
                $this->url($appraisal), ['employee_id' => (int) $appraisal->employee_id, 'requested_at' => $appraisal->published_at]))
            ->values();
    }

    private function url(Appraisal $appraisal): ?string
    {
        try {
            return AppraisalResource::getUrl('view', ['record' => $appraisal->id]);
        } catch (Throwable) {
            return null;
        }
    }
}
