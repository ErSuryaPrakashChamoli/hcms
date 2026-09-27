<?php

namespace App\Filament\Resources\OnboardingTemplates\RelationManagers;

use App\Domain\Configuration\Models\Form;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Onboarding\Models\OnboardingTemplateItem;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Checklist items';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('phase')->options(collect(config('peopleos.onboarding.phases'))->map(fn ($p) => $p['label'])->all())->required(),
            Select::make('type')->options(config('peopleos.onboarding.item_types'))->default('task')->required()->live(),
            TextInput::make('sort_order')->numeric()->default(0),
            TextInput::make('title')->required()->maxLength(255)->columnSpan(2),
            TextInput::make('due_offset_days')->label('Due (days from joining)')->numeric()->helperText('Blank uses the phase default.'),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            Select::make('owner_type')->options(config('peopleos.onboarding.owner_types'))->default('employee')->required()->live(),
            Select::make('owner_role_id')->label('Role')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all())->visible(fn (Get $get) => $get('owner_type') === 'role')->required(fn (Get $get) => $get('owner_type') === 'role'),
            Select::make('owner_user_id')->label('User')->options(fn () => User::query()->forCurrentTenant()->orderBy('name')->pluck('name', 'id')->all())->searchable()->visible(fn (Get $get) => $get('owner_type') === 'user')->required(fn (Get $get) => $get('owner_type') === 'user'),
            Select::make('document_type_id')->label('Document type')->options(fn () => DocumentType::query()->orderBy('name')->pluck('name', 'id')->all())->visible(fn (Get $get) => $get('type') === 'document'),
            Select::make('form_id')->label('Form')->options(fn () => Form::query()->orderBy('name')->pluck('name', 'id')->all())->visible(fn (Get $get) => $get('type') === 'form'),
            Toggle::make('is_mandatory')->label('Mandatory')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('phase')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.onboarding.phases.{$state}.label", $state)),
                TextColumn::make('sort_order')->label('#'),
                TextColumn::make('title')->wrap(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.onboarding.item_types.{$state}", $state)),
                TextColumn::make('owner_type')->label('Owner')->formatStateUsing(fn (string $state, OnboardingTemplateItem $record) => match ($state) {
                    'role' => 'Role: '.$record->ownerRole?->name,
                    'user' => $record->ownerUser?->name,
                    default => config("peopleos.onboarding.owner_types.{$state}", $state),
                }),
                TextColumn::make('due_offset_days')->label('Due +days')->placeholder('phase'),
                IconColumn::make('is_mandatory')->boolean()->label('Mandatory'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([CreateAction::make()->modalWidth('3xl')])
            ->recordActions([EditAction::make()->modalWidth('3xl'), DeleteAction::make()]);
    }
}
