<?php

namespace App\Filament\Resources\Policies\Pages;

use App\Filament\Pages\PolicyWizard;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class ListPolicies extends PeopleListRecords
{
    protected static string $resource = PolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('New policy')->icon(Heroicon::OutlinedPlus)
                ->url(PolicyWizard::getUrl())
                ->visible(fn () => auth()->user()->can('policy.create')),
        ];
    }
}
