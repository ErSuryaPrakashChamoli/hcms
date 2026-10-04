<?php

namespace App\Filament\Pages;

use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Configuration\Enums\ChangeStatus;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\WorkforcePulse;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Filament\Resources\ConfigurationChanges\ConfigurationChangeResource;
use App\Filament\Resources\WorkforcePlans\WorkforcePlanResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Workforce Command Centre (§56; Experience Transformation §26). Organised around four questions: what
 * changed, what needs attention, what is trending and what requires a decision. Every figure comes from
 * WorkforceMetrics with its own permission and small-population suppression; nothing individual is shown
 * to a viewer who may only see aggregates.
 */
class WorkforceCommandCentre extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'Workforce Command Centre';

    protected static ?string $title = 'Workforce Command Centre';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.workforce-command-centre';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('analytics.executive') ?? false;
    }

    public function getMetrics(): array
    {
        return app(WorkforceMetrics::class)->all(['headcount', 'people_cost', 'attrition_rate', 'absenteeism_rate', 'joiners_30d', 'exits_30d', 'high_performers', 'cost_per_head', 'avg_tenure_months', 'women_share', 'learning_completion', 'open_grievances']);
    }

    public function getTrends(): array
    {
        $m = app(WorkforceMetrics::class);

        return ['headcount' => $m->series('headcount'), 'joiners' => $m->series('joiners'), 'exits' => $m->series('exits'), 'people_cost' => $m->series('people_cost')];
    }

    public function getCriticalSkills(): array
    {
        return app(WorkforceMetrics::class)->criticalSkills();
    }

    /** UX.15: the workforce told as a story ("Workforce pulse"). */
    public function getHeading(): string
    {
        return 'Workforce pulse';
    }

    public function getSubheading(): ?string
    {
        return $this->pulse['headline'];
    }

    #[Computed]
    public function pulse(): array
    {
        return app(WorkforcePulse::class)->for(auth()->user());
    }

    /** What changed: this month against last month, from the 12-month series. @return list<array{label: string, now: float, before: float, rising_is_bad: bool}> */
    public function getChanges(): array
    {
        $t = $this->trends;
        $pick = fn (string $key) => array_values($t[$key]['series'])[0] ?? [];
        $out = [];
        foreach (['joiners' => ['Joined this month', false], 'exits' => ['Left this month', true], 'headcount' => ['Headcount at month end', false]] as $key => [$label, $risingIsBad]) {
            $v = $pick($key);
            if (count($v) >= 2) {
                $out[] = ['label' => $label, 'now' => (float) end($v), 'before' => (float) $v[count($v) - 2], 'rising_is_bad' => $risingIsBad];
            }
        }

        return $out;
    }

    /** Needs attention: aggregate risks, each with the reason it matters. @return list<array{title: string, why: string, value: string, severity: string, url: ?string}> */
    public function getRisks(): array
    {
        // UX.15.20: from the pulse's metrics (same viewer, same permissions), not a second full metric set.
        $m = $this->pulse['metrics'];
        $risks = [];
        $value = fn (string $k) => ($m[$k]['restricted'] ?? false) ? null : $m[$k]['value'] ?? null;
        if (($a = $value('attrition_rate')) !== null && $a >= 15) {
            $risks[] = ['title' => 'Attrition is high', 'why' => 'More than 15% of people left in the last 12 months.', 'value' => round($a, 1).'%', 'severity' => $a >= 25 ? 'danger' : 'warning', 'url' => null];
        }
        if (($ab = $value('absenteeism_rate')) !== null && $ab >= 5) {
            $risks[] = ['title' => 'Absenteeism above 5%', 'why' => 'Unplanned absence is eating into scheduled days.', 'value' => round($ab, 1).'%', 'severity' => 'warning', 'url' => null];
        }
        if (($l = $value('learning_completion')) !== null && $l < 80) {
            $risks[] = ['title' => 'Mandatory learning behind', 'why' => 'Fewer than 80% of mandatory enrolments are complete.', 'value' => round($l).'%', 'severity' => $l < 50 ? 'danger' : 'warning', 'url' => null];
        }
        $noExperts = collect($this->pulse['critical_skills'])->where('experts', 0)->count();
        if ($noExperts > 0) {
            $risks[] = ['title' => 'Skills without an expert', 'why' => 'No advanced or expert holder: a succession and delivery risk.', 'value' => (string) $noExperts, 'severity' => 'warning', 'url' => null];
        }
        if (($g = $value('open_grievances')) !== null && $g > 0) {
            $risks[] = ['title' => 'Open grievance cases', 'why' => 'Cases still being handled; details stay with authorised handlers.', 'value' => (string) $g, 'severity' => 'info', 'url' => null];
        }

        return $risks;
    }

    /** Requires a decision: items waiting for this viewer or their approval body. @return list<array{title: string, count: int, url: ?string, tone: string}> */
    public function getDecisions(): array
    {
        $user = auth()->user();
        $out = [];
        $mine = app(ApprovalCenter::class)->count($user);
        if ($mine > 0 || Approvals::canAccess()) {
            $out[] = ['title' => 'Your approvals', 'count' => $mine, 'url' => Approvals::canAccess() ? Approvals::getUrl() : null, 'tone' => 'amber'];
        }
        if ($user->hasPermission('workforce.approve')) {
            $out[] = ['title' => 'Headcount plans awaiting review', 'count' => WorkforcePlanVersion::query()->whereIn('status', ['submitted', 'under_review'])->count(),
                'url' => rescue(fn () => WorkforcePlanResource::getUrl('index'), null, false), 'tone' => 'violet'];
        }
        if ($user->hasPermission('configuration.publish')) {
            $out[] = ['title' => 'Configuration changes awaiting approval', 'count' => ConfigurationChange::query()->where('status', ChangeStatus::PendingApproval)->count(),
                'url' => rescue(fn () => ConfigurationChangeResource::getUrl('index'), null, false), 'tone' => 'sky'];
        }

        return $out;
    }

    #[Computed]
    public function metrics(): array
    {
        return $this->getMetrics();
    }

    #[Computed]
    public function trends(): array
    {
        return $this->getTrends();
    }
}
