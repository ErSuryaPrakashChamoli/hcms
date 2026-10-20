<?php

namespace App\Filament\Support\Pages;

use App\Filament\Support\Pages\Concerns\ArrangesRecordActions;
use App\Filament\Support\Pages\Concerns\PresentsRecordContext;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** UX.15 closure: a record's detail with its state in one line and breadcrumbs that name the record. */
abstract class PeopleViewRecord extends ViewRecord
{
    use ArrangesRecordActions;
    use PresentsRecordContext;

    /** The record's own name ("TKT-2026-00001"), not "View TKT-2026-00001". */
    public function getTitle(): string|Htmlable
    {
        return static::$title ?? (static::getResource()::hasRecordTitle() ? $this->getRecordTitle() : parent::getTitle());
    }
}
