<?php

namespace App\Filament\Resources\RewardPoints\Pages;

use App\Domain\Experience\Services\RoleLens;
use App\Domain\Recognition\Services\RewardPoints;
use App\Filament\Resources\RewardPoints\RewardPointEntryResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\RewardPointActions;

class ListRewardPointEntries extends PeopleListRecords
{
    protected static string $resource = RewardPointEntryResource::class;

    /** The list's own sentence, then the balances: the viewer's own points, and the budget for HR. */
    public function getSubheading(): ?string
    {
        $points = app(RewardPoints::class);
        $parts = [];
        if (($me = app(RoleLens::class)->employee(auth()->user())) !== null) {
            $parts[] = 'Points to give: '.$points->givingBalance($me);
            $parts[] = 'Points received: '.$points->earnedBalance($me);
        }
        if (auth()->user()->can('recognition.points')) {
            $parts[] = 'Points budget: '.$points->budgetAvailable();
        }
        if (! $points->enabled()) {
            $parts[] = 'Reward points are off (Setup → Engage)';
        }

        return trim($this->moduleContext()['sentence'].' '.implode(' · ', $parts));
    }

    protected function getHeaderActions(): array
    {
        return [RewardPointActions::give(), RewardPointActions::redeemOwn(), RewardPointActions::deposit(), RewardPointActions::redeemFor(), RewardPointActions::adjust()];
    }
}
