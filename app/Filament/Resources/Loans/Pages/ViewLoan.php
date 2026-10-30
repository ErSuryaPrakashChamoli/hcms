<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewLoan extends PeopleViewRecord
{
    protected static string $resource = LoanResource::class;
}
