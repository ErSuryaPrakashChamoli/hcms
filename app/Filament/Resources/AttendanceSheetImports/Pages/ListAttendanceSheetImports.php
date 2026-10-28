<?php

namespace App\Filament\Resources\AttendanceSheetImports\Pages;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Employment\Enums\ImportMode;
use App\Filament\Resources\AttendanceSheetImports\AttendanceSheetImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;

class ListAttendanceSheetImports extends PeopleListRecords
{
    protected static string $resource = AttendanceSheetImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportActions::formatAction(AttendanceSheetImports::TYPE),
            Action::make('downloadTemplate')
                ->label('Download template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->modalHeading('Download a monthly attendance sheet')
                ->modalDescription('The sheet lists everyone employed in the month, with a column for each day. Fill in a day code per day and upload it.')
                ->modalSubmitActionLabel('Download')
                ->schema([ImportActions::monthField()])
                ->action(fn (array $data) => response()->streamDownload(fn () => print (app(AttendanceSheetImports::class)->template($data['month'])), "attendance-{$data['month']}.csv", ['Content-Type' => 'text/csv'])),
            CreateAction::make()
                ->label('Upload attendance CSV')
                ->modalHeading('Upload a month\'s attendance')
                ->modalDescription('For attendance not captured by a biometric device: one row per employee, one column per day of the month, a day code in each cell. Start from Download template. Nothing changes until the file is mapped, validated, approved and run.')
                ->schema([
                    ImportActions::monthField(),
                    ImportActions::modeField(AttendanceSheetImports::MODES),
                    ImportActions::blankDaysField(),
                    ImportActions::fileField(),
                    ImportActions::formatSection(AttendanceSheetImports::TYPE),
                ])
                ->using(fn (array $data) => app(AttendanceSheetImports::class)->register($data['file'], ImportActions::originalName($data), auth()->user(), $data['month'], ImportMode::from($data['mode']), $data['blank_days']))
                ->successRedirectUrl(fn ($record) => AttendanceSheetImportResource::getUrl('view', ['record' => $record])),
        ];
    }
}
