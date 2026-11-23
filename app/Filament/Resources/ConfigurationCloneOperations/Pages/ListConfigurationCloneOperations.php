<?php

namespace App\Filament\Resources\ConfigurationCloneOperations\Pages;

use App\Domain\Configuration\Cloning\CloneMode;
use App\Domain\Configuration\Cloning\CloneRegistry;
use App\Domain\Configuration\Models\ConfigurationCloneOperation;
use App\Filament\Resources\ConfigurationCloneOperations\ConfigurationCloneOperationResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Support\Csv\SpreadsheetCell;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListConfigurationCloneOperations extends PeopleListRecords
{
    protected static string $resource = ConfigurationCloneOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Export CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn () => $this->export()),
        ];
    }

    /** The clones the list shows now (its filters and search), as CSV. */
    private function export(): StreamedResponse
    {
        $rows = $this->getFilteredSortedTableQuery()->limit(5000)->get();
        $registry = app(CloneRegistry::class);

        return response()->streamDownload(function () use ($rows, $registry) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'By', 'What', 'Source', 'Companies', 'Mode', 'Status', 'Created', 'Why it stopped', 'Clone reference']);
            foreach ($rows as $row) {
                /** @var ConfigurationCloneOperation $row */
                fputcsv($out, SpreadsheetCell::row([
                    $row->started_at?->format('Y-m-d H:i'), $row->initiator?->name, $registry->label($row->adapter), $row->source_label,
                    implode('; ', $row->destinationNames()), CloneMode::tryFrom($row->mode)?->label() ?? $row->mode, $row->statusLabel(),
                    count((array) ($row->result['created'] ?? [])), $row->error, $row->ulid,
                ]));
            }
            fclose($out);
        }, 'clone-history-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
