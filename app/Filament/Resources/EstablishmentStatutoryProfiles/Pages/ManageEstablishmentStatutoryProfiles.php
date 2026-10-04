<?php

namespace App\Filament\Resources\EstablishmentStatutoryProfiles\Pages;

use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Filament\Resources\EstablishmentStatutoryProfiles\EstablishmentStatutoryProfileResource;
use App\Filament\Resources\StatutoryRegistrations\StatutoryRegistrationResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;

class ManageEstablishmentStatutoryProfiles extends PeopleManageRecords
{
    protected static string $resource = EstablishmentStatutoryProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('New profile')
                ->visible(fn () => auth()->user()?->can('create', EstablishmentStatutoryProfile::class))
                ->schema(EstablishmentStatutoryProfileResource::createFields())
                ->action(function (array $data) {
                    $reason = $data['reason'];
                    $data['settings'] = $data['statute'] === 'EPF' ? ['restrict_to_ceiling' => (bool) ($data['settings']['restrict_to_ceiling'] ?? true)] : null;
                    StatutoryRegistrationResource::attempt(function () use ($data, $reason) {
                        $profile = new EstablishmentStatutoryProfile([...$data, 'created_by' => auth()->id()]);
                        $profile->withAuditReason($reason)->save();
                    }, 'Profile recorded');
                }),
        ];
    }
}
