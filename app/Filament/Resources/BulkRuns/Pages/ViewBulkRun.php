<?php

namespace App\Filament\Resources\BulkRuns\Pages;

use App\Domain\Experience\Models\BulkRun;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewBulkRun extends PeopleViewRecord
{
    protected static string $resource = BulkRunResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var BulkRun $run */
        $run = $this->getRecord();

        return 'Bulk run: '.$run->operation_label;
    }
}
