<?php

namespace App\Filament\Resources\EstablishmentAssignments\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\EmployeeEstablishmentAssignment;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Filament\Resources\EstablishmentAssignments\EstablishmentAssignmentResource;
use App\Filament\Resources\StatutoryRegistrations\StatutoryRegistrationResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;

class ManageEstablishmentAssignments extends PeopleManageRecords
{
    protected static string $resource = EstablishmentAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('assign')->label('Assign establishment')->requiresConfirmation()
                ->visible(fn () => auth()->user()?->can('create', EmployeeEstablishmentAssignment::class))
                ->schema(EstablishmentAssignmentResource::createFields())
                ->action(fn (array $data) => StatutoryRegistrationResource::attempt(fn () => app(EstablishmentAssignments::class)->assign(
                    Employee::query()->findOrFail($data['employee_id']),
                    Establishment::query()->findOrFail($data['establishment_id']),
                    $data['effective_from'],
                    $data['reason'],
                    $data['source'],
                    auth()->user(),
                ), 'Establishment assigned')),
        ];
    }
}
