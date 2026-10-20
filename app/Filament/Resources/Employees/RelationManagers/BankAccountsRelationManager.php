<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\ProfileChangeActions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Sensitive (blueprint §66, §80): opening the tab is audited, numbers are masked, revealing
 * one needs a stated purpose and is audited again.
 */
class BankAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'bankAccounts';

    protected static ?string $title = 'Bank';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewSensitive', $ownerRecord) ?? false;
    }

    public function mount(): void
    {
        app(SensitiveAccessAuditor::class)->recordView($this->getOwnerRecord(), 'bank_accounts');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('account_holder_name')->required()->maxLength(255),
            TextInput::make('bank_name')->required()->maxLength(255),
            TextInput::make('branch_name')->maxLength(255),
            TextInput::make('ifsc')->label('IFSC')->maxLength(16)->regex('/^[A-Z]{4}0[A-Z0-9]{6}$/')->helperText('Format ABCD0123456'),
            TextInput::make('account_number')->required()->maxLength(34)->password()->revealable(),
            Select::make('account_type')->options(['savings' => 'Savings', 'current' => 'Current', 'salary' => 'Salary']),
            Toggle::make('is_primary')->label('Primary (salary) account'),
            AuditReasonField::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bank_name')->description(fn ($record) => $record->branch_name),
                TextColumn::make('account_holder_name')->label('Holder'),
                TextColumn::make('account_number_last4')->label('Account')->formatStateUsing(fn ($state) => '••••'.$state),
                TextColumn::make('ifsc')->label('IFSC')->placeholder('—'),
                TextColumn::make('account_type')->badge()->placeholder('—'),
                IconColumn::make('is_primary')->label('Primary')->boolean(),
                TextColumn::make('verified_at')->dateTime()->placeholder('Unverified'),
            ])
            ->headerActions([
                // Phase 12: every write goes through ChangeBankAccountAction (authorization, validation, audit).
                CreateAction::make()->using(function (array $data, RelationManager $livewire) {
                    $reason = AuditReasonField::extract($data);

                    return ProfileChangeActions::run(fn () => app(ChangeBankAccountAction::class)->add($livewire->getOwnerRecord(), $data, auth()->user(), $reason));
                }),
            ])
            ->recordActions([
                Action::make('reveal')
                    ->label('Reveal')
                    ->icon('heroicon-m-eye')
                    ->schema([Textarea::make('purpose')->required()->maxLength(500)->helperText('Recorded in the audit trail with your name and time.')])
                    ->action(function (EmployeeBankAccount $record, array $data) {
                        app(SensitiveAccessAuditor::class)->recordView($record->employee, 'bank_account:'.$record->id, $data['purpose']);

                        Notification::make()
                            ->title($record->bank_name)
                            ->body("Account number: {$record->account_number}\nIFSC: {$record->ifsc}")
                            ->persistent()
                            ->send();
                    }),
                EditAction::make()->using(function (EmployeeBankAccount $record, array $data) {
                    $reason = AuditReasonField::extract($data);

                    return ProfileChangeActions::run(fn () => app(ChangeBankAccountAction::class)->update($record, $data, auth()->user(), $reason));
                }),
                DeleteAction::make()->using(fn (EmployeeBankAccount $record) => ProfileChangeActions::run(fn () => app(ChangeBankAccountAction::class)->remove($record, auth()->user()) ?? true)),
            ]);
    }
}
