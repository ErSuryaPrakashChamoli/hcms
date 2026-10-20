<?php

namespace App\Filament\Support\Pages;

use App\Filament\Support\Pages\Concerns\PresentsRecordContext;
use App\Filament\Support\PeopleOsText;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * UX.15 closure: creating a record as a flow (context → information → review → confirm). The page says what
 * creating does, the review lists what has been filled in, and the button names what is being created.
 */
abstract class PeopleCreateRecord extends CreateRecord
{
    use PresentsRecordContext;

    public function getTitle(): string|Htmlable
    {
        return static::$title ?? 'New '.PeopleOsText::inline((string) static::getResource()::getTitleCaseModelLabel());
    }

    protected function recordPageNote(): string
    {
        return 'Fill in what you know; required fields are marked. You review the details before creating, and the change is recorded in the audit trail.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Create '.PeopleOsText::inline((string) static::getResource()::getTitleCaseModelLabel()));
    }
}
