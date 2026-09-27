<?php

namespace App\Filament\Resources\ConfigurationChanges;

use App\Domain\Configuration\Enums\ChangeStatus;
use App\Domain\Configuration\Enums\RiskLevel;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Filament\Resources\ConfigurationChanges\Pages\ListConfigurationChanges;
use App\Filament\Resources\ConfigurationChanges\Pages\ViewConfigurationChange;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Configuration Change Centre (§71): pending, scheduled, published, rejected, history. */
class ConfigurationChangeResource extends Resource
{
    protected static ?string $model = ConfigurationChange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Change Centre';

    protected static ?string $modelLabel = 'configuration change';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = ConfigurationChange::query()->where('status', ChangeStatus::PendingApproval)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Change')
                ->columns(4)
                ->schema([
                    TextEntry::make('subject_type')->label('Record type')->formatStateUsing(fn (string $state) => class_basename($state)),
                    TextEntry::make('subject_label')->label('Record')->placeholder('—'),
                    TextEntry::make('risk_level')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('change_type')->badge()->color('gray'),
                    TextEntry::make('effective_from')->date()->placeholder('Immediately'),
                    TextEntry::make('requester.name')->label('Requested by')->placeholder('—'),
                    TextEntry::make('created_at')->label('Requested')->dateTime(),
                    TextEntry::make('reason')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('What changes')
                ->schema([
                    RepeatableEntry::make('diff')
                        ->hiddenLabel()
                        ->state(fn (ConfigurationChange $record) => collect($record->diff())->map(fn ($d, $f) => ['field' => $f, 'before' => self::scalar($d['before']), 'after' => self::scalar($d['after'])])->values()->all())
                        ->columns(3)
                        ->schema([
                            TextEntry::make('field'),
                            TextEntry::make('before')->placeholder('∅'),
                            TextEntry::make('after')->placeholder('∅'),
                        ]),
                ]),
            Section::make('Impact preview')
                ->columns(2)
                ->schema([
                    TextEntry::make('impact.summary')->label('Summary')->placeholder('—'),
                    TextEntry::make('impact.employees_affected')->label('Employees affected'),
                    KeyValueEntry::make('impact.details.departments')->label('By department')->columnSpanFull(),
                ]),
            Section::make('Review')
                ->columns(3)
                ->collapsed(fn (ConfigurationChange $record) => $record->reviewed_at === null)
                ->schema([
                    TextEntry::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
                    TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('published_at')->dateTime()->placeholder('—'),
                    TextEntry::make('review_note')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('rolledBackBy.id')->label('Rolled back by change')->formatStateUsing(fn ($state) => "#{$state}")->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('subject_type')->label('Type')->formatStateUsing(fn (string $state) => class_basename($state))->badge()->color('gray'),
                TextColumn::make('subject_label')->label('Record')->searchable()->placeholder('—'),
                TextColumn::make('risk_level')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('impact.employees_affected')->label('Affects')->placeholder('0'),
                TextColumn::make('effective_from')->date()->placeholder('Now')->sortable(),
                TextColumn::make('requester.name')->label('By')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('risk_level')->options(RiskLevel::class),
                SelectFilter::make('status')->options(ChangeStatus::class)->multiple(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConfigurationChanges::route('/'),
            'view' => ViewConfigurationChange::route('/{record}'),
        ];
    }

    private static function scalar(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
