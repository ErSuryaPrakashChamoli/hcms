<?php

namespace App\Filament\Resources\Forms\Pages;

use App\Domain\Configuration\Services\Forms;
use App\Filament\Resources\Forms\FormResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateForm extends PeopleCreateRecord
{
    protected static string $resource = FormResource::class;

    protected function afterCreate(): void
    {
        app(Forms::class)->draft($this->getRecord());
    }
}
