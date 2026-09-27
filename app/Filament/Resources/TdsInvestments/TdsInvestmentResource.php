<?php

namespace App\Filament\Resources\TdsInvestments;

use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\TdsInvestments\Pages\ManageTdsInvestments;
use App\Filament\Support\StatutoryReturnActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/** Part L/T: investment and deduction proofs against employee tax declarations. */
class TdsInvestmentResource extends Resource
{
    protected static ?string $model = TdsEmployeeInvestment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'TDS investment proofs';

    protected static ?int $navigationSort = 37;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('employee_id')->label('Employee')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => Employee::query()->where('employee_code', 'like', "%{$search}%")->limit(25)->pluck('employee_code', 'id')->all()),
            TextInput::make('financial_year')->required()->maxLength(9)->placeholder('2026-27'),
            TextInput::make('section')->required()->maxLength(16)->placeholder('80C'),
            TextInput::make('description')->maxLength(255),
            TextInput::make('declared_amount')->numeric()->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.employee_code')->label('Employee'),
                TextColumn::make('financial_year')->label('FY'),
                TextColumn::make('section'),
                TextColumn::make('declared_amount')->numeric(2),
                TextColumn::make('proof_amount')->numeric(2)->placeholder('—'),
                TextColumn::make('proof_status')->badge(),
            ])
            ->filters([SelectFilter::make('proof_status')->options(TdsEmployeeInvestment::STATUSES)])
            ->recordActions([
                Action::make('verifyProof')->label('Verify proof')->requiresConfirmation()
                    ->visible(fn (TdsEmployeeInvestment $record) => $record->proof_status === 'pending' && StatutoryReturnActions::user()->can('update', $record))
                    ->schema([TextInput::make('proof_amount')->numeric()->required(), Select::make('proof_status')->options(['verified' => 'Verified', 'rejected' => 'Rejected'])->required(), Textarea::make('notes')->rows(2)])
                    ->action(fn (TdsEmployeeInvestment $record, array $data) => StatutoryReturnActions::run(function () use ($record, $data) {
                        if ((int) $record->employee?->user_id === (int) StatutoryReturnActions::user()->getKey()) {
                            throw new RuntimeException('You cannot verify your own investment proof.');
                        }
                        $record->withAuditReason($data['notes'] ?? 'Proof reviewed')->update($data + ['verified_by' => StatutoryReturnActions::user()->getKey(), 'verified_at' => now()]);
                    }, 'Proof reviewed')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTdsInvestments::route('/')];
    }
}
