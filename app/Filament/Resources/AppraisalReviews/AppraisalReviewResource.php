<?php

namespace App\Filament\Resources\AppraisalReviews;

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Filament\Resources\AppraisalReviews\Pages\ListAppraisalReviews;
use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Support\BulkRecordActions;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PerformanceRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * HROne parity GO-1 (HR Desk → Performance → Performance review requests, matrix 27 §27.3.2): every review request of
 * the organisation — one row per reviewer level of an appraisal (self, manager, approver, peers …) and its state — so
 * HR can see who still has to review, remind them and (owner decision PER-1 / D-GO1, off by default) reopen a submitted
 * review. Read-only: reviews are written only by their reviewers (Appraisals::submitReview).
 *
 * Who sees what: performance HR (performance.view / performance.calibrate), whose access scope narrows the appraisals —
 * the same people who see every review on an appraisal's Reviews tab. A review's rating column still goes through
 * AppraisalVisibility::reviewVisible (the performance policy's per-level rules), and an anonymous peer's name follows the
 * Reviews tab (shown to HR, who may already read it there). 360 feedback rounds keep their own register and anonymity.
 */
class AppraisalReviewResource extends Resource
{
    protected static ?string $model = AppraisalReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Review requests';

    protected static ?string $modelLabel = 'review request';

    protected static ?string $slug = 'review-requests';

    protected static ?int $navigationSort = 21;

    public static function canAccess(): bool
    {
        return AppraisalVisibility::isHr(auth()->user());
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Reviews of the appraisals the viewer may list (the appraisal's access scope applies inside the sub-query). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['appraisal.employee.person', 'appraisal.cycle', 'reviewer.person']);
        if (! static::canAccess()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('appraisal');
    }

    /** The day the review's stage closes (HROne "due"), from the cycle's stage dates. */
    public static function dueOn(AppraisalReview $record): ?string
    {
        $stage = match ($record->type) {
            'self' => 'self_review', 'manager' => 'manager_review', 'approver' => 'approver_review', default => 'peer_review'
        };
        $ends = collect($record->loadMissing('appraisal.cycle')->appraisal?->cycle?->stages ?? [])->firstWhere('key', $stage)['ends_on'] ?? null;

        return $ends ? Carbon::parse($ends)->toDateString() : null;
    }

    public static function table(Table $table): Table
    {
        $user = fn () => auth()->user();

        return $table
            ->columns([
                TextColumn::make('appraisal.employee.person.full_name')->label('Employee')
                    ->description(fn (AppraisalReview $record) => $record->appraisal?->employee?->employee_code)
                    ->searchable(query: EmployeeFilters::search('appraisal.employee')),
                TextColumn::make('appraisal.cycle.name')->label('Performance cycle'),
                TextColumn::make('type')->label('Review level')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => config("peopleos.performance.review_types.{$state}", $state)),
                TextColumn::make('reviewer.person.full_name')->label('Reviewer')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'submitted' ? 'success' : 'warning')
                    ->formatStateUsing(fn (string $state) => $state === 'submitted' ? 'Submitted' : 'Pending'),
                TextColumn::make('due_on')->label('Due')->placeholder('—')->state(fn (AppraisalReview $record) => $record->isSubmitted() ? null : self::dueOn($record)),
                TextColumn::make('created_at')->label('Created')->date()->sortable(),
                TextColumn::make('submitted_at')->label('Submitted')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('overall_rating')->label('Overall')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    // The performance policy's per-level visibility (HP-8i) still decides, as on the Reviews tab.
                    ->state(fn (AppraisalReview $record) => $record->appraisal !== null && AppraisalVisibility::reviewVisible($record->appraisal, $record, $user()) ? $record->overall_rating : null),
                TextColumn::make('appraisal.status')->label('Appraisal')->badge()->color(fn (?string $state) => AppraisalResource::statusColor((string) $state))
                    ->formatStateUsing(fn (?string $state) => config("peopleos.performance.appraisal_statuses.{$state}", $state))->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('performance_cycle_id')->label('Performance cycle')
                    ->options(fn () => PerformanceCycle::query()->orderByDesc('period_start')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereHas('appraisal', fn (Builder $a) => $a->where('performance_cycle_id', (int) $data['value'])) : $query),
                SelectFilter::make('type')->label('Review level')->multiple()->options(fn () => config('peopleos.performance.review_types')),
                SelectFilter::make('status')->multiple()->options(['pending' => 'Pending', 'submitted' => 'Submitted']),
                SelectFilter::make('appraisal_status')->label('Appraisal status')->multiple()->options(fn () => config('peopleos.performance.appraisal_statuses'))
                    ->query(fn (Builder $query, array $data) => ($data['values'] ?? []) !== [] ? $query->whereHas('appraisal', fn (Builder $a) => $a->whereIn('status', (array) $data['values'])) : $query),
                EmployeeFilters::dateRange('created_dates', 'Created', 'created_at'),
                EmployeeFilters::dateRange('submitted_dates', 'Submitted', 'submitted_at'),
                ...EmployeeFilters::forRegister('appraisal.employee'),
            ])
            ->recordActions([
                Action::make('openAppraisal')->label('Appraisal')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->url(fn (AppraisalReview $record) => $record->appraisal !== null && $user()->can('view', $record->appraisal) ? AppraisalResource::getUrl('view', ['record' => $record->appraisal]) : null)
                    ->visible(fn (AppraisalReview $record) => $record->appraisal !== null && $user()->can('view', $record->appraisal)),
                PerformanceRegisters::remindReviewerAction(),
                PerformanceRegisters::reopenReviewAction(),
            ])
            ->toolbarActions([BulkRecordActions::selected('review_requests', self::bulkOperations())]);
    }

    /** Remind reviewers always; reopen only while owner decision PER-1 / D-GO1 is switched on. */
    public static function bulkOperations(): array
    {
        return array_values(array_filter(['review_requests.remind', Appraisals::reopenEnabled() ? 'review_requests.reopen' : null]));
    }

    public static function getPages(): array
    {
        return ['index' => ListAppraisalReviews::route('/')];
    }
}
