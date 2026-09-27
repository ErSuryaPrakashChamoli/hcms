<?php

namespace App\Filament\Resources\TicketCategories;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\Workflow\Models\Workflow;
use App\Filament\Resources\TicketCategories\Pages\ManageTicketCategories;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TicketCategoryResource extends Resource
{
    protected static ?string $model = TicketCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Service Desk';

    protected static ?string $navigationLabel = 'Categories & SLAs';

    protected static ?int $navigationSort = 50;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('servicedesk.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            TextInput::make('sort_order')->numeric()->default(0),
            TextInput::make('sla_hours')->label('Resolution SLA')->numeric()->minValue(1)->default(48)->suffix('hours')->required(),
            TextInput::make('first_response_hours')->label('First response')->numeric()->minValue(1)->default(8)->suffix('hours')->required(),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            Select::make('default_assignee_id')->label('Default agent')->placeholder('—')->searchable()->options(fn () => User::forCurrentTenant()->get()->filter(fn (User $u) => $u->hasPermission('servicedesk.view'))->pluck('name', 'id')->all()),
            Select::make('assignee_role_id')->label('or agents with role')->placeholder('—')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('escalation_role_id')->label('Escalate breaches to role')->placeholder('—')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('workflow_key')->label('Start workflow')->placeholder('None')->options(fn () => Workflow::query()->where('status', 'active')->pluck('name', 'key')->all())->helperText('e.g. an approval flow for letter requests'),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#'),
                TextColumn::make('name')->searchable(),
                TextColumn::make('code'),
                TextColumn::make('sla_hours')->label('SLA')->suffix(' h'),
                TextColumn::make('first_response_hours')->label('First response')->suffix(' h'),
                TextColumn::make('defaultAssignee.name')->label('Default agent')->placeholder('—'),
                TextColumn::make('assigneeRole.name')->label('Role')->placeholder('—'),
                TextColumn::make('workflow_key')->label('Workflow')->placeholder('—'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make()->using(function (TicketCategory $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTicketCategories::route('/')];
    }
}
