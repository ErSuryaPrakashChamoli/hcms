<?php

namespace App\Filament\Resources\FeedbackParticipants\Pages;

use App\Domain\Performance\Services\FeedbackNominations;
use App\Domain\Performance\Services\FeedbackRoundResults;
use App\Filament\Resources\FeedbackParticipants\FeedbackParticipantResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** HROne setup parity HP-8h: 360 feedback — about me, to nominate, to approve, and (HR) all in scope. */
class ListFeedbackParticipants extends PeopleListRecords
{
    protected static string $resource = FeedbackParticipantResource::class;

    public function getTabs(): array
    {
        $user = auth()->user();
        $me = FeedbackParticipantResource::me();
        $toNominate = app(FeedbackNominations::class)->toNominateFor($user)->pluck('id')->all();
        $tabs = [
            'mine' => Tab::make('About me')->modifyQueryUsing(fn (Builder $query) => $query->where('employee_id', $me?->id ?? 0)),
            'nominate' => Tab::make('To nominate')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('id', $toNominate ?: [0]))->badge(count($toNominate) ?: null),
            'approve' => Tab::make('To approve')->modifyQueryUsing(fn (Builder $query) => $query->where('nomination_status', 'submitted')->where('employee_id', '!=', $me?->id ?? 0)),
        ];
        if (FeedbackRoundResults::isHr($user)) {
            $tabs['all'] = Tab::make('All');
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'mine';
    }
}
