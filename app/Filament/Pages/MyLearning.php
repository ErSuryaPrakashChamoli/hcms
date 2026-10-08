<?php

namespace App\Filament\Pages;

use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Employment\Models\Employee;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Skills\Models\SkillAssessment;
use App\Domain\Skills\Services\SkillProfiles;
use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
use App\Filament\Support\LearningActions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/** Phase 8 employee learning experience: my learning, certificates, skills, assessments and development plans. */
class MyLearning extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My learning';

    protected static ?string $title = 'My learning';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.my-learning';

    public static function canAccess(): bool
    {
        return auth()->check() && LearningActions::me() !== null && auth()->user()->can('learning.learn');
    }

    protected function getHeaderActions(): array
    {
        return [LearningActions::requestLearning()];
    }

    public function me(): Employee
    {
        return LearningActions::me();
    }

    public function enrolments(): Collection
    {
        return LearningEnrolment::query()->with(['course', 'courseVersion'])->where('employee_id', $this->me()->id)
            ->orderByRaw("case when status = 'overdue' then 0 when status in ('requested','pending_approval','waitlisted') then 1 when status in ('assigned','enrolled','approved','in_progress') then 2 else 3 end")
            ->orderBy('due_on')->limit(100)->get();
    }

    public function certificates(): Collection
    {
        return LearningCertificate::query()->with('course')->where('employee_id', $this->me()->id)->orderByDesc('issued_on')->get();
    }

    /** @return list<array<string, mixed>> */
    public function skills(): array
    {
        return app(SkillProfiles::class)->profile($this->me());
    }

    public function assessments(): Collection
    {
        return SkillAssessment::query()->with(['skill', 'scaleVersion'])->where('employee_id', $this->me()->id)->whereIn('status', ['finalized', 'superseded'])->orderByDesc('assessed_on')->limit(20)->get();
    }

    public function plans(): Collection
    {
        return DevelopmentPlan::query()->withCount(['items', 'items as open_items_count' => fn ($q) => $q->where('status', 'open')])->where('employee_id', $this->me()->id)->orderByDesc('id')->get();
    }

    public function enrolmentUrl(LearningEnrolment $enrolment): string
    {
        return LearningEnrolmentResource::getUrl('view', ['record' => $enrolment]);
    }
}
