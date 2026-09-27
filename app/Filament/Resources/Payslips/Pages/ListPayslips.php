<?php

namespace App\Filament\Resources\Payslips\Pages;

use App\Filament\Resources\Payslips\PayslipResource;
use Filament\Resources\Pages\ListRecords;

class ListPayslips extends ListRecords
{
    protected static string $resource = PayslipResource::class;
}
