<?php

namespace App\Filament\Support\Pages;

use App\Filament\Support\Pages\Concerns\ArrangesRecordActions;
use App\Filament\Support\Pages\Concerns\PresentsRecordContext;
use Filament\Resources\Pages\EditRecord;

/**
 * UX.15 closure: changing a record as a flow. The page says what state the record is in and what saving does;
 * "Your changes" shows Before → After for each field changed in this form before it is saved.
 */
abstract class PeopleEditRecord extends EditRecord
{
    use ArrangesRecordActions;
    use PresentsRecordContext;

    protected function recordPageNote(): string
    {
        return 'Your changes are listed for review before you save, and recorded in the audit trail.';
    }
}
