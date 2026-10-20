<?php

namespace App\Filament\Resources\Payslips\Pages;

use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListPayslips extends PeopleListRecords
{
    protected static string $resource = PayslipResource::class;
}
