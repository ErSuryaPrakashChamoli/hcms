<?php

namespace App\Filament\Resources\AlumniProfiles\Pages;

use App\Filament\Resources\AlumniProfiles\AlumniProfileResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use Filament\Actions\EditAction;

class ViewAlumniProfile extends PeopleViewRecord
{
    protected static string $resource = AlumniProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->visible(fn () => auth()->user()->can('alumni.manage'))];
    }
}
