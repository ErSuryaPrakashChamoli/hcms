<?php

namespace App\Filament\Resources\Tenants\Tables;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Http\Middleware\ResolveTenant;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                Action::make('enter')
                    ->label('Enter')
                    ->icon('heroicon-o-arrow-right-end-on-rectangle')
                    ->visible(fn (Tenant $record) => $record->isAccessible())
                    ->action(function (Tenant $record) {
                        session()->put(ResolveTenant::SESSION_KEY, $record->id);

                        return redirect()->to(filament()->getHomeUrl());
                    }),
                Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-o-pause-circle')
                    ->color('danger')
                    ->visible(fn (Tenant $record) => $record->status !== TenantStatus::Suspended)
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                    ->action(function (Tenant $record, array $data, AuditRecorder $audit) {
                        $record->withAuditReason($data['reason'])->update(['status' => TenantStatus::Suspended]);
                        $audit->record(AuditAction::TenantSuspended, 'platform', $record, reason: $data['reason'], tenantId: $record->id);
                    }),
                Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn (Tenant $record) => $record->status === TenantStatus::Suspended)
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                    ->action(function (Tenant $record, array $data, AuditRecorder $audit) {
                        $record->withAuditReason($data['reason'])->update(['status' => TenantStatus::Active]);
                        $audit->record(AuditAction::TenantReactivated, 'platform', $record, reason: $data['reason'], tenantId: $record->id);
                    }),
                EditAction::make(),
            ]);
    }
}
