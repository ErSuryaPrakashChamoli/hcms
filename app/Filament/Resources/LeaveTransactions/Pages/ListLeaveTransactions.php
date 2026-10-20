<?php

namespace App\Filament\Resources\LeaveTransactions\Pages;

use App\Filament\Resources\LeaveTransactions\LeaveTransactionResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListLeaveTransactions extends PeopleListRecords
{
    protected static string $resource = LeaveTransactionResource::class;
}
