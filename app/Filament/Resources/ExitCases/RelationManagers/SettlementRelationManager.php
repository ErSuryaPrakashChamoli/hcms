<?php

namespace App\Filament\Resources\ExitCases\RelationManagers;

use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Exit\Services\FinalSettlements;
use App\Filament\Support\ExitActions;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Full & final (one row per exit) with a line-by-line breakdown modal. */
class SettlementRelationManager extends RelationManager
{
    protected static string $relationship = 'settlement';

    protected static ?string $title = 'Full & final';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('exit.settle') || $user->can('exit.view') || $ownerRecord->employee?->user_id === $user->id);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'paid' => 'success', 'approved' => 'primary', 'calculated' => 'info', default => 'gray'
                })->formatStateUsing(fn (string $state) => config("peopleos.exit.settlement_statuses.{$state}", $state)),
                TextColumn::make('total_earnings')->numeric(2),
                TextColumn::make('total_deductions')->numeric(2),
                TextColumn::make('net_amount')->numeric(2)->weight('bold'),
                TextColumn::make('calculated_at')->dateTime()->placeholder('—'),
                TextColumn::make('approver.name')->label('Approved by')->placeholder('—'),
                TextColumn::make('paid_at')->dateTime()->placeholder('—'),
                TextColumn::make('payment_reference')->placeholder('—'),
            ])
            ->paginated(false)
            ->headerActions([
                Action::make('start')->label('Start settlement')->icon('heroicon-m-plus')
                    ->visible(fn () => $this->getOwnerRecord()->settlement()->doesntExist() && auth()->user()->can('exit.settle'))
                    ->action(fn () => ServiceDeskActions::run(fn () => app(FinalSettlements::class)->calculate($this->getOwnerRecord(), auth()->user()), fn ($s) => 'Calculated: net '.number_format((float) $s->net_amount, 2))),
            ])
            ->recordActions([
                Action::make('breakdown')->label('Breakdown')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([
                        RepeatableEntry::make('lines')->hiddenLabel()->columns(4)->schema([
                            TextEntry::make('type')->badge()->color(fn (string $state) => $state === 'earning' ? 'success' : 'danger'),
                            TextEntry::make('name'),
                            TextEntry::make('amount')->numeric(2),
                            TextEntry::make('basis')->state(fn ($record) => collect($record->basis ?? [])->except(['formula'])->map(fn ($v, $k) => "{$k}: ".(is_scalar($v) ? $v : json_encode($v)))->implode(' · '))->placeholder('—')->wrap(),
                        ]),
                        Section::make('Inputs')->collapsed()->schema([
                            TextEntry::make('inputs')->hiddenLabel()->state(fn (FinalSettlement $record) => collect($record->inputs ?? [])->map(fn ($v, $k) => "{$k}: ".(is_scalar($v) ? var_export($v, true) : json_encode($v)))->all())->listWithLineBreaks(),
                        ]),
                    ]),
                ...ExitActions::forSettlement(),
            ]);
    }
}
