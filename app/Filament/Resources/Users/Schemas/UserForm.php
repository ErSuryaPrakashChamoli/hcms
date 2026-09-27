<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Filament\Support\AuditReasonField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->minLength(12)
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText('Leave blank to keep the current password.'),
                        Select::make('status')
                            ->options(UserStatus::class)
                            ->default(UserStatus::Active)
                            ->required(),
                        TextInput::make('timezone')->placeholder('Asia/Kolkata')->maxLength(64),
                    ]),
                Section::make('Roles')
                    ->schema([
                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->disabled(fn () => ! auth()->user()->can('user.assign_roles'))
                            ->helperText('Tenant-wide role assignments. Organisational restrictions are set under Access scope below.'),
                    ]),
                Section::make('Access scope')
                    ->description('Leave empty for tenant-wide access. Selections restrict the employees and organisation units this user can reach; dimensions combine with AND.')
                    ->schema([
                        Select::make('access_scope.company')->label('Companies')->multiple()->searchable()->preload()
                            ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('access_scope.location')->label('Locations')->multiple()->searchable()->preload()
                            ->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('access_scope.department')->label('Departments')->multiple()->searchable()->preload()
                            ->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('access_scope.business_unit')->label('Business units')->multiple()->searchable()->preload()
                            ->options(fn () => BusinessUnit::query()->orderBy('name')->pluck('name', 'id')),
                    ])
                    ->columns(2)
                    ->disabled(fn () => ! auth()->user()->can('user.assign_roles'))
                    ->dehydrated(fn () => auth()->user()->can('user.assign_roles')),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}
