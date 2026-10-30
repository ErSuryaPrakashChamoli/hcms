<?php

namespace App\Filament\Resources\Loans\RelationManagers;

use App\Domain\Loans\Models\LoanInstallment;
use App\Domain\Loans\Services\Loans;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** The loan's instalment schedule; payroll managers may defer a due instalment to the end. */
class ScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'schedule';

    protected static ?string $title = 'Instalments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sequence')->label('#'),
                TextColumn::make('due_month')->label('Month'),
                TextColumn::make('principal_part')->label('Principal')->numeric(2),
                TextColumn::make('interest_part')->label('Interest')->numeric(2),
                TextColumn::make('amount')->numeric(2),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => LoanInstallment::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'recovered', 'prepaid' => 'success', 'deferred', 'waived' => 'gray', default => 'warning',
                    }),
                TextColumn::make('recovered_on')->date()->placeholder('—'),
                TextColumn::make('note')->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                Action::make('defer')->icon('heroicon-m-arrow-right-circle')->color('gray')
                    ->visible(fn (LoanInstallment $record) => $record->status === 'due' && auth()->user()->can('payroll.manage'))
                    ->schema([Textarea::make('note')->required()->maxLength(255)])
                    ->action(function (LoanInstallment $record, array $data) {
                        try {
                            app(Loans::class)->defer($record, $data['note']);
                            Notification::make()->success()->title('Instalment moved to the end of the schedule')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
