<?php

namespace App\Filament\Resources\SalaryImports\Pages;

use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Filament\Resources\SalaryImports\SalaryImportResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Support\Icons\Heroicon;

class ListSalaryImports extends PeopleListRecords
{
    protected static string $resource = SalaryImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
                    FileUpload::make('file')->label('CSV file')->disk(EmployeeImports::disk())->directory(fn () => EmployeeImports::directory())->visibility('private')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])->maxSize((int) config('peopleos.documents.max_kb', 10240))->storeFileNamesIn('original_name')->required(),
                ])
                ->using(function (array $data) {
                    $name = is_array($data['original_name'] ?? null) ? (reset($data['original_name']) ?: basename($data['file'])) : ($data['original_name'] ?? basename($data['file']));

                    return app(SalaryImports::class)->register($data['file'], (string) $name, auth()->user());
                })
                ->successRedirectUrl(fn ($record) => SalaryImportResource::getUrl('view', ['record' => $record])),
        ];
    }
}
