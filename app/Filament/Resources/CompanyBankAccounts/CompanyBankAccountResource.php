<?php

namespace App\Filament\Resources\CompanyBankAccounts;

use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\BankFileLayout;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Filament\Resources\CompanyBankAccounts\Pages\ManageCompanyBankAccounts;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * HROne setup parity BP-13 / CA-5 (Payroll settings → Salary bank; Core HR → Payment bank): the accounts each company pays
 * salaries from. The bank file groups transfers by paying account and writes each account's file in its bank's layout.
 */
class CompanyBankAccountResource extends Resource
{
    protected static ?string $model = CompanyBankAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Payment banks';

    protected static ?string $modelLabel = 'paying bank account';

    protected static ?string $slug = 'payment-banks';

    protected static ?int $navigationSort = 77;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('payroll.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Account')->columns(2)->schema([
                Select::make('company_id')->label('Company')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id'))->required()->native(false),
                TextInput::make('alias')->label('Name for this account')->maxLength(64)->placeholder('e.g. HDFC salary account'),
            ]),
            Section::make('Bank details')->columns(2)->schema([
                TextInput::make('bank_name')->label('Bank')->required()->maxLength(120),
                TextInput::make('bank_code')->label('Salary bank code')->maxLength(32)->helperText('The code your bank gives the company for salary uploads, if any.'),
                TextInput::make('branch_name')->label('Branch')->maxLength(120),
                TextInput::make('ifsc')->label('IFSC')->maxLength(11)->regex('/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/')->helperText('Employees with an IFSC of the same bank are paid from this account.'),
                TextInput::make('account_number')->label('Debit account number')->password()->revealable()->maxLength(34)
                    ->required(fn (string $operation) => $operation === 'create')->formatStateUsing(fn () => null)->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave blank to keep the number on file.' : null),
            ]),
            Section::make('Salary payments')->columns(2)->schema([
                Select::make('bank_file_layout_code')->label('Bank file layout')->placeholder('Plain salary file')
                    ->options(fn () => BankFileLayout::query()->orderBy('code')->orderBy('version')->get()->mapWithKeys(fn (BankFileLayout $l) => [$l->code => "{$l->code} · {$l->name}"])->all()),
                Toggle::make('is_default')->label('Default account of the company')->helperText('Pays everyone not paid from another account.'),
                Select::make('status')->options(CompanyBankAccount::STATUSES)->default('active')->required()->native(false),
                Textarea::make('notes')->rows(2)->maxLength(500)->columnSpanFull(),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('company'))
            ->columns([
                TextColumn::make('alias')->label('Account')->state(fn (CompanyBankAccount $record) => $record->label())->searchable(['alias', 'bank_name']),
                TextColumn::make('company.name')->label('Company'),
                TextColumn::make('bank_name')->label('Bank')->description(fn (CompanyBankAccount $record) => $record->branch_name),
                TextColumn::make('ifsc')->label('IFSC')->placeholder('—'),
                TextColumn::make('bank_code')->label('Salary bank code')->placeholder('—')->toggleable(),
                TextColumn::make('bank_file_layout_code')->label('File layout')->placeholder('Plain file'),
                IconColumn::make('is_default')->label('Default')->boolean(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('company_id')->label('Company')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id'))])
            ->defaultSort('company_id')
            ->recordActions([
                EditAction::make()->using(function (CompanyBankAccount $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCompanyBankAccounts::route('/')];
    }
}
