<?php

namespace App\Filament\Resources\AlumniProfiles\Pages;

use App\Filament\Resources\AlumniProfiles\AlumniProfileResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAlumniProfile extends ViewRecord
{
    protected static string $resource = AlumniProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->visible(fn () => auth()->user()->can('alumni.manage'))];
    }
}
