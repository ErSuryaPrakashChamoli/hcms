<?php

namespace App\Filament\Resources\ExitCases\RelationManagers;

use App\Domain\Exit\Models\ExitWithdrawalRequest;
use App\Filament\Support\SeparationActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-11 (§23.6.2 #1–2): requests to withdraw this resignation, newest first, with HR's approve / reject and
 * the requester's "Call off". Shown only once the exit has a request (withdrawals need approval only when the
 * organisation asks for it) and to whoever may see the exit case.
 */
class WithdrawalRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'withdrawalRequests';

    protected static ?string $title = 'Withdrawal requests';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (auth()->user()?->can('view', $ownerRecord) ?? false) && $ownerRecord->withdrawalRequests()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['requester', 'decider']))
            ->columns([
                TextColumn::make('id')->label('Request')->formatStateUsing(fn ($state, ExitWithdrawalRequest $record) => $record->reference()),
                TextColumn::make('created_at')->label('Requested on')->dateTime(),
                TextColumn::make('requester.name')->label('By')->placeholder('—'),
                TextColumn::make('reason')->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', 'pending' => 'warning', default => 'gray'
                })->formatStateUsing(fn (string $state) => ExitWithdrawalRequest::STATUSES[$state] ?? $state),
                TextColumn::make('decider.name')->label('Decided by')->placeholder('—'),
                TextColumn::make('decided_at')->label('Decided on')->dateTime()->placeholder('—'),
                TextColumn::make('decision_note')->label('Note')->placeholder('—')->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated(false)
            ->recordActions(SeparationActions::forWithdrawal());
    }
}
