<?php

namespace App\Filament\Resources\Appraisals\RelationManagers;

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Services\PerformancePolicyRules;
use App\Domain\Performance\Services\PeriodicReviews;
use App\Filament\Support\PerformanceActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity HP-8i: the periodic reviews of an appraisal (performance policy review cycle). The reviewer (the
 * reporting manager) rates an open review inside its window; ratings and comments show to those who see the appraisal's
 * reviews, and to the employee once submitted unless the policy keeps them from the employee (PeriodicReviews::visibleTo).
 */
class PeriodReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'periodReviews';

    protected static ?string $title = 'Periodic reviews';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (auth()->user()?->can('view', $ownerRecord) ?? false) && $ownerRecord instanceof Appraisal && $ownerRecord->periodReviews()->exists();
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $service = app(PeriodicReviews::class);
        $shown = fn (PerformancePeriodReview $record) => $service->visibleTo($user, $record->setRelation('appraisal', $this->getOwnerRecord()));

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('reviewer.person')->reorder('sequence'))
            ->columns([
                TextColumn::make('sequence')->label('#'),
                TextColumn::make('period')->label('Period')->state(fn (PerformancePeriodReview $record) => $record->label()),
                TextColumn::make('window')->label('Window')->state(fn (PerformancePeriodReview $record) => $record->window_opens_on->format('d M').' – '.$record->window_closes_on->format('d M Y')),
                TextColumn::make('reviewer.person.full_name')->label('Reviewer')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.performance.period_review_statuses.{$state}", $state))
                    ->color(fn (string $state) => match ($state) {
                        'submitted' => 'success', 'open' => 'info', 'missed' => 'danger', default => 'gray'
                    }),
                TextColumn::make('rating')->placeholder('—')->state(fn (PerformancePeriodReview $record) => $shown($record) ? $record->rating : null),
                TextColumn::make('comment')->placeholder('—')->limit(60)->state(fn (PerformancePeriodReview $record) => $shown($record) ? $record->comment : null),
            ])
            ->recordActions([
                Action::make('rate')->label('Rate')->icon('heroicon-m-pencil-square')->color('primary')
                    ->visible(fn (PerformancePeriodReview $record) => $record->status === 'open' && $service->mayRate($user, $record))
                    ->schema(function (PerformancePeriodReview $record) {
                        $appraisal = $this->getOwnerRecord()->loadMissing('cycle');
                        $scale = $appraisal->cycle->ratingScale();

                        return [
                            Select::make('rating')->required()->options(PerformanceActions::aboveMax($scale->options(), $scale->max(), app(PerformancePolicyRules::class)->maxRating($appraisal, $scale))),
                            Textarea::make('comment')->maxLength(2000),
                        ];
                    })
                    ->action(fn (PerformancePeriodReview $record, array $data) => PerformanceActions::run(fn () => $service->submit($record, (float) $data['rating'], $data['comment'] ?? null, auth()->user()), 'Periodic review submitted')),
            ]);
    }
}
