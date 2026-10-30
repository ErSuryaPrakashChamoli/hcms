<?php

namespace App\Filament\Resources\TimeOfficeImports\Pages;

use App\Domain\Employment\Imports\RowImportTypes;
use App\Filament\Resources\TimeOfficeImports\TimeOfficeImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ListTimeOfficeImports extends PeopleListRecords
{
    protected static string $resource = TimeOfficeImportResource::class;

    protected function getHeaderActions(): array
    {
        $resource = static::getResource();
        $types = fn () => collect(RowImportTypes::allowedTypes(auth()->user(), $resource::area()))->mapWithKeys(fn (string $t) => [$t => RowImportTypes::TYPES[$t][1]])->all();

        return [
            Action::make('templates')
                ->label('Download template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->schema([Select::make('type')->label('Upload')->options($types)->required()->native(false)])
                ->action(fn (array $data) => response()->streamDownload(fn () => print (ImportActions::service($data['type'])->template()), str_replace('_', '-', $data['type']).'-template.csv', ['Content-Type' => 'text/csv'])),
            CreateAction::make()
                ->label('Upload CSV')
                // HROne parity TX-1: a register's "Upload" link opens this dialog with its type chosen (when allowed).
                ->mountUsing(function (?Schema $schema, array $arguments) use ($types): void {
                    $schema?->fill();
                    $type = is_string($arguments['type'] ?? null) ? $arguments['type'] : null;
                    if ($schema !== null && $type !== null && array_key_exists($type, $types())) {
                        $schema->fill([...$schema->getRawState(), 'type' => $type]);
                    }
                })
                ->modalHeading($resource::uploadHeading())
                ->modalDescription('Choose what the file holds, then upload it. Start from Download template. Nothing changes until the file is mapped, validated, approved and run.')
                ->schema([
                    Select::make('type')->label('Upload')->options($types)->required()->live()->native(false),
                    Radio::make('mode')->label('What is this file for?')->required()->default('both')
                        ->options(fn (Get $get) => array_map(fn (array $m) => $m[0], ImportActions::modes((string) $get('type')) ?? []))
                        ->descriptions(fn (Get $get) => array_map(fn (array $m) => $m[1], ImportActions::modes((string) $get('type')) ?? []))
                        ->visible(fn (Get $get) => filled($get('type')) && ImportActions::modes((string) $get('type')) !== null),
                    ImportActions::fileField(),
                ])
                ->using(fn (array $data) => ImportActions::service($data['type'])->register($data['file'], ImportActions::originalName($data), auth()->user(), null, array_filter(['mode' => $data['mode'] ?? null])))
                ->successRedirectUrl(fn ($record) => $resource::getUrl('view', ['record' => $record])),
        ];
    }
}
