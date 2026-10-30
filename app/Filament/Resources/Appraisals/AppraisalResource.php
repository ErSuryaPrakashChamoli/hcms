<?php

namespace App\Filament\Resources\Appraisals;

use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Appraisals\Pages\ListAppraisals;
use App\Filament\Resources\Appraisals\Pages\ViewAppraisal;
use App\Filament\Resources\Appraisals\RelationManagers\ReviewsRelationManager;
use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PerformanceActions;
use App\Filament\Support\PerformanceRegisters;
use BackedEnum;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Appraisals (§34): HR sees all; employees their own; managers their reports; reviewers what they must review.
 * HROne setup parity HP-8b: ratings, scores, labels and the summary show to the employee only once the rating is
 * published (AppraisalVisibility) and never to other reviewers; the self rating follows "Show self review to manager".
 */
class AppraisalResource extends Resource
{
    protected static ?string $model = Appraisal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Appraisals';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['cycle', 'employee.person', 'manager.person', 'reviews']);
        $user = auth()->user();

        if ($user->can('performance.view')) {
            return $query;
        }

        $me = EmployeeOwnedPolicy::employeeOf($user);
        if ($me === null) {
            return $query->whereRaw('1 = 0');
        }
        $reports = $user->can('performance.team') ? app(PerformanceRelationships::class)->reportIds($me) : collect();

        return $query->where(fn (Builder $q) => $q->where('employee_id', $me->id)->orWhereIn('employee_id', $reports)->orWhereHas('reviews', fn (Builder $r) => $r->where('reviewer_id', $me->id)));
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'pending' => 'gray', 'in_review' => 'info', 'calibration' => 'warning', 'finalized' => 'success', 'acknowledged' => 'primary', default => 'gray'
        };
    }

    /** @return array<int, TextColumn> */
    public static function columns(): array
    {
        return [
            TextColumn::make('employee.employee_code')->label('Code')->searchable(),
            TextColumn::make('employee.person.full_name')->label('Employee')->searchable(['first_name', 'last_name']),
            TextColumn::make('manager.person.full_name')->label('Manager')->placeholder('—'),
            TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.performance.appraisal_statuses.{$state}", $state)),
            TextColumn::make('reviews')->label('Reviews')->state(fn (Appraisal $record) => $record->reviews->map(fn ($r) => ucfirst($r->type).($r->isSubmitted() ? ' ✓' : ' …'))->implode(', ')),
            TextColumn::make('self_rating')->label('Self')->placeholder('—')->state(fn (Appraisal $record) => AppraisalVisibility::selfReviewVisible($record, auth()->user()) ? $record->self_rating : null),
            TextColumn::make('manager_rating')->label('Manager')->placeholder('—')->state(self::rating('manager_rating', 'reviewer_ratings')),
            TextColumn::make('computed_rating')->label('Computed')->placeholder('—')->state(self::rating('computed_rating', 'computed_rating')),
            TextColumn::make('calibrated_rating')->label('Calibrated')->placeholder('—')->state(self::rating('calibrated_rating', 'reviewer_ratings')),
            TextColumn::make('final_rating')->label('Final')->placeholder('—')->weight('bold')->state(self::rating('final_rating')),
        ];
    }

    /**
     * A rating column / entry shown only to whom the appraisal's ratings are visible (HP-8b); with $part, also hidden from
     * the employee when their goal sheet policy hides that part of the scorecard (HP-8d).
     */
    public static function rating(string $field, ?string $part = null): Closure
    {
        return fn (Appraisal $record) => ($part === null ? AppraisalVisibility::ratingsVisible($record, auth()->user()) : AppraisalVisibility::partVisible($record, auth()->user(), $part)) ? $record->getAttribute($field) : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        $ratings = fn (Appraisal $record) => AppraisalVisibility::ratingsVisible($record, auth()->user());
        // HP-8d: parts of the scorecard the employee's goal sheet policy hides from them.
        $part = fn (string $part) => fn (Appraisal $record) => AppraisalVisibility::partVisible($record, auth()->user(), $part);

        return $schema->components([
            Section::make('Appraisal')->columns(4)->schema([
                TextEntry::make('cycle.name')->label('Cycle')->url(fn (Appraisal $record) => PerformanceCycleResource::getUrl('edit', ['record' => $record->cycle])),
                TextEntry::make('employee.person.full_name')->label('Employee'),
                TextEntry::make('manager.person.full_name')->label('Manager')->placeholder('—'),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.performance.appraisal_statuses.{$state}", $state)),
                TextEntry::make('cycle.current_stage')->label('Cycle stage')->placeholder('—')->formatStateUsing(fn (?string $state) => config("peopleos.performance.stages.{$state}", $state)),
                TextEntry::make('goal_score')->label('Goal score')->suffix('%')->placeholder('—')->visible($part('goal_score')),
                TextEntry::make('competency_score')->label(PerformanceLabels::get('competency').' score')->suffix('%')->placeholder('—')->visible($part('competency_score')),
                TextEntry::make('computed_rating')->label('Computed rating')->placeholder('—')->visible($part('computed_rating')),
                TextEntry::make('self_rating')->label('Self rating')->placeholder('—')->visible(fn (Appraisal $record) => AppraisalVisibility::selfReviewVisible($record, auth()->user())),
                TextEntry::make('manager_rating')->label('Manager rating')->placeholder('—')->visible($part('reviewer_ratings')),
                TextEntry::make('peer_rating')->label('Peer rating')->placeholder('—')->visible($part('reviewer_ratings')),
                TextEntry::make('calibrated_rating')->label('Calibrated')->placeholder('—')->visible($part('reviewer_ratings')),
                TextEntry::make('final_rating')->label('Final rating')->placeholder('—')->weight('bold')->visible($ratings),
                TextEntry::make('final_label')->label('Final label')->placeholder('—')->badge()->color('success')->visible($ratings),
                TextEntry::make('promotion_recommended')->label('Promotion')->formatStateUsing(fn ($state) => $state ? 'Recommended' : '—')->visible($ratings),
                TextEntry::make('pip_recommended')->label('Improvement plan')->formatStateUsing(fn ($state) => $state ? 'Recommended' : '—')->visible($ratings),
                TextEntry::make('published_at')->label('Rating published')->dateTime()->placeholder('Not yet')
                    ->visible(fn (Appraisal $record) => $record->isFinal() && AppraisalVisibility::isPrivileged($record, auth()->user())),
                // HP-8i: the final comments follow "Final comments is visible to" of the performance policy (all = as before).
                TextEntry::make('manager_summary')->label('Summary')->placeholder('—')->columnSpan(2)->visible(fn (Appraisal $record) => ($record->isFinal() && AppraisalVisibility::finalCommentsVisible($record, auth()->user())) || auth()->user()->can('performance.calibrate')),
                // HP-8i: reviewers' recommendations (performance policy), for HR and the employee's managers.
                TextEntry::make('recommendations')->label('Recommendations')->columnSpan(2)->listWithLineBreaks()
                    ->state(fn (Appraisal $record) => self::recommendationLines($record))
                    ->visible(fn (Appraisal $record) => ! empty($record->recommendations) && AppraisalVisibility::isPrivileged($record, auth()->user())),
                TextEntry::make('calibration_note')->placeholder('—')->columnSpan(2)->visible(fn () => auth()->user()->can('performance.calibrate')),
                TextEntry::make('employee_comment')->label('Employee comment')->placeholder('—')->columnSpan(2),
                // HP-8e: the goal templates' feedback form asked of the manager (statuses only, never the answers).
                TextEntry::make('goal_sheet_feedback')->label('Goal sheet feedback')->columnSpan(2)
                    ->state(fn (Appraisal $record) => ($review = $record->review('manager')) ? app(FeedbackRequests::class)->summary($review) : null)
                    ->visible(fn (Appraisal $record) => AppraisalVisibility::isPrivileged($record, auth()->user()) && ($review = $record->review('manager')) !== null && app(FeedbackRequests::class)->summary($review) !== null),
            ]),
        ]);
    }

    /** @return list<string> HP-8i: the recommendations as lines (training needs, promotion, increment, variable pay) */
    public static function recommendationLines(Appraisal $record): array
    {
        $r = (array) ($record->recommendations ?? []);
        $value = fn (?array $v) => $v === null ? null : rtrim(rtrim(number_format((float) ($v['value'] ?? 0), 2, '.', ''), '0'), '.').(($v['type'] ?? 'percentage') === 'amount' ? ' (amount)' : '%');
        $training = $r['training'] ?? null;
        $names = is_array($training) ? Competency::query()->whereIn('id', (array) ($training['competency_ids'] ?? []) ?: [0])->pluck('name')->implode(', ') : '';

        return array_values(array_filter([
            is_array($training) ? 'Training needs: '.trim($names.(filled($training['note'] ?? null) ? ' — '.$training['note'] : ''), ' —') : null,
            array_key_exists('promotion', $r) ? 'Promotion: '.($r['promotion'] ? 'recommended' : 'not recommended') : null,
            isset($r['increment']) ? 'Salary increment: '.$value($r['increment']) : null,
            isset($r['variable']) ? 'Variable pay: '.$value($r['variable']) : null,
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('cycle.name')->label('Cycle')->sortable(), ...self::columns()])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('performance_cycle_id')->label('Cycle')->relationship('cycle', 'name'),
                SelectFilter::make('status')->options(config('peopleos.performance.appraisal_statuses')),
                // HROne parity GO-1 (§27.3.1 #5): created (launch) date, and the WO-2 employee filters — for performance HR only,
                // so the employee's and the manager's lists are as before.
                ...PerformanceRegisters::hrOnly([
                    EmployeeFilters::dateRange('created_dates', 'Created', 'created_at'),
                    ...EmployeeFilters::forRegister('employee'),
                ]),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([
                // HP-8b: publish the selected finalized ratings (manual publishing).
                BulkAction::make('publishRatings')->label('Publish ratings')->icon(Heroicon::OutlinedMegaphone)->color('success')
                    ->visible(fn () => auth()->user()->can('performance.calibrate'))
                    ->requiresConfirmation()->modalDescription('Finalized ratings among the selected appraisals are shown to their employees, who are told.')
                    ->action(fn (Collection $records) => PerformanceActions::run(
                        fn () => $records->groupBy('performance_cycle_id')->sum(fn (Collection $group) => app(Appraisals::class)->publish(PerformanceCycle::query()->findOrFail($group->first()->performance_cycle_id), $group->pluck('id')->all(), auth()->user())),
                        fn (int $n) => "{$n} rating(s) published",
                    )),
            ]);
    }

    public static function getRelations(): array
    {
        return [ReviewsRelationManager::class, RelationManagers\PeriodReviewsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppraisals::route('/'),
            'view' => ViewAppraisal::route('/{record}'),
        ];
    }
}
