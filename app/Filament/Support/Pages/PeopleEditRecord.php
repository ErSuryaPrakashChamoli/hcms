<?php

namespace App\Filament\Support\Pages;

use App\Filament\Support\Pages\Concerns\ArrangesRecordActions;
use App\Filament\Support\Pages\Concerns\FollowsFormWizard;
use App\Filament\Support\Pages\Concerns\PresentsRecordContext;
use Filament\Resources\Pages\EditRecord;

/**
 * UX.15 closure: changing a record as a flow. The page says what state the record is in and what saving does;
 * "Your changes" shows Before → After for each field changed in this form before it is saved. The form is followed as
 * a wizard (FollowsFormWizard): any step can be opened from the step list, and Review lists the changes before saving.
 */
abstract class PeopleEditRecord extends EditRecord
{
    use ArrangesRecordActions;
    use FollowsFormWizard;
    use PresentsRecordContext;

    protected function recordPageNote(): string
    {
        return $this->isFormWizard()
            ? 'Open any step to change it. Your changes are listed for review before you save, and recorded in the audit trail.'
            : 'Your changes are listed for review before you save, and recorded in the audit trail.';
    }
}
