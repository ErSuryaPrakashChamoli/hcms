<?php

namespace App\Filament\Resources\Appraisals\RelationManagers;

use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Reviews on an appraisal. Employees see submitted reviews about them (anonymous peers hidden by name); reviewers see their own. */
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
        $privileged = $user->can('performance.view') || $owner->manager_id === $me?->id;

        return $table
            ->modifyQueryUsing(function ($query) use ($privileged, $me, $owner) {
                $query->with('reviewer.person');
                if (! $privileged) {
                    // The employee sees only submitted reviews about them, plus anything they must fill in.
                    $query->where(fn ($q) => $q->where('reviewer_id', $me?->id)->orWhere(fn ($s) => $s->where('status', 'submitted')->where('appraisal_id', $owner->id)));
                }
            })
            ->columns([
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.performance.review_types.{$state}", $state)),
                TextColumn::make('reviewer.person.full_name')->label('Reviewer')->formatStateUsing(fn ($state, AppraisalReview $record) => $record->is_anonymous && ! $privileged && $record->reviewer_id !== $me?->id ? 'Anonymous' : $state)->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'submitted' ? 'success' : 'gray'),
                TextColumn::make('overall_rating')->label('Overall')->placeholder('—'),
                TextColumn::make('submitted_at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('detail')->label('Read')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->visible(fn (AppraisalReview $record) => $record->isSubmitted())
                    ->schema([
                        Section::make('Ratings')->schema([
                            TextEntry::make('ratings')->hiddenLabel()->state(function (AppraisalReview $record) {
                                $ratings = $record->ratings()->get();
                                $goals = Goal::query()->whereIn('id', $ratings->where('subject_type', 'goal')->pluck('subject_id'))->pluck('title', 'id');
                                $comps = Competency::query()->whereIn('id', $ratings->where('subject_type', 'competency')->pluck('subject_id'))->pluck('name', 'id');

                                return $ratings->map(fn ($r) => ($r->subject_type === 'goal' ? $goals[$r->subject_id] ?? 'Goal' : $comps[$r->subject_id] ?? 'Competency').': '.rtrim(rtrim((string) $r->rating, '0'), '.').($r->comment ? " — {$r->comment}" : ''))->all();
                            })->listWithLineBreaks()->placeholder('None'),
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
