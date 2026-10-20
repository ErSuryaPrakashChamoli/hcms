<?php

namespace App\Filament\Pages;

use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Employment\Models\Employee;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Skills\Services\SkillProfiles;
use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
use App\Filament\Support\LearningActions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Phase 8 manager view: learning status, mandatory training, pending approvals, skill gaps and
 * development plans of the employees the manager manages through configured relationship types.
 * Aggregate queries per team (no per-employee query loops for counts).
 */
class TeamLearning extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'Team learning';

    protected static ?string $title = 'Team learning';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.team-learning';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('learning.team') || $user->can('learning.assign')) && app(PerformanceRelationships::class)->reportIds(LearningActions::me())->isNotEmpty();
    }

    protected function getHeaderActions(): array
    {
        return [LearningActions::enrol()];
    }

    public function teamIds(): Collection
    {
        return app(PerformanceRelationships::class)->reportIds(LearningActions::me());
    }

    /** @return list<array<string, mixed>> */
    public function members(): array
    {
        $ids = $this->teamIds();
        $counts = LearningEnrolment::query()->whereIn('employee_id', $ids)
            ->selectRaw("employee_id, sum(case when status in ('assigned','enrolled','approved','in_progress') then 1 else 0 end) as open, sum(case when status = 'overdue' then 1 else 0 end) as overdue, sum(case when status = 'overdue' and is_mandatory = 1 then 1 else 0 end) as mandatory_overdue, sum(case when status = 'completed' then 1 else 0 end) as completed")
            ->groupBy('employee_id')->get()->keyBy('employee_id');
        $expiring = LearningCertificate::query()->whereIn('employee_id', $ids)->whereIn('status', ['valid', 'expiring'])->whereBetween('expires_on', [now()->toDateString(), now()->addDays(60)->toDateString()])
            ->selectRaw('employee_id, count(*) as total')->groupBy('employee_id')->pluck('total', 'employee_id');

        return Employee::query()->with('person')->whereIn('id', $ids)->orderBy('employee_code')->get()->map(fn (Employee $e) => [
            'name' => $e->person?->full_name, 'code' => $e->employee_code,
            'open' => (int) ($counts[$e->id]->open ?? 0), 'overdue' => (int) ($counts[$e->id]->overdue ?? 0), 'mandatory_overdue' => (int) ($counts[$e->id]->mandatory_overdue ?? 0),
            'completed' => (int) ($counts[$e->id]->completed ?? 0), 'expiring' => (int) ($expiring[$e->id] ?? 0),
        ])->all();
    }

    public function pendingApprovals(): Collection
    {
        return LearningEnrolment::query()->with(['employee.person', 'course'])->whereIn('employee_id', $this->teamIds())->whereIn('status', ['requested', 'pending_approval'])->orderBy('requested_at')->get();
    }

    /** @return list<array<string, mixed>> skill gaps of the team, per person */
    public function gaps(): array
    {
        return Employee::query()->with('person')->whereIn('id', $this->teamIds())->get()
            ->flatMap(fn (Employee $e) => collect(app(SkillProfiles::class)->gaps($e))->map(fn ($g) => $g + ['employee' => $e->person?->full_name]))
            ->sortByDesc('gap')->values()->all();
    }

    public function plans(): Collection
    {
        return DevelopmentPlan::query()->with('employee.person')->withCount(['items', 'items as open_items_count' => fn ($q) => $q->where('status', 'open')])
            ->whereIn('employee_id', $this->teamIds())->whereNotIn('status', ['archived'])->orderByDesc('id')->get();
    }

    public function enrolmentUrl(LearningEnrolment $enrolment): string
    {
        return LearningEnrolmentResource::getUrl('view', ['record' => $enrolment]);
    }
}
