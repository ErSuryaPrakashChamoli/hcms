<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Pages\RolesByPerson;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;

class ListRoles extends PeopleListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // HROne setup parity (Role → employee view): the same roles, listed by person.
            Action::make('byPerson')->label('By person')->icon('heroicon-o-users')->color('gray')
                ->visible(fn () => RolesByPerson::canAccess())->url(fn () => RolesByPerson::getUrl()),
            CreateAction::make(),
        ];
    }
}
