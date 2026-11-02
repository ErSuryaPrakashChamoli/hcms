<?php

namespace App\Filament\Resources\PublishedDocuments;

use App\Domain\Documents\Models\DocumentShareBatch;
use App\Domain\Documents\Models\DocumentShareItem;
use App\Domain\Documents\Services\DocumentShares;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\DocumentShareBatches\DocumentShareBatchResource;
use App\Filament\Resources\PublishedDocuments\Pages\ListPublishedDocuments;
use App\Filament\Support\DocumentShareActions;
use App\Filament\Support\EmployeeFilters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne parity WO-14 (§23.7.4 "Shared letters by employee"): one row per employee and document shared with them in bulk
 * — document type, title, published on, published by, status — inside the viewer's access scope, with the filters
 * document type, published by, publish date, status, share and the WO-2 employee search, codes and organisation filters;
 * "View" (the documents' own signed download) and "Unpublish" one or the rows ticked (hidden, kept — D-WO5). Issued
 * letters stay in Letters (with Revoke). Read-only otherwise; for people who share documents.
 */
class PublishedDocumentResource extends Resource
{
    protected static ?string $model = DocumentShareItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Letters';

    protected static ?string $navigationLabel = 'Published documents';

    protected static ?string $modelLabel = 'published document';

    protected static ?string $slug = 'published-documents';

    protected static ?int $navigationSort = 21;

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
        return parent::getEloquentQuery()->whereIn('status', ['shared', 'unpublished'])->whereNotNull('employee_id')
            ->with(['employee.person', 'document', 'batch.type', 'batch.creator', 'unpublisher']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_code')->label('Employee code')->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('employee.person.full_name')->label('Employee name')->placeholder('—'),
                TextColumn::make('batch.type.name')->label('Document type')->placeholder('—'),
                TextColumn::make('batch.title')->label('Title')->wrap(),
                TextColumn::make('document.original_name')->label('File')->placeholder('—')->toggleable(),
                TextColumn::make('shared_at')->label('Published on')->dateTime()->sortable(),
                TextColumn::make('batch.creator.name')->label('Published by')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => DocumentShareItem::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'shared' ? 'success' : 'gray'),
                TextColumn::make('unpublish_reason')->label('Unpublished because')->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('document_share_batch_id')->label('Share')->formatStateUsing(fn ($state) => '#'.$state)
                    ->url(fn (DocumentShareItem $record) => DocumentShareBatchResource::getUrl('view', ['record' => $record->document_share_batch_id]))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('document_type')->label('Document type')->options(fn () => DocumentShares::types()->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereHas('batch', fn (Builder $b) => $b->where('document_type_id', (int) $data['value'])) : $query),
                SelectFilter::make('published_by')->label('Published by')
                    ->options(fn () => User::forCurrentTenant()->whereIn('id', DocumentShareBatch::query()->select('created_by')->distinct())->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereHas('batch', fn (Builder $b) => $b->where('created_by', (int) $data['value'])) : $query),
                EmployeeFilters::dateRange('published', 'Published on', 'shared_at'),
                SelectFilter::make('status')->options(['shared' => 'Shared', 'unpublished' => 'Unpublished']),
                SelectFilter::make('batch')->label('Share')->attribute('document_share_batch_id')
                    ->options(fn () => DocumentShareBatch::query()->orderByDesc('id')->limit(200)->get(['id', 'title'])->mapWithKeys(fn (DocumentShareBatch $b) => [$b->id => "#{$b->id} {$b->title}"])->all()),
                EmployeeFilters::codes('employee'),
                EmployeeFilters::organisation('employee'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                DocumentShareActions::viewDocument(),
                DocumentShareActions::unpublishItem(),
                Action::make('openShare')->label('Open share')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->url(fn (DocumentShareItem $record) => DocumentShareBatchResource::getUrl('view', ['record' => $record->document_share_batch_id])),
            ])
            ->toolbarActions([DocumentShareActions::unpublishSelected()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPublishedDocuments::route('/'),
        ];
    }
}
