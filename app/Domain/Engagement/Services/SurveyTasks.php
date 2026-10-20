<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Experience\Contracts\SurveyTaskProvider;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Filament\Pages\MyHr;
use Illuminate\Support\Collection;

/** Phase 13: binds the Phase 12 My HR hook — open surveys the employee has not answered yet (their own participation only). */
final class SurveyTasks implements SurveyTaskProvider
{
    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if ($employee === null || ! $user->hasPermission('engagement.participate')) {
            return collect();
        }
        $versionIds = AccessScope::withoutScoping(fn () => SurveyParticipation::query()->where('employee_id', $employee->id)->whereIn('status', ['invited', 'opened'])->pluck('survey_version_id'));

        return SurveyVersion::query()->with('survey')->whereIn('id', $versionIds)->where('status', 'open')->orderBy('closes_at')->get()
            ->map(fn (SurveyVersion $v) => new ExperienceTask('engagement', 'survey', 'Survey: '.$v->survey->name, $v->survey->code, $v->closes_at, MyHr::getUrl(['tab' => 'surveys'])))
            ->values();
    }
}
