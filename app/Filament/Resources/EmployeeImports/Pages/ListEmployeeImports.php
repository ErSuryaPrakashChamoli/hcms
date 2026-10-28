<?php

namespace App\Filament\Resources\EmployeeImports\Pages;

use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;

class ListEmployeeImports extends PeopleListRecords
{
    protected static string $resource = EmployeeImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportActions::formatAction('employees'),
            Action::make('downloadTemplate')
                ->label('Download template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => auth()->user()->can('create', EmployeeImport::class))
                ->action(function () {
                    $csv = app(EmployeeImports::class)->template();

                    return response()->streamDownload(fn () => print ($csv), 'employee-import-template.csv', ['Content-Type' => 'text/csv']);
                }),
            CreateAction::make()
                ->label('Upload CSV')
                ->modalHeading('Upload an employee file')
                ->modalDescription('CSV with a header row; start from Download template and replace the example row with your people. To update people already on file, Employees › Export gives them in this format. Nothing is written to employees until the file is mapped, validated, approved and run.')
                ->schema([
                    ImportActions::modeField(EmployeeImports::MODES),
                    ImportActions::fileField(),
                    ImportActions::formatSection('employees'),
                ])
                ->using(fn (array $data) => app(EmployeeImports::class)->register($data['file'], ImportActions::originalName($data), auth()->user(), mode: ImportMode::from($data['mode'])))
                ->successRedirectUrl(fn ($record) => EmployeeImportResource::getUrl('view', ['record' => $record])),
        ];
    }
}
