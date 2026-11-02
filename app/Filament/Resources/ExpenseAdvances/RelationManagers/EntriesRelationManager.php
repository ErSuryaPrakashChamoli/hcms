<?php

namespace App\Filament\Resources\ExpenseAdvances\RelationManagers;

use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The advance's ledger: dispensed, settled against reports, refunded (XO-6: and how), written off. Read only. */
class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Ledger';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('report'))
            ->columns([
                TextColumn::make('entry_date')->label('Date')->date(),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state) => ExpenseAdvanceEntry::KINDS[$state] ?? $state),
                TextColumn::make('amount')->numeric(2),
                TextColumn::make('report.number')->label('Report')->placeholder('—'),
                TextColumn::make('reference')->placeholder('—'),
                // HROne parity XO-6: how a refund came back (bank transfer, cheque with bank, number and date, cash).
                TextColumn::make('refund_mode')->label('How')->placeholder('—')->state(fn (ExpenseAdvanceEntry $e) => $e->refundModeLabel()),
                TextColumn::make('note')->placeholder('—')->wrap(),
            ]);
    }
}
