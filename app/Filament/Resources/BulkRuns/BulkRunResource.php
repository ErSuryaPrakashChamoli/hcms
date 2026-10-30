<?php

namespace App\Filament\Resources\BulkRuns;

use App\Domain\Experience\Models\BulkRun;
use App\Filament\Resources\BulkRuns\Pages\ListBulkRuns;
use App\Filament\Resources\BulkRuns\Pages\ViewBulkRun;
use App\Filament\Resources\BulkRuns\RelationManagers\ItemsRelationManager;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne parity WO-1: bulk runs started from registers, with each record's outcome (done / refused with the reason /
 * failed). A person sees their own runs; bulk.view_all sees everyone's. Read-only: runs are written by BulkRuns.
 */
class BulkRunResource extends Resource
{
    protected static ?string $model = BulkRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Bulk runs';

    protected static ?string $modelLabel = 'bulk run';

    protected static ?int $navigationSort = 95;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('user');
        $user = auth()->user();

        return $user?->hasPermission('bulk.view_all') ? $query : $query->where('user_id', $user?->id ?? 0);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'completed' => 'success', 'failed' => 'danger', 'running' => 'info', default => 'warning',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            TextEntry::make('operation_label')->label('Action')->weight('bold'),
            TextEntry::make('register')->label('Register')->formatStateUsing(fn (string $state) => ucfirst(str_replace('_', ' ', $state))),
            TextEntry::make('selection')->label('Records')->formatStateUsing(fn (string $state, BulkRun $record) => $record->total.' · '.(BulkRun::SELECTIONS[$state] ?? $state)),
            TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => BulkRun::STATUSES[$state] ?? $state),
            TextEntry::make('summary')->label('Outcome')->state(fn (BulkRun $record) => $record->isFinished() ? $record->summary() : 'Waiting to run'),
            TextEntry::make('user.name')->label('Started by'),
            TextEntry::make('created_at')->label('Started')->dateTime(),
            TextEntry::make('finished_at')->label('Finished')->dateTime()->placeholder('—'),
            TextEntry::make('error')->placeholder('—')->color('danger')->columnSpanFull()->visible(fn (BulkRun $record) => filled($record->error)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('operation_label')->label('Action')->searchable()->weight('medium'),
                TextColumn::make('register')->formatStateUsing(fn (string $state) => ucfirst(str_replace('_', ' ', $state)))->toggleable(),
                TextColumn::make('total')->label('Records')->numeric(),
                TextColumn::make('done')->numeric(),
                TextColumn::make('refused')->numeric()->color(fn (int $state) => $state > 0 ? 'warning' : null),
                TextColumn::make('failed')->numeric()->color(fn (int $state) => $state > 0 ? 'danger' : null)->toggleable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => BulkRun::STATUSES[$state] ?? $state),
                TextColumn::make('user.name')->label('Started by')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(BulkRun::STATUSES),
                SelectFilter::make('register')->options(fn () => BulkRun::query()->distinct()->orderBy('register')->pluck('register', 'register')->map(fn (string $r) => ucfirst(str_replace('_', ' ', $r)))->all()),
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
            'index' => ListBulkRuns::route('/'),
            'view' => ViewBulkRun::route('/{record}'),
        ];
    }
}
