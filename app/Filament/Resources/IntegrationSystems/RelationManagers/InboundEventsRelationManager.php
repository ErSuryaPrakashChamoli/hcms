<?php

namespace App\Filament\Resources\IntegrationSystems\RelationManagers;

use App\Filament\Resources\InboundEvents\InboundEventResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** Phase 14: the integration's inbound events (same columns and actions as the Inbound events list). */
class InboundEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'inboundEvents';

    protected static ?string $title = 'Inbound events';

    public function table(Table $table): Table
    {
        return InboundEventResource::table($table);
    }
}
