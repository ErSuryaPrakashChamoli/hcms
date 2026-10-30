<?php

namespace App\Filament\Resources\MyTaxCertificates\Pages;

use App\Filament\Resources\MyTaxCertificates\MyTaxCertificateResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListMyTaxCertificates extends PeopleListRecords
{
    protected static string $resource = MyTaxCertificateResource::class;
}
