<?php

namespace App\Filament\Resources\PunchImports\Pages;

use App\Domain\Attendance\Imports\PunchImports;
use App\Filament\Resources\PunchImports\PunchImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;

class ListPunchImports extends PeopleListRecords
{
    protected static string $resource = PunchImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportActions::formatAction(PunchImports::TYPE),
            Action::make('downloadTemplate')
                ->label('Download template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => response()->streamDownload(fn () => print (app(PunchImports::class)->template()), 'punch-import-template.csv', ['Content-Type' => 'text/csv'])),
            CreateAction::make()
                ->label('Upload punches CSV')
                ->modalHeading('Upload a raw punch file')
                ->modalDescription('CSV with a header row (employee code, timestamp, optional timezone/direction/external id/device). Rows become raw punches only after mapping, validation and approval; attendance is recalculated from them.')
                ->schema([ImportActions::fileField(), ImportActions::formatSection(PunchImports::TYPE)])
                ->using(fn (array $data) => app(PunchImports::class)->register($data['file'], ImportActions::originalName($data), auth()->user()))
                ->successRedirectUrl(fn ($record) => PunchImportResource::getUrl('view', ['record' => $record])),
        ];
    }
}
