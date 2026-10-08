<?php

namespace App\Filament\Resources\Tenants\Tables;

use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\PlatformTenantAccess;
use App\Domain\Platform\Services\TenantSuspensions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('country_code')->label('Country'),
                TextColumn::make('users_count')->counts('users')->label('Users'),
                TextColumn::make('companies_count')->counts('companies')->label('Companies'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(TenantStatus::class),
            ])
            ->defaultSort('name')
            ->recordActions([
                // SaaS.2: controlled support access. A reason (and optionally a ticket) is required; the access is
                // time-boxed and audited on the platform chain and on the tenant's own chain.
                Action::make('enter')
                    ->label('Enter')
                    ->icon('heroicon-o-arrow-right-end-on-rectangle')
                    ->modalHeading(fn (Tenant $record) => "Enter {$record->name}")
                    ->modalDescription(fn () => 'Support access is recorded in Markedge\'s audit trail and in this tenant\'s own audit trail, and ends on its own after '.(int) config('peopleos.platform.tenant_access_minutes', 60).' minutes.')
                    ->schema([
                        Textarea::make('reason')->label('Why do you need access?')->required()->minLength(10)->maxLength(1000),
                        TextInput::make('reference')->label('Ticket or case reference')->maxLength(100),
                    ])
                    ->action(function (Tenant $record, array $data, PlatformTenantAccess $access) {
                        try {
                            $access->enter(auth()->user(), $record, $data['reason'], $data['reference'] ?? null, session()->driver());
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return null;
                        }

                        return redirect()->to(filament()->getHomeUrl());
                    }),
                Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-o-pause-circle')
                    ->color('danger')
                    ->visible(fn (Tenant $record) => $record->status !== TenantStatus::Suspended)
                    ->requiresConfirmation()
                    ->modalDescription('Every user is signed out at once, API keys stop working and scheduled work pauses until the tenant is reactivated.')
                    ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                    ->action(fn (Tenant $record, array $data, TenantSuspensions $suspensions) => $suspensions->suspend($record, $data['reason'], auth()->user())),
                Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn (Tenant $record) => $record->status === TenantStatus::Suspended)
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                    ->action(fn (Tenant $record, array $data, TenantSuspensions $suspensions) => $suspensions->reactivate($record, $data['reason'], auth()->user())),
                EditAction::make(),
            ]);
    }
}
