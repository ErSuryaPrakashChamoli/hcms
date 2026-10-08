<?php

namespace App\Filament\Resources\StatutoryRegistrations\Pages;

use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Services\StatutoryRegistrations;
use App\Filament\Resources\StatutoryRegistrations\StatutoryRegistrationResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;

class ManageStatutoryRegistrations extends PeopleManageRecords
{
    protected static string $resource = StatutoryRegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('register')->label('New registration')
                ->visible(fn () => auth()->user()?->can('create', StatutoryRegistration::class))
                ->schema(StatutoryRegistrationResource::registrationFields())
                ->action(function (array $data) {
                    $reason = $data['reason'];
                    unset($data['reason']);
                    StatutoryRegistrationResource::attempt(fn () => app(StatutoryRegistrations::class)->register($data, $reason), 'Registration recorded');
                }),
        ];
    }
}
