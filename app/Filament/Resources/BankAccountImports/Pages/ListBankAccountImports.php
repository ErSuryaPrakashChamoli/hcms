<?php

namespace App\Filament\Resources\BankAccountImports\Pages;

use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\BankAccountImports;
use App\Filament\Resources\BankAccountImports\BankAccountImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;

class ListBankAccountImports extends PeopleListRecords
{
    protected static string $resource = BankAccountImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportActions::formatAction(BankAccountImports::TYPE),
            Action::make('downloadTemplate')
                ->label('Download template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => response()->streamDownload(fn () => print (app(BankAccountImports::class)->template()), 'bank-details-template.csv', ['Content-Type' => 'text/csv'])),
            CreateAction::make()
                ->label('Upload bank details CSV')
                ->modalHeading('Upload a bank details file')
                ->modalDescription('CSV with a header row: employee code, account holder name, bank name and account number, optionally IFSC, branch and account type. Start from Download template. Nothing changes until the file is mapped, validated, approved and run.')
                ->schema([
                    ImportActions::modeField(BankAccountImports::MODES),
                    ImportActions::fileField(),
                    ImportActions::formatSection(BankAccountImports::TYPE),
                ])
                ->using(fn (array $data) => app(BankAccountImports::class)->register($data['file'], ImportActions::originalName($data), auth()->user(), mode: ImportMode::from($data['mode'])))
                ->successRedirectUrl(fn ($record) => BankAccountImportResource::getUrl('view', ['record' => $record])),
        ];
    }
}
