<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;

class ListEmployees extends PeopleListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The employees the list shows (filters and search applied), in the import template format.
            Action::make('exportEmployees')
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => EmployeeImports::enabled() && auth()->user()->can('create', EmployeeImport::class))
                ->action(function () {
                    $csv = app(EmployeeImports::class)->export($this->getFilteredTableQuery(), auth()->user());

                    return response()->streamDownload(fn () => print ($csv), 'employees-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
                }),
            CreateAction::make()->label('Hire employee'),
        ];
    }
}
