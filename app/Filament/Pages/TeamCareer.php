<?php

namespace App\Filament\Pages;

use App\Domain\Career\Models\CareerAspirationEntry;
use App\Domain\Career\Models\CareerGoal;
use App\Domain\Career\Models\CareerProfile;
use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\Successor;
use App\Domain\Talent\Models\TalentReviewSession;
use App\Filament\Resources\TalentReviews\TalentReviewResource;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Phase 9 manager view (Team career, Team development, Succession, Talent review): the employees the
 * manager manages through configured relationship types only (mentor, buddy and project lead
 * excluded). Aspirations and mobility appear only when the employee shares them; succession
 * candidacy needs succession.team; talent reviews only those the manager participates in.
 */
class TeamCareer extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'Team career';

    protected static ?string $title = 'Team career & succession';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.team-career';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('career.team') || $user->can('succession.team')) && app(PerformanceRelationships::class)->reportIds(TalentActions::me())->isNotEmpty();
    }

    public function teamIds(): Collection
    {
        return app(PerformanceRelationships::class)->reportIds(TalentActions::me());
    }

    /** @return list<array<string, mixed>> */
    public function members(): array
    {
        $ids = $this->teamIds();
        $profiles = CareerProfile::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $ids)->get()->keyBy('employee_id');
        $aspirations = CareerAspirationEntry::query()->withoutGlobalScope(AccessScope::class)->with('targetDesignation')->whereIn('employee_id', $ids)->where('status', 'current')->get()->groupBy('employee_id');
        $goals = CareerGoal::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $ids)->whereIn('status', ['active', 'paused'])->get()->groupBy('employee_id');
        $plans = DevelopmentPlan::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $ids)->whereIn('status', ['draft', 'active', 'on_hold'])
            ->withCount(['items', 'items as open_items_count' => fn ($q) => $q->where('status', 'open')])->get()->groupBy('employee_id');
        $canCareer = auth()->user()->can('career.team');

        return Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->whereIn('id', $ids)->orderBy('employee_code')->get()->map(function (Employee $e) use ($profiles, $aspirations, $goals, $plans, $canCareer) {
            $p = $profiles[$e->id] ?? null;

            return [
                'code' => $e->employee_code, 'name' => $e->person?->full_name,
                'aspirations' => $canCareer && $p?->share_aspirations_with_manager ? ($aspirations[$e->id] ?? collect())->map(fn ($a) => config("peopleos.career.aspiration_terms.{$a->term}").': '.($a->targetDesignation?->name ?? $a->aspiration))->all() : null,
                'goals' => $canCareer && ($p === null || $p->share_goals_with_manager) ? ($goals[$e->id] ?? collect())->pluck('title')->all() : null,
                'mobility' => $canCareer && $p?->share_mobility_with_manager ? collect($p->mobility ?? [])->filter()->keys()->map(fn ($k) => config("peopleos.career.mobility_options.{$k}", $k))->all() : null,
                'development' => ($plans[$e->id] ?? collect())->map(fn ($plan) => $plan->title.' ('.($plan->items_count - $plan->open_items_count).'/'.$plan->items_count.')')->all(),
            ];
        })->all();
    }

    /** Candidacy of the manager's team — only with succession.team. */
    public function succession(): Collection
    {
        if (! auth()->user()->can('succession.team')) {
            return collect();
        }

        return Successor::query()->withoutGlobalScope(AccessScope::class)->with(['employee.person', 'plan.position'])->whereIn('employee_id', $this->teamIds())->where('status', 'active')->get()
            ->map(fn (Successor $s) => [
                'name' => $s->employee?->person?->full_name, 'position' => $s->plan?->position?->title,
                'readiness' => ReadinessAssessment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $s->employee_id)->where('target_key', ReadinessAssessment::targetKey($s->plan?->critical_position_id, null))->where('status', 'current')->value('readiness_level'),
            ]);
    }

    public function reviews(): Collection
    {
        return TalentReviewSession::query()->whereIn('status', ['draft', 'in_progress'])->whereJsonContains('participants', (int) auth()->id())->orderBy('scheduled_for')->get();
    }

    public function reviewsUrl(): string
    {
        return TalentReviewResource::getUrl('index');
    }
}
