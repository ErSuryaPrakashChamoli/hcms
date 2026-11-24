<?php

namespace App\Filament\RelationManagers\Masters;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Master relationships R4: a read-only tab of a master's edit page, drawn from a view (no table). It rides on the audit
 * relationship every master has, only because a relation manager needs one; nothing is listed from it.
 */
abstract class MasterTab extends RelationManager
{
    protected static string $relationship = 'auditEvents';

    protected static bool $isLazy = false;

    /** The view the tab shows. */
    protected static string $contentView;

    abstract public static function appliesTo(Model $record): bool;

    /** @return array<string, mixed> */
    abstract protected function tabData(): array;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return MasterTabs::isMaster($ownerRecord) && static::appliesTo($ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([View::make(static::$contentView)->viewData(fn () => $this->tabData())]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([])->paginated(false);
    }
}
