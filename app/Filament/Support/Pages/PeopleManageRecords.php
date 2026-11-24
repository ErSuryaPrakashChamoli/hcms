<?php

namespace App\Filament\Support\Pages;

use App\Filament\Support\Applicability\ApplicabilityUi;
use App\Filament\Support\Pages\Concerns\InteractsWithModuleContext;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Schema;

/** UX.15 closure: a PeopleOS list whose records are created and changed in a side drawer. */
abstract class PeopleManageRecords extends ManageRecords
{
    use InteractsWithModuleContext;

    public function form(Schema $schema): Schema
    {
        // Configuration applicability: the "Applies to" choice when a registered configuration record is created here.
        return ApplicabilityUi::extendForm(parent::form($schema), static::getResource()::getModel(), 'manage');
    }
}
