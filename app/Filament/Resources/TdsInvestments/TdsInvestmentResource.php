<?php

namespace App\Filament\Resources\TdsInvestments;

use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Services\InvestmentProofs;
use App\Domain\Compliance\Support\InvestmentCatalogue;
use App\Filament\Resources\MyInvestmentProofs\MyInvestmentProofResource;
use App\Filament\Resources\TdsInvestments\Pages\ManageTdsInvestments;
use App\Filament\Support\PayrollRegisters;
use App\Filament\Support\StatutoryReturnActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/** Part L/T: investment and deduction proofs against employee tax declarations, one row per item filed in DeclareInvestments. */
class TdsInvestmentResource extends Resource
{
    protected static ?string $model = TdsEmployeeInvestment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'TDS investment proofs';

    protected static ?int $navigationSort = 37;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('employee.person'))
            ->columns([
                TextColumn::make('employee.employee_code')->label('Code')->searchable(),
                TextColumn::make('employee.person.display_name')->label('Employee'),
                TextColumn::make('financial_year')->label('FY'),
                TextColumn::make('section')->formatStateUsing(fn (string $state) => InvestmentCatalogue::SECTIONS[$state]['code'] ?? $state),
                TextColumn::make('description')->label('Item')->wrap()->placeholder('—'),
                TextColumn::make('declared_amount')->numeric(2),
                TextColumn::make('proof_claimed_amount')->label('Proof shows')->numeric(2)->placeholder('—'),
                TextColumn::make('proof_files')->label('Proof files')->html()->state(fn (TdsEmployeeInvestment $record) => MyInvestmentProofResource::fileLinks($record)),
                TextColumn::make('proof_amount')->numeric(2)->placeholder('—'),
                TextColumn::make('proof_status')->badge(),
            ])
            ->filters([
                SelectFilter::make('proof_status')->options(TdsEmployeeInvestment::STATUSES),
                // HROne parity PO-1: the WO-2 employee filters (payroll side only).
                ...PayrollRegisters::filters('payroll.view'),
            ])
            ->recordActions([
                Action::make('verifyProof')->label('Verify proof')->requiresConfirmation()
                    ->visible(fn (TdsEmployeeInvestment $record) => $record->proof_status === 'pending' && StatutoryReturnActions::user()->can('update', $record))
                    ->fillForm(fn (TdsEmployeeInvestment $record) => ['proof_amount' => (float) ($record->proof_claimed_amount ?? $record->declared_amount)])
                    ->schema([TextInput::make('proof_amount')->numeric()->required(), Select::make('proof_status')->options(['verified' => 'Verified', 'rejected' => 'Rejected'])->required(), Textarea::make('notes')->rows(2)])
                    ->action(fn (TdsEmployeeInvestment $record, array $data) => StatutoryReturnActions::run(function () use ($record, $data) {
                        if ((int) $record->employee?->user_id === (int) StatutoryReturnActions::user()->getKey()) {
                            throw new RuntimeException('You cannot verify your own investment proof.');
                        }
                        // HROne parity PY-12: one review path (InvestmentProofs) for this screen and the proof inbox.
                        app(InvestmentProofs::class)->review($record, $data['proof_status'], (float) $data['proof_amount'], $data['notes'] ?? null, StatutoryReturnActions::user());
                    }, 'Proof reviewed')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTdsInvestments::route('/')];
    }
}
