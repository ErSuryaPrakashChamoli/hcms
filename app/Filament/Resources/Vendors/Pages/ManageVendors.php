<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\Vendors\VendorResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;

/** HROne parity WO-15: the vendor master; "Add vendor" opens directly with ?action=create. */
class ManageVendors extends PeopleManageRecords
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add vendor')->using(fn (array $data, Action $action) => VendorResource::save($action, null, $data)),
        ];
    }
}
