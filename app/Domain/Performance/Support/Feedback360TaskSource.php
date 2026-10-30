<?php

namespace App\Domain\Performance\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Services\FeedbackNominations;
use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Resources\FeedbackNominations\FeedbackNominationResource;
use App\Filament\Resources\FeedbackParticipants\FeedbackParticipantResource;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne setup parity HP-8h / HP-8i: "My tasks" items — 360 feedback asked of the person (due when the window closes),
 * 360 nominations they still have to make, and open periodic reviews they rate.
 */
final class Feedback360TaskSource implements ExperienceTaskSource
{
    public function __construct(private readonly FeedbackNominations $nominations) {}

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        $tasks = collect();
        try {
            $give = $this->url(fn () => FeedbackNominationResource::getUrl('index'));
            $this->nominations->pendingFor($user)->each(fn (FeedbackNomination $n) => $tasks->push(new ExperienceTask(
                'performance', 'feedback_360', '360 feedback for '.($n->employee?->person?->full_name ?? 'a colleague').' ('.$n->round->name.')',
                $n->requested_at?->toDateString(), $n->round->window_closes_on->copy()->endOfDay(), $give,
            )));
            $nominate = $this->url(fn () => FeedbackParticipantResource::getUrl('index', ['tab' => 'nominate']));
            $this->nominations->toNominateFor($user)->each(fn (FeedbackParticipant $p) => $tasks->push(new ExperienceTask(
                'performance', 'feedback_360_nomination', 'Nominate 360 reviewers for '.($p->employee?->person?->full_name ?? 'an employee').' ('.$p->round->name.')',
                $p->created_at?->toDateString(), $p->round->window_closes_on->copy()->endOfDay(), $nominate,
            )));
            if ($employee !== null) {
                PerformancePeriodReview::query()->withoutGlobalScope(AccessScope::class)->with('employee.person')->where('reviewer_id', $employee->id)->where('status', 'open')->orderBy('window_closes_on')->get()
                    ->each(fn (PerformancePeriodReview $r) => $tasks->push(new ExperienceTask(
                        'performance', 'period_review', 'Periodic review of '.($r->employee?->person?->full_name ?? 'an employee').' ('.$r->label().')',
                        $r->opened_at?->toDateString(), $r->window_closes_on->copy()->endOfDay(), $this->url(fn () => AppraisalResource::getUrl('view', ['record' => $r->appraisal_id])),
                    )));
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $tasks;
    }

    private function url(\Closure $url): ?string
    {
        try {
            return $url();
        } catch (Throwable) {
            return null;
        }
    }
}
