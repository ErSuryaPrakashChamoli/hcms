<?php

namespace App\Filament\Resources\IntegrationLogExports;

use App\Domain\Integration\Models\IntegrationLogExport;
use App\Domain\Integration\Services\IntegrationLogExports;
use App\Filament\Resources\IntegrationLogExports\Pages\ManageIntegrationLogExports;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/**
 * HROne parity SV-26: the person's own queued exports of the webhook delivery log and of API call logs. Download is for the
 * person who asked, while they may still export that log (audited); files are purged with retention.report_runs_days.
 */
class IntegrationLogExportResource extends Resource
{
    protected static ?string $model = IntegrationLogExport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'Log exports';

    protected static ?string $modelLabel = 'log export';

    protected static ?int $navigationSort = 12;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('requested_by', auth()->id() ?? 0);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Asked for')->dateTime()->sortable(),
                TextColumn::make('kind')->label('Log')->formatStateUsing(fn (string $state) => IntegrationLogExport::KINDS[$state] ?? $state),
                TextColumn::make('filters')->label('Filters')->state(fn (IntegrationLogExport $record) => collect($record->filters ?? [])->map(fn ($v, $k) => str_replace('_', ' ', (string) $k).': '.$v)->values()->all())
                    ->listWithLineBreaks()->placeholder('None'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => IntegrationLogExport::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success', 'failed' => 'danger', 'running' => 'info', default => 'warning'
                    }),
                TextColumn::make('row_count')->label('Rows')->numeric()->placeholder('—'),
                TextColumn::make('error')->placeholder('—')->wrap()->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('download')->label('Download')->icon('heroicon-m-arrow-down-tray')
                    ->visible(fn (IntegrationLogExport $record) => app(IntegrationLogExports::class)->canDownload($record, auth()->user()))
                    ->action(function (IntegrationLogExport $record) {
                        try {
                            $file = app(IntegrationLogExports::class)->download($record, auth()->user());
                        } catch (RuntimeException $e) {
                            ServiceDeskActions::refuse($e->getMessage());

                            return null;
                        }

                        return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => 'text/csv']);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageIntegrationLogExports::route('/')];
    }
}
