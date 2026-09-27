<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Domain\Identity\Models\Permission;
use App\Filament\Support\AuditReasonField;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Role')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set, string $operation) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(64)
                            ->alphaDash()
                            ->disabled(fn (string $operation, $record) => $operation === 'edit' && $record?->is_system)
                            ->dehydrated()
                            ->helperText('Stable identifier used by policies and integrations.'),
                        TextInput::make('description')->maxLength(255)->columnSpanFull(),
                    ]),
                Section::make('Permissions')
                    ->description('resource.action keys from the platform catalogue. Tenants cannot invent new keys.')
                    ->schema([
                        CheckboxList::make('permissions')
                            ->relationship('permissions', 'key')
                            ->descriptions(fn () => Permission::query()->pluck('description', 'id')->all())
                            ->searchable()
                            ->bulkToggleable()
                            ->columns(3)
                            ->gridDirection('row'),
                    ]),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}
