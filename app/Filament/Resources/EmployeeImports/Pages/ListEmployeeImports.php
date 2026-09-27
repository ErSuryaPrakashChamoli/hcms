<?php

namespace App\Filament\Resources\EmployeeImports\Pages;

use App\Domain\Employment\Imports\EmployeeImports;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeImports extends ListRecords
{
    protected static string $resource = EmployeeImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Upload CSV')
                ->modalHeading('Upload an employee file')
                ->modalDescription('CSV with a header row. Nothing is written to employees until the file is mapped, validated, approved and run.')
                ->schema([
                    FileUpload::make('file')
                        ->label('CSV file')
                        ->disk(EmployeeImports::disk())
                        ->directory(fn () => EmployeeImports::directory())
                        ->visibility('private')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->maxSize((int) config('peopleos.documents.max_kb', 10240))
                        ->storeFileNamesIn('original_name')
                        ->required(),
                ])
                ->using(function (array $data) {
                    $name = is_array($data['original_name'] ?? null) ? (reset($data['original_name']) ?: basename($data['file'])) : ($data['original_name'] ?? basename($data['file']));

                    return app(EmployeeImports::class)->register($data['file'], (string) $name, auth()->user());
                })
                ->successRedirectUrl(fn ($record) => EmployeeImportResource::getUrl('view', ['record' => $record])),
        ];
    }
}
