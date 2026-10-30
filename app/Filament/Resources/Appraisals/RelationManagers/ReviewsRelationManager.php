<?php

namespace App\Filament\Resources\Appraisals\RelationManagers;

use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\KeyResult;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Filament\Support\PerformanceRegisters;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Reviews on an appraisal. HR and the employee's managers see them all (the self review only while the cycle shows it
 * to managers); reviewers see their own. HROne setup parity HP-8b: the employee sees their own self review, and the
 * submitted reviews by others (anonymous peers hidden by name) only once the rating is published. HP-8i: the employee's
 * performance policy may narrow who sees the ratings and comments of each level (AppraisalVisibility::reviewVisible); a
 * review hidden from the viewer is listed with its status only.
 */
class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    protected static ?string $title = 'Reviews';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $me = EmployeeOwnedPolicy::employeeOf($user);
        $owner = $this->getOwnerRecord();
        $privileged = AppraisalVisibility::isPrivileged($owner, $user);
        $othersVisible = AppraisalVisibility::isOwn($owner, $user) && $owner->isPublished();
        $selfVisible = AppraisalVisibility::selfReviewVisible($owner, $user);
        // HP-8d: what the employee's goal sheet policy hides from them in reviews written by others.
        $shows = fn (AppraisalReview $review, string $part) => AppraisalVisibility::reviewVisible($owner, $review, $user)
            && ($privileged || (int) $review->reviewer_id === (int) $me?->id || AppraisalVisibility::partVisible($owner, $user, $part));

        return $table
            ->modifyQueryUsing(function ($query) use ($privileged, $me, $othersVisible, $selfVisible) {
                $query->with(['reviewer.person', 'appraisal.cycle']);
                if (! $privileged) {
                    // What they must fill in (or wrote); the employee, the submitted reviews about them once published.
                    $query->where(fn ($q) => $q->where('reviewer_id', $me?->id ?? 0)->when($othersVisible, fn ($o) => $o->orWhere('status', 'submitted')));
                }
                if (! $selfVisible) {
                    $query->where('type', '!=', 'self');
                }
            })
            ->columns([
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.performance.review_types.{$state}", $state)),
                TextColumn::make('reviewer.person.full_name')->label('Reviewer')->formatStateUsing(fn ($state, AppraisalReview $record) => $record->is_anonymous && ! $privileged && $record->reviewer_id !== $me?->id ? 'Anonymous' : $state)->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'submitted' ? 'success' : 'gray'),
                TextColumn::make('overall_rating')->label('Overall')->placeholder('—')->state(fn (AppraisalReview $record) => $shows($record, 'reviewer_ratings') ? $record->overall_rating : null),
                TextColumn::make('submitted_at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                // HROne parity GO-1: HR reminds a pending reviewer, and (owner decision PER-1 / D-GO1, off by default) reopens a
                // submitted review; neither is offered to anyone else.
                PerformanceRegisters::remindReviewerAction(),
                PerformanceRegisters::reopenReviewAction(),
                Action::make('detail')->label('Read')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->visible(fn (AppraisalReview $record) => $record->isSubmitted() && AppraisalVisibility::reviewVisible($owner, $record, $user))
                    ->schema([
                        Section::make('Ratings')->schema([
                            TextEntry::make('ratings')->hiddenLabel()->state(function (AppraisalReview $record) use ($shows) {
                                // HP-8d: KPI ratings (review level "KPI") and achievement percentages; KRA / competency details
                                // the employee's goal sheet policy hides are left out for them.
                                $ratings = $record->ratings()->get()
                                    ->reject(fn ($r) => in_array($r->subject_type, ['goal', 'key_result'], true) && ! $shows($record, 'goal_details'))
                                    ->reject(fn ($r) => $r->subject_type === 'competency' && ! $shows($record, 'competency_details'));
                                $goals = Goal::query()->whereIn('id', $ratings->where('subject_type', 'goal')->pluck('subject_id'))->pluck('title', 'id');
                                $keyResults = KeyResult::query()->whereIn('id', $ratings->where('subject_type', 'key_result')->pluck('subject_id'))->pluck('title', 'id');
                                $comps = Competency::query()->whereIn('id', $ratings->where('subject_type', 'competency')->pluck('subject_id'))->pluck('name', 'id');

                                return $ratings->map(fn ($r) => match ($r->subject_type) {
                                    'goal' => $goals[$r->subject_id] ?? 'Goal', 'key_result' => '↳ '.($keyResults[$r->subject_id] ?? 'KPI'), default => $comps[$r->subject_id] ?? 'Competency',
                                }.': '.rtrim(rtrim((string) $r->rating, '0'), '.').($r->achievement_pct !== null ? ' ('.rtrim(rtrim((string) $r->achievement_pct, '0'), '.').'%)' : '').($r->comment ? " — {$r->comment}" : ''))->values()->all();
                            })->listWithLineBreaks()->placeholder('None'),
                            TextEntry::make('evidence')->label('Evidence')->listWithLineBreaks()->placeholder('None')
                                ->state(fn (AppraisalReview $record) => array_map(fn ($path) => basename((string) $path), (array) ($record->evidence ?? [])))
                                ->visible(fn (AppraisalReview $record) => ! empty($record->evidence)),
                        ]),
                        Section::make('Comments')->columns(1)->schema([
                            TextEntry::make('strengths')->placeholder('—'),
                            TextEntry::make('improvements')->label('Areas to develop')->placeholder('—'),
                            TextEntry::make('comments')->placeholder('—'),
                        ]),
                    ]),
            ]);
    }
}
