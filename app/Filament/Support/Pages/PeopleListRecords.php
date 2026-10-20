<?php

namespace App\Filament\Support\Pages;

use App\Filament\Support\Pages\Concerns\InteractsWithModuleContext;
use Filament\Resources\Pages\ListRecords;

/** UX.15 closure: every resource list is a PeopleOS list (context, lens, search, results). */
abstract class PeopleListRecords extends ListRecords
{
    use InteractsWithModuleContext;
}
