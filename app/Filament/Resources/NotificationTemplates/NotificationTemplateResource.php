<?php

namespace App\Filament\Resources\NotificationTemplates;

use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\NotificationTemplates\Pages\ManageNotificationTemplates;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?string $navigationLabel = 'Notification templates';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->live(onBlur: true)
                ->afterStateUpdated(fn ($state, callable $set, string $operation) => $operation === 'create' ? $set('key', Str::slug($state, '_')) : null),
            TextInput::make('key')->required()->maxLength(64)->regex('/^[a-z][a-z0-9_]*$/')->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            TextInput::make('subject')->required()->maxLength(255)->placeholder('Welcome {{ employee.first_name }}')->columnSpanFull(),
            Textarea::make('body')->required()->rows(6)->columnSpanFull()
                ->helperText('Variables: employee.name, employee.code, employee.department, employee.manager, subject.label, initiator.name, tenant.name, app.url, task.title, workflow.name, lifecycle.to …'),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            AuditReasonField::make()->visibleOn('edit'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('key')->searchable(),
                TextColumn::make('subject')->limit(60),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->modalWidth('3xl')->using(function (NotificationTemplate $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageNotificationTemplates::route('/')];
    }
}
