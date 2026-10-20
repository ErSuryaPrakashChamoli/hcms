<?php

namespace App\Filament\Resources\PunchImports\Pages;

use App\Domain\Attendance\Imports\PunchImports;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Filament\Resources\PunchImports\PunchImportResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;

class ListPunchImports extends PeopleListRecords
{
    protected static string $resource = PunchImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Upload punches CSV')
                ->modalHeading('Upload a raw punch file')
                ->modalDescription('CSV with a header row (employee code, timestamp, optional timezone/direction/external id/device). Rows become raw punches only after mapping, validation and approval; attendance is recalculated from them.')
                ->schema([
                    FileUpload::make('file')->label('CSV file')->disk(EmployeeImports::disk())->directory(fn () => EmployeeImports::directory())->visibility('private')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])->maxSize((int) config('peopleos.documents.max_kb', 10240))->storeFileNamesIn('original_name')->required(),
                ])
                ->using(function (array $data) {
                    $name = is_array($data['original_name'] ?? null) ? (reset($data['original_name']) ?: basename($data['file'])) : ($data['original_name'] ?? basename($data['file']));

                    return app(PunchImports::class)->register($data['file'], (string) $name, auth()->user());
                })
                ->successRedirectUrl(fn ($record) => PunchImportResource::getUrl('view', ['record' => $record])),
        ];
    }
}
