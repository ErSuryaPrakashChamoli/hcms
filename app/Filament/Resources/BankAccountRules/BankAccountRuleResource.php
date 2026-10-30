<?php

namespace App\Filament\Resources\BankAccountRules;

use App\Domain\Employment\Models\BankAccountRule;
use App\Filament\Resources\BankAccountRules\Pages\ManageBankAccountRules;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * HROne setup parity CA-5 (Payment bank → "restrict the values employees can enter in the bank account number field"):
 * per bank, the length and characters of an account number. Employee 360, bank change requests and bank detail uploads
 * refuse a number that does not fit its bank's rule.
 */
class BankAccountRuleResource extends Resource
{
    protected static ?string $model = BankAccountRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Bank account number rules';

    protected static ?string $modelLabel = 'account number rule';

    protected static ?int $navigationSort = 79;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('payroll.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Bank')->columns(2)->schema([
                TextInput::make('bank_name')->label('Bank')->maxLength(120)->required(fn (Get $get) => blank($get('ifsc_prefix')))->live(onBlur: true)
                    ->helperText('Matched by name when an account has no IFSC, or when the rule gives no IFSC bank code.'),
                TextInput::make('ifsc_prefix')->label('IFSC bank code')->maxLength(4)->regex('/^[A-Za-z]{4}$/')->helperText('The first four letters of the bank\'s IFSC, e.g. HDFC.'),
            ]),
            Section::make('Account number format')->columns(2)->schema([
                TextInput::make('min_length')->label('Shortest account number')->numeric()->minValue(1)->maxValue(34),
                TextInput::make('max_length')->label('Longest account number')->numeric()->minValue(1)->maxValue(34)
                    ->rule(fn (Get $get) => fn (string $attribute, $value, \Closure $fail) => filled($value) && filled($get('min_length')) && (int) $value < (int) $get('min_length') ? $fail('The longest cannot be shorter than the shortest.') : null),
                Toggle::make('allow_digits')->label('Digits allowed')->default(true),
                Toggle::make('allow_letters')->label('Letters allowed')->default(true),
                Toggle::make('allow_special')->label('Special characters allowed')->default(false),
            ]),
            Section::make('Status and notes')->columns(2)->schema([
                Select::make('status')->options(BankAccountRule::STATUSES)->default('active')->required()->native(false),
                Textarea::make('notes')->rows(2)->maxLength(500)->columnSpanFull(),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bank_name')->label('Bank')->placeholder('—')->searchable(),
                TextColumn::make('ifsc_prefix')->label('IFSC bank code')->placeholder('—'),
                TextColumn::make('length')->label('Length')->state(fn (BankAccountRule $r) => ($r->min_length ?? '—').' to '.($r->max_length ?? '—')),
                IconColumn::make('allow_digits')->label('Digits')->boolean(),
                IconColumn::make('allow_letters')->label('Letters')->boolean(),
                IconColumn::make('allow_special')->label('Special')->boolean(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make()->using(function (BankAccountRule $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBankAccountRules::route('/')];
    }
}
