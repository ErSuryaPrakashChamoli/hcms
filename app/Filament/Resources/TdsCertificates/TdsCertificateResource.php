<?php

namespace App\Filament\Resources\TdsCertificates;

use App\Domain\Compliance\Models\TdsCertificate;
use App\Domain\Compliance\Services\Tds\TdsCertificates;
use App\Filament\Resources\TdsCertificates\Pages\ListTdsCertificates;
use App\Filament\Support\StatutoryReturnActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use UnitEnum;

/** Part L/T: Form No. 130 (earlier Form 16) snapshots from the verified annual ledger. */
class TdsCertificateResource extends Resource
{
    protected static ?string $model = TdsCertificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'TDS certificates (Form 130)';

    protected static ?string $slug = 'compliance/tds-certificates';

    protected static ?int $navigationSort = 36;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('employee.person');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('financial_year')->label('FY')->sortable(),
                TextColumn::make('employee.person.display_name')->label('Employee'),
                TextColumn::make('code')->label('Form')->formatStateUsing(fn (string $state, TdsCertificate $record) => "{$state} (formerly {$record->legacy_code})"),
                TextColumn::make('version')->formatStateUsing(fn ($state) => "v{$state}"),
                TextColumn::make('status')->badge(),
                TextColumn::make('snapshot.part_b.tds_deducted')->label('TDS')->numeric(2),
                TextColumn::make('certificate_number')->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->options(['generated' => 'Generated', 'issued' => 'Issued', 'superseded' => 'Superseded'])])
            ->recordActions([
                Action::make('snapshot')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([TextEntry::make('snapshot')->hiddenLabel()->state(fn (TdsCertificate $record) => collect(Arr::dot($record->snapshot))->map(fn ($v, $k) => "{$k} = ".(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)))->values()->all())->listWithLineBreaks()]),
                Action::make('issue')->icon('heroicon-m-paper-airplane')->requiresConfirmation()
                    ->visible(fn (TdsCertificate $record) => $record->status === 'generated' && StatutoryReturnActions::user()->hasPermission('compliance.tds.manage'))
                    ->schema([TextInput::make('certificate_number')->label('TRACES certificate number')->maxLength(64)])
                    ->action(fn (TdsCertificate $record, array $data) => StatutoryReturnActions::run(fn () => app(TdsCertificates::class)->issue($record, StatutoryReturnActions::user(), $data['certificate_number'] ?? null), 'Certificate issued')),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListTdsCertificates::route('/')];
    }
}
