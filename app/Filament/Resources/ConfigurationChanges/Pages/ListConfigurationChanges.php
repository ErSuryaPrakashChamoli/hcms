<?php

namespace App\Filament\Resources\ConfigurationChanges\Pages;

use App\Domain\Configuration\Enums\ChangeStatus;
use App\Filament\Resources\ConfigurationChanges\ConfigurationChangeResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListConfigurationChanges extends PeopleListRecords
{
    protected static string $resource = ConfigurationChangeResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending approval')->modifyQueryUsing(fn (Builder $query) => $query->where('status', ChangeStatus::PendingApproval)),
            'scheduled' => Tab::make('Scheduled')->modifyQueryUsing(fn (Builder $query) => $query->where('status', ChangeStatus::Scheduled)),
            'published' => Tab::make('Recently published')->modifyQueryUsing(fn (Builder $query) => $query->where('status', ChangeStatus::Published)->where('published_at', '>=', now()->subDays(30))),
            'rejected' => Tab::make('Rejected')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [ChangeStatus::Rejected, ChangeStatus::Discarded])),
            'history' => Tab::make('History'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
