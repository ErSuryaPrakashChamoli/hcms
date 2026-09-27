<?php

namespace App\Filament\Resources\NotificationRules;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\NotificationRules\Pages\ManageNotificationRules;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Event -> Rule -> Audience -> Channel -> Template (§47). */
class NotificationRuleResource extends Resource
{
    protected static ?string $model = NotificationRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?string $navigationLabel = 'Notification rules';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('event')->options(array_combine(config('peopleos.notifications.events'), config('peopleos.notifications.events')))->required()->searchable(),
            Select::make('notification_template_id')->label('Template')->relationship('template', 'name')->required()->preload()->searchable(),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            Repeater::make('audience')->schema([
                Select::make('type')->options(config('peopleos.notifications.audience_types'))->required()->live(),
                Select::make('role_id')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all())->visible(fn (Get $get) => $get('type') === 'role')->required(fn (Get $get) => $get('type') === 'role'),
                Select::make('user_id')->options(fn () => User::query()->forCurrentTenant()->orderBy('name')->pluck('name', 'id')->all())->searchable()->visible(fn (Get $get) => $get('type') === 'user')->required(fn (Get $get) => $get('type') === 'user'),
            ])->columns(3)->minItems(1)->columnSpanFull(),
            CheckboxList::make('channels')->options(collect(config('peopleos.notifications.channels'))->map(fn ($c) => $c['label'])->all())->default(['in_app'])->columns(5)->required()->columnSpanFull(),
            Repeater::make('conditions')->label('Only when')->schema([
                TextInput::make('field')->required()->placeholder('employee.department, lifecycle.to, change.risk'),
                Select::make('operator')->options(config('peopleos.rules.operators'))->required()->default('equals'),
                TextInput::make('value'),
            ])->columns(3)->defaultItems(0)->columnSpanFull(),
            AuditReasonField::make()->visibleOn('edit'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('event')->badge()->color('gray'),
                TextColumn::make('audience')->state(fn (NotificationRule $record) => collect($record->audience)->map(fn ($a) => config('peopleos.notifications.audience_types')[$a['type'] ?? ''] ?? $a['type'])->implode(', ')),
                TextColumn::make('channels')->badge()->separator(','),
                TextColumn::make('template.name')->label('Template'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([SelectFilter::make('event')->options(array_combine(config('peopleos.notifications.events'), config('peopleos.notifications.events')))])
            ->defaultSort('event')
            ->recordActions([
                EditAction::make()->modalWidth('4xl')->using(function (NotificationRule $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageNotificationRules::route('/')];
    }
}
