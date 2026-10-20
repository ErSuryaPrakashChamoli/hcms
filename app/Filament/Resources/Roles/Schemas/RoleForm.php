<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\RoleGrants;
use App\Filament\Support\AuditReasonField;
use Closure;
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
                            // SaaS.10 (E1): a permission added to a role must be one the editor holds (RoleGrants).
                            ->rule(fn (?Role $record) => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                $added = array_diff(array_map('intval', (array) $value), $record?->permissions()->pluck('permissions.id')->map(fn ($id) => (int) $id)->all() ?? []);
                                $beyond = app(RoleGrants::class)->keysBeyond(auth()->user(), $added);
                                if ($beyond !== []) {
                                    $fail('You cannot add permissions you do not hold yourself: '.implode(', ', array_slice($beyond, 0, 5)).(count($beyond) > 5 ? ' …' : '').'.');
                                }
                            })
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
