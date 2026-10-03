<?php

namespace App\Filament\Resources\AuditEvents;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\ChangeIntelligence;
use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\AuditEvents\Pages\ViewAuditEvent;
use App\Filament\Resources\AuditEvents\Schemas\AuditEventInfolist;
use App\Filament\Resources\AuditEvents\Tables\AuditEventsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Change history explorer. Strictly read-only: the model itself refuses updates and deletes.
 */
class AuditEventResource extends Resource
{
    /** Phase 14: an organisation-scoped auditor sees only changes to records of employees in their scope. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return auth()->user() ? app(ChangeIntelligence::class)->scope($query, auth()->user()) : $query->whereRaw('1 = 0');
    }

    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?string $navigationLabel = 'Change history';

    protected static ?string $modelLabel = 'audit event';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return AuditEventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AuditEventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEvents::route('/'),
            'view' => ViewAuditEvent::route('/{record}'),
        ];
    }
}
