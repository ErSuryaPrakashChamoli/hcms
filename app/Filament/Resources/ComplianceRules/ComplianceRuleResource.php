<?php

namespace App\Filament\Resources\ComplianceRules;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Filament\Resources\ComplianceRules\Pages\ListComplianceRules;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use UnitEnum;

/** The versioned statutory rule library (§32). Read-only inside tenants; updated by the platform. */
class ComplianceRuleResource extends Resource
{
    protected static ?string $model = ComplianceRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Statutory rules';

    protected static ?int $navigationSort = 60;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->badge()->color('gray')->sortable(),
                TextColumn::make('state')->placeholder('All India')->formatStateUsing(fn (?string $state) => config("peopleos.compliance.states.{$state}", $state)),
                TextColumn::make('name')->searchable(),
                TextColumn::make('version')->formatStateUsing(fn ($state) => "v{$state}"),
                TextColumn::make('effective_from')->date()->sortable(),
                TextColumn::make('effective_to')->date()->placeholder('Open'),
                TextColumn::make('source')->limit(50)->placeholder('—')->toggleable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->defaultSort('code')
            ->filters([SelectFilter::make('code')->options(['EPF' => 'EPF', 'ESI' => 'ESI', 'PT' => 'Professional tax', 'LWF' => 'LWF', 'TDS' => 'Income tax', 'GRATUITY' => 'Gratuity'])])
            ->recordActions([
                Action::make('parameters')->label('Parameters')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('parameters')->hiddenLabel()->state(fn (ComplianceRule $record) => collect(Arr::dot($record->parameters))->map(fn ($v, $k) => "{$k} = ".(is_bool($v) ? ($v ? 'yes' : 'no') : $v))->values()->all())->listWithLineBreaks(),
                    ]),
            ])
            ->emptyStateHeading('No statutory rules loaded')
            ->emptyStateDescription('Run peopleos:compliance:sync on the platform to load the compliance packs.');
    }

    public static function getPages(): array
    {
        return ['index' => ListComplianceRules::route('/')];
    }
}
