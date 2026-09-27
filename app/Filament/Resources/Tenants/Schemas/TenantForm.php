<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\Domain\Platform\Enums\TenantStatus;
use App\Filament\Support\AuditReasonField;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tenant')
                    ->columns(2)
                    ->schema([
                        Select::make('tier')->options(config('peopleos.enterprise.tiers'))->default('shared')->required(),
                        Select::make('region')->label('Data region')->options(config('peopleos.enterprise.regions'))->placeholder('Default'),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set, string $operation) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                        TextInput::make('slug')
                            ->required()
                            ->alphaDash()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('status')
                            ->options(TenantStatus::class)
                            ->default(TenantStatus::Active)
                            ->required(),
                        DateTimePicker::make('trial_ends_at')->native(false),
                    ]),
                Section::make('Locale defaults')
                    ->columns(4)
                    ->schema([
                        TextInput::make('country_code')->required()->default('IN')->length(2),
                        TextInput::make('timezone')->required()->default('Asia/Kolkata')->maxLength(64),
                        TextInput::make('locale')->required()->default('en')->maxLength(16),
                        TextInput::make('currency')->required()->default('INR')->length(3),
                    ]),
                Section::make('First administrator')
                    ->description('Provisioned as Tenant Super Admin together with the system roles, default features and settings.')
                    ->columns(3)
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('admin_name')->label('Name')->required()->dehydrated(false),
                        TextInput::make('admin_email')->label('Email')->email()->required()->dehydrated(false)->unique('users', 'email'),
                        TextInput::make('admin_password')->label('Password')->password()->revealable()->minLength(12)->required()->dehydrated(false),
                    ]),
                AuditReasonField::make(),
            ]);
    }
}
