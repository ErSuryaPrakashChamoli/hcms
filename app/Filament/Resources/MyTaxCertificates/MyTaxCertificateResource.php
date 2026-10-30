<?php

namespace App\Filament\Resources\MyTaxCertificates;

use App\Domain\Compliance\Models\TdsCertificate;
use App\Domain\Compliance\Services\TaxSettings;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\MyTaxCertificates\Pages\ListMyTaxCertificates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use UnitEnum;

/** HROne setup parity (PS-2): an employee's own issued TDS certificates (Form 16 / 130), when the organisation shows them. */
class MyTaxCertificateResource extends Resource
{
    protected static ?string $model = TdsCertificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My tax certificates';

    protected static ?string $modelLabel = 'tax certificate';

    protected static ?string $slug = 'my-tax-certificates';

    protected static ?int $navigationSort = 47;

    public static function canAccess(): bool
    {
        return app(TaxSettings::class)->form16SelfService() && (auth()->user()?->can('payroll.payslip') ?? false) && Employee::query()->where('user_id', auth()->id())->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', 'issued')->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('financial_year')->label('Financial year')->sortable(),
                TextColumn::make('code')->label('Form'),
                TextColumn::make('certificate_number')->placeholder('—'),
                TextColumn::make('snapshot.part_b.tds_deducted')->label('Tax deducted')->numeric(2),
                TextColumn::make('issued_at')->label('Issued')->date(),
            ])
            ->defaultSort('financial_year', 'desc')
            ->recordActions([
                Action::make('view')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([TextEntry::make('snapshot')->hiddenLabel()->state(fn (TdsCertificate $record) => collect(Arr::dot($record->snapshot))->map(fn ($v, $k) => "{$k} = ".(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)))->values()->all())->listWithLineBreaks()]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMyTaxCertificates::route('/')];
    }
}
