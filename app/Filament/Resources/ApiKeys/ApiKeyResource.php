<?php

namespace App\Filament\Resources\ApiKeys;

use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Services\ApiKeys;
use App\Filament\Resources\ApiKeys\Pages\ManageApiKeys;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use UnitEnum;

/** Integration Hub credentials (§87, §109). The secret is shown exactly once. */
class ApiKeyResource extends Resource
{
    protected static ?string $model = ApiKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'API keys';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->placeholder('Recruitment system'),
            CheckboxList::make('scopes')->options(config('peopleos.api.scopes'))->required()->columns(1),
            DatePicker::make('expires_at')->native(false)->placeholder('Never'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('prefix')->fontFamily('mono'),
                TextColumn::make('scopes')->badge()->separator(','),
                TextColumn::make('last_used_at')->dateTime()->placeholder('Never'),
                TextColumn::make('expires_at')->dateTime()->placeholder('Never'),
                TextColumn::make('status')->badge(),
                TextColumn::make('creator.name')->label('Created by')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('revoke')->label('Revoke')->icon('heroicon-m-no-symbol')->color('danger')
                    ->visible(fn (ApiKey $record) => $record->isUsable())
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->maxLength(255)])
                    ->action(fn (ApiKey $record, array $data) => app(ApiKeys::class)->revoke($record, $data['reason'] ?? null)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageApiKeys::route('/')];
    }

    public static function issueAction(): CreateAction
    {
        return CreateAction::make()
            ->label('Issue key')
            ->using(function (array $data) {
                $issued = app(ApiKeys::class)->issue($data['name'], $data['scopes'], isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null);

                Notification::make()
                    ->success()
                    ->title('API key issued — copy it now, it will not be shown again')
                    ->body($issued['plaintext'])
                    ->persistent()
                    ->send();

                return $issued['key'];
            });
    }
}
