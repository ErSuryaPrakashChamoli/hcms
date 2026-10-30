<?php

namespace App\Filament\Resources\MoodReasons;

use App\Domain\Engagement\Models\MoodReason;
use App\Filament\Resources\MoodReasons\Pages\ManageMoodReasons;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity HP-9f (Engage → Happiness index → reason tags): the reasons employees may pick with their mood —
 * "You can add up to 20 active tags", active and inactive tags, "Add new reason", and a preview of the question.
 */
class MoodReasonResource extends Resource
{
    protected static ?string $model = MoodReason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFaceSmile;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Mood reasons';

    protected static ?string $modelLabel = 'mood reason';

    protected static ?int $navigationSort = 66;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Reason')->required()->maxLength(60)->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
            TextInput::make('sort_order')->label('Order')->numeric()->integer()->minValue(0)->default(fn () => ((int) MoodReason::query()->max('sort_order')) + 10),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Reason')->searchable()->sortable(),
                TextColumn::make('is_system')->label('Type')->badge()->color('gray')->formatStateUsing(fn ($state) => $state ? 'Standard' : 'Added'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray')->formatStateUsing(fn (string $state) => MoodReason::STATUSES[$state] ?? $state),
                TextColumn::make('sort_order')->label('Order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([SelectFilter::make('status')->options(MoodReason::STATUSES)])
            ->recordActions([
                EditAction::make(),
                Action::make('toggle')->label(fn (MoodReason $record) => $record->status === 'active' ? 'Make inactive' : 'Make active')->color('gray')
                    ->icon(fn (MoodReason $record) => $record->status === 'active' ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
                    ->visible(fn () => auth()->user()->can('engagement.manage'))
                    ->action(function (MoodReason $record) {
                        try {
                            $record->update(['status' => $record->status === 'active' ? 'inactive' : 'active']);
                            Notification::make()->success()->title($record->name.' is '.$record->status)->send();
                        } catch (RuntimeException $e) {
                            $record->refresh();
                            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMoodReasons::route('/')];
    }
}
