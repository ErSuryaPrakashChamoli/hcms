<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\DevelopmentNeed;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\PerformanceCheckIn;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\Appraisals\AppraisalResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Employee 360 → Performance: appraisal history plus a goals summary in the description. */
class PerformanceRelationManager extends RelationManager
{
    protected static string $relationship = 'appraisals';

    protected static ?string $title = 'Performance';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', new Appraisal(['employee_id' => $ownerRecord->id])) ?? false;
    }

    public function table(Table $table): Table
    {
        $employee = $this->getOwnerRecord();
        $goals = Goal::query()->where('employee_id', $employee->id);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['cycle', 'manager.person', 'reviews', 'templateVersion']))
            ->description($this->summary($employee, $goals))
            ->columns([
                TextColumn::make('cycle.name')->label('Cycle'),
                TextColumn::make('cycle.period_end')->label('Period end')->date(),
                TextColumn::make('status')->badge()->color(fn (string $state) => AppraisalResource::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.performance.appraisal_statuses.{$state}", $state)),
                TextColumn::make('manager_rating')->label('Manager')->placeholder('—'),
                TextColumn::make('final_rating')->label('Final')->placeholder('—')->weight('bold'),
                TextColumn::make('final_label')->label('Label')->placeholder('—'),
                TextColumn::make('templateVersion.version')->label('Template')->prefix('v')->placeholder('—')->toggleable(),
                TextColumn::make('locked_at')->label('Locked')->date()->placeholder('—')->toggleable(),
                TextColumn::make('promotion_recommended')->label('Promotion')->formatStateUsing(fn ($state) => $state ? 'Recommended' : '—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([Action::make('open')->label('Open')->url(fn (Appraisal $record) => AppraisalResource::getUrl('view', ['record' => $record]))]);
    }

    /**
     * Phase 7 performance section: goals, recent check-ins and open development needs for anyone
     * who can see the tab; improvement-plan status only for HR, PIP managers or the employee's
     * manager. No review text, private notes or calibration detail.
     */
    private function summary(Model $employee, $goals): string
    {
        $user = auth()->user();
        $parts = [sprintf('Goals: %d active, %d completed · average progress %.0f%%', (clone $goals)->where('status', 'active')->count(), (clone $goals)->where('status', 'completed')->count(), (clone $goals)->where('status', 'active')->avg('progress') ?? 0)];
        $parts[] = 'Check-ins (90 days): '.PerformanceCheckIn::query()->where('employee_id', $employee->id)->where('period_date', '>=', now()->subDays(90)->toDateString())->whereIn('status', ['submitted', 'reviewed'])->count();
        $parts[] = 'Open development needs: '.DevelopmentNeed::query()->where('employee_id', $employee->id)->whereIn('status', ['open', 'in_progress'])->count();
        $seesPip = $user->can('performance.view') || $user->can('performance.pip') || app(PerformanceRelationships::class)->manages(EmployeeOwnedPolicy::employeeOf($user), $employee->id);
        if ($seesPip) {
            $plan = ImprovementPlan::query()->where('employee_id', $employee->id)->whereIn('status', ['draft', 'active', 'extended'])->first();
            $parts[] = 'Improvement plan: '.($plan ? config("peopleos.performance.pip_statuses.{$plan->status}").' until '.$plan->end_date->toDateString() : 'none open');
        }

        return implode(' · ', $parts);
    }
}
