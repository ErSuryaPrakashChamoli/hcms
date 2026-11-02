<?php

namespace App\Filament\Resources\DocumentShareBatches;

use App\Domain\Documents\Models\DocumentShareBatch;
use App\Domain\Documents\Services\DocumentShares;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\DocumentShareBatches\Pages\ListDocumentShareBatches;
use App\Filament\Resources\DocumentShareBatches\Pages\ViewDocumentShareBatch;
use App\Filament\Resources\DocumentShareBatches\RelationManagers\ItemsRelationManager;
use App\Filament\Support\DocumentShareActions;
use App\Filament\Support\EmployeeFilters;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne parity WO-14 (§23.7.3 "Shared letters", #6): the register of bulk document shares — document type, title, how the
 * files were given, published on, by whom, counts shared / failed / unpublished, status — with "Share documents" (opened
 * directly by `?action=shareDocuments`), "Share letters", the sheet template and the published documents list. Read-only:
 * shares are written by DocumentShares. For people who share documents (document.share) and see employees (employee.view).
 */
class DocumentShareBatchResource extends Resource
{
    protected static ?string $model = DocumentShareBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Letters';

    protected static ?string $navigationLabel = 'Shared documents';

    protected static ?string $modelLabel = 'document share';

    protected static ?string $slug = 'document-shares';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return DocumentShareActions::allowed();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['type', 'creator', 'unpublisher'])
            ->withCount(['items as unpublished_count' => fn (Builder $q) => $q->where('status', 'unpublished')]);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'completed' => 'success', 'failed' => 'danger', 'running' => 'info', default => 'warning',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (DocumentShareBatch $record) => $record->title)->columns(4)->schema([
                TextEntry::make('type.name')->label('Document type')->placeholder('—'),
                TextEntry::make('mode')->label('Files')->formatStateUsing(fn (string $state) => DocumentShareBatch::MODES[$state] ?? $state),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => DocumentShareBatch::STATUSES[$state] ?? $state),
                TextEntry::make('outcome')->label('Outcome')->state(fn (DocumentShareBatch $record) => $record->isFinished() ? $record->summary((int) $record->unpublished_count) : 'Waiting to run'),
                TextEntry::make('creator.name')->label('Published by')->placeholder('—'),
                TextEntry::make('created_at')->label('Published on')->dateTime(),
                TextEntry::make('finished_at')->label('Finished')->dateTime()->placeholder('—'),
                TextEntry::make('notify')->label('Employees told')->state(fn (DocumentShareBatch $record) => implode(', ', array_filter([
                    $record->notify_in_app ? 'In-app' : null, $record->email ? 'E-mail ('.mb_strtolower(DocumentShareBatch::EMAIL_TO[$record->email_to] ?? '').', '.$record->emailed.' sent)' : null,
                ])) ?: 'Not notified'),
                TextEntry::make('source_name')->label('Zip / file')->placeholder('—'),
                TextEntry::make('sheet_name')->label('Sheet')->placeholder('—')->visible(fn (DocumentShareBatch $record) => $record->mode === 'sheet'),
                TextEntry::make('message')->label('Message')->placeholder('—')->columnSpan(2),
                TextEntry::make('unused_files')->label('Files in the zip no row named')->columnSpanFull()
                    ->state(fn (DocumentShareBatch $record) => implode(', ', (array) ($record->unused_files ?? [])))->visible(fn (DocumentShareBatch $record) => filled($record->unused_files)),
                TextEntry::make('error')->label('Stopped because')->color('danger')->columnSpanFull()->visible(fn (DocumentShareBatch $record) => filled($record->error)),
                TextEntry::make('unpublished_at')->label('Unpublished')->columnSpanFull()->visible(fn (DocumentShareBatch $record) => $record->unpublished_at !== null)
                    ->state(fn (DocumentShareBatch $record) => $record->unpublished_at?->format('j M Y H:i').' by '.($record->unpublisher?->name ?? '—').': '.$record->unpublish_reason),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('type.name')->label('Document type')->placeholder('—'),
                TextColumn::make('title')->searchable()->weight('medium')->wrap(),
                TextColumn::make('mode')->label('Files')->badge()->color('gray')->formatStateUsing(fn (string $state) => $state === 'sheet' ? 'Zip + sheet' : 'Common file')->toggleable(),
                TextColumn::make('created_at')->label('Published on')->dateTime()->sortable(),
                TextColumn::make('creator.name')->label('Published by')->placeholder('—'),
                TextColumn::make('shared')->label('Shared')->numeric(),
                TextColumn::make('failed')->label('Failed')->numeric()->color(fn (int $state) => $state > 0 ? 'danger' : null),
                TextColumn::make('unpublished_count')->label('Unpublished')->numeric()->toggleable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => DocumentShareBatch::STATUSES[$state] ?? $state),
            ])
            ->filters([
                SelectFilter::make('document_type_id')->label('Document type')->options(fn () => DocumentShares::types()->pluck('name', 'id')->all()),
                SelectFilter::make('status')->options(DocumentShareBatch::STATUSES),
                SelectFilter::make('created_by')->label('Published by')
                    ->options(fn () => User::forCurrentTenant()->whereIn('id', DocumentShareBatch::query()->select('created_by')->distinct())->orderBy('name')->pluck('name', 'id')->all()),
                TernaryFilter::make('unpublished')->label('Unpublished')->queries(
                    true: fn (Builder $q) => $q->whereNotNull('unpublished_at'),
                    false: fn (Builder $q) => $q->whereNull('unpublished_at'),
                ),
                EmployeeFilters::dateRange('published', 'Published on', 'created_at'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentShareBatches::route('/'),
            'view' => ViewDocumentShareBatch::route('/{record}'),
        ];
    }
}
