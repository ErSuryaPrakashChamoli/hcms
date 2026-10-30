<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\DevelopmentNeed;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\PerformanceCheckIn;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Appraisals\AppraisalResource;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee 360 → Performance: appraisal history plus a goals summary in the description. HROne setup parity HP-8b:
 * ratings show to the employee once published; with performance.profile.show_360 the tab adds the peer rating and the
 * submitted peer / upward / skip-level / external reviews (anonymous authors masked) of published appraisals.
 */
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
        $show360 = (bool) app(SettingsRepository::class)->get('performance.profile.show_360', false);
        $employee = $this->getOwnerRecord();
        $goals = Goal::query()->where('employee_id', $employee->id);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['cycle', 'manager.person', 'reviews', 'templateVersion']))
            ->description($this->summary($employee, $goals))
            ->columns([
                TextColumn::make('cycle.name')->label('Cycle'),
                TextColumn::make('cycle.period_end')->label('Period end')->date(),
                TextColumn::make('status')->badge()->color(fn (string $state) => AppraisalResource::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.performance.appraisal_statuses.{$state}", $state)),
                TextColumn::make('manager_rating')->label('Manager')->placeholder('—')->state(AppraisalResource::rating('manager_rating', 'reviewer_ratings')),
                TextColumn::make('peer_rating')->label('Peer (360)')->placeholder('—')->state(AppraisalResource::rating('peer_rating', 'reviewer_ratings'))->visible($show360),
                TextColumn::make('final_rating')->label('Final')->placeholder('—')->weight('bold')->state(AppraisalResource::rating('final_rating')),
                TextColumn::make('final_label')->label('Label')->placeholder('—')->state(AppraisalResource::rating('final_label')),
                TextColumn::make('templateVersion.version')->label('Template')->prefix('v')->placeholder('—')->toggleable(),
                TextColumn::make('locked_at')->label('Locked')->date()->placeholder('—')->toggleable(),
                TextColumn::make('promotion_recommended')->label('Promotion')->formatStateUsing(fn ($state) => $state ? 'Recommended' : '—')->placeholder('—')->state(AppraisalResource::rating('promotion_recommended')),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('open')->label('Open')->url(fn (Appraisal $record) => AppraisalResource::getUrl('view', ['record' => $record])),
                Action::make('feedback360')->label('360 feedback')->icon('heroicon-m-users')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->visible(fn (Appraisal $record) => $show360 && $record->isPublished() && AppraisalVisibility::ratingsVisible($record, auth()->user()))
                    ->schema([
                        TextEntry::make('reviews360')->hiddenLabel()->listWithLineBreaks()->placeholder('No 360 feedback was submitted.')
                            ->state(fn (Appraisal $record) => self::feedback360($record)),
                    ]),
            ]);
    }

    /** @return list<string> one line per submitted 360 review: relation, author (masked when anonymous), rating, comments */
    public static function feedback360(Appraisal $appraisal): array
    {
        $user = auth()->user();
        $hr = $user?->can('performance.view') ?? false;

        return AppraisalReview::query()->with('reviewer.person')->where('appraisal_id', $appraisal->id)
            ->whereIn('type', ['peer', 'upward', 'skip_level', 'external'])->where('status', 'submitted')->orderBy('id')->get()
            ->map(fn (AppraisalReview $review) => config("peopleos.performance.review_types.{$review->type}", $review->type).' · '
                .($review->is_anonymous && ! $hr ? 'Anonymous' : ($review->reviewer?->person?->full_name ?? '—'))
                .($review->overall_rating !== null ? ' · rating '.rtrim(rtrim((string) $review->overall_rating, '0'), '.') : '')
                .collect([$review->strengths, $review->improvements, $review->comments])->filter()->map(fn ($t) => ' — '.$t)->implode(''))
            ->all();
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
            $plan = ImprovementPlan::query()->where('employee_id', $employee->id)->whereIn('status', ImprovementPlan::UNDECIDED)->first();
            $parts[] = 'Improvement plan: '.($plan ? config("peopleos.performance.pip_statuses.{$plan->status}").' until '.$plan->end_date->toDateString() : 'none open');
        }

        return implode(' · ', $parts);
    }
}
