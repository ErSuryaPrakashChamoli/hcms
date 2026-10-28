<?php

namespace App\Filament\Resources\SalaryImports\Pages;

use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Employment\Enums\ImportMode;
use App\Filament\Resources\SalaryImports\SalaryImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;

class ListSalaryImports extends PeopleListRecords
{
    protected static string $resource = SalaryImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportActions::formatAction(SalaryImports::TYPE),
            Action::make('downloadTemplate')
                ->label('Download template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => response()->streamDownload(fn () => print (app(SalaryImports::class)->template()), 'salary-upload-template.csv', ['Content-Type' => 'text/csv'])),
            CreateAction::make()
                ->label('Upload salaries CSV')
                ->modalHeading('Upload a salary file')
                ->modalDescription('CSV with a header row: employee code, salary structure code and annual CTC, optionally effective date, currency, variable target, change type and fixed monthly component amounts. Start from Download template. Running the upload prepares a draft compensation cycle; pay changes only after that cycle is reviewed, approved and executed by other people.')
                ->schema([
                    ImportActions::modeField(SalaryImports::MODES),
                    ImportActions::fileField(),
                    ImportActions::formatSection(SalaryImports::TYPE),
                ])
                ->using(fn (array $data) => app(SalaryImports::class)->register($data['file'], ImportActions::originalName($data), auth()->user(), mode: ImportMode::from($data['mode'])))
                ->successRedirectUrl(fn ($record) => SalaryImportResource::getUrl('view', ['record' => $record])),
        ];
    }
}
