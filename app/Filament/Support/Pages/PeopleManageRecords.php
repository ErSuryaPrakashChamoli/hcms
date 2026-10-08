<?php

namespace App\Filament\Support\Pages;

use App\Filament\Support\Pages\Concerns\InteractsWithModuleContext;
use Filament\Resources\Pages\ManageRecords;

/** UX.15 closure: a PeopleOS list whose records are created and changed in a side drawer. */
abstract class PeopleManageRecords extends ManageRecords
{
    use InteractsWithModuleContext;
}
