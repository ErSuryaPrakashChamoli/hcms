<?php

namespace App\Filament\Resources\LetterSignatories\Pages;

use App\Domain\Letters\Models\LetterSignatory;
use App\Filament\Resources\LetterSignatories\LetterSignatoryResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageLetterSignatories extends PeopleManageRecords
{
    protected static string $resource = LetterSignatoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => LetterSignatoryResource::save(new LetterSignatory, $data))];
    }
}
