<?php

namespace App\Filament\Resources\ProductIdeas\Pages;

use App\Filament\Resources\ProductIdeas\ProductIdeaResource;
use App\Filament\Support\Pages\PeopleViewRecord;

/** HROne parity AC-3: one product idea — what was sent, the status and Markedge's response, the files. Read only. */
class ViewProductIdea extends PeopleViewRecord
{
    protected static string $resource = ProductIdeaResource::class;
}
