<?php

namespace App\Filament\Resources\PerformanceLookups\Pages;

use App\Domain\Performance\Services\PerformanceDefaults;
use App\Filament\Resources\PerformanceLookups\PerformanceLookupResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use App\Filament\Support\PerformanceActions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ManagePerformanceLookups extends PeopleManageRecords
{
    protected static string $resource = PerformanceLookupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('standard')->label('Add standard entries')->icon(Heroicon::OutlinedSparkles)->color('gray')
                ->visible(fn () => auth()->user()->can('performance.manage'))
                ->requiresConfirmation()->modalDescription('Adds the standard perspectives, initiative types, PIP reasons and units of measurement that are missing. Entries you already have are left as they are.')
                ->action(fn () => PerformanceActions::run(fn () => app(PerformanceDefaults::class)->seedMasters(), fn (int $added) => "{$added} standard entr".($added === 1 ? 'y' : 'ies').' added')),
        ];
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('All')];
        foreach (PerformanceLookupResource::kinds() as $kind => $label) {
            $tabs[$kind] = Tab::make($label)->modifyQueryUsing(fn (Builder $query) => $query->where('kind', $kind));
        }

        return $tabs;
    }
}
