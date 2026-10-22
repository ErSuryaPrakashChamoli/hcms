<?php

namespace App\Filament\Resources\Tenants\Tables;

use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Platform\Services\PlatformTenantAccess;
use App\Domain\Platform\Services\TenantFirstAdministrator;
use App\Domain\Platform\Services\TenantSuspensions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
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
                IconColumn::make('import_export')->label('Import & export')->boolean()
                    ->state(fn (Tenant $record) => app(FeatureFlags::class)->enabledFor($record, EmployeeImports::FEATURE)),
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
                    ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
                    ->action(fn (Tenant $record, array $data, TenantSuspensions $suspensions) => $suspensions->suspend($record, $data['reason'], auth()->user())),
                Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn (Tenant $record) => $record->status === TenantStatus::Suspended)
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
                    ->action(fn (Tenant $record, array $data, TenantSuspensions $suspensions) => $suspensions->reactivate($record, $data['reason'], auth()->user())),
                // A platform-controlled flag: an operator and a reason, recorded on both chains (FeatureFlags::setForTenant).
                Action::make('importExport')
                    ->label('Import & export')
                    ->icon('heroicon-o-arrows-up-down')
                    ->modalHeading(fn (Tenant $record) => "Employee import and export for {$record->name}")
                    ->modalDescription('Lets the organisation\'s administrators upload employees from a CSV file and download employee records in the same format.')
                    ->fillForm(fn (Tenant $record) => ['enabled' => app(FeatureFlags::class)->enabledFor($record, EmployeeImports::FEATURE)])
                    ->schema([
                        Toggle::make('enabled')->label('Allow employee import and export'),
                        Textarea::make('reason')->required()->minLength(5)->maxLength(1000),
                    ])
                    ->action(function (Tenant $record, array $data, FeatureFlags $flags) {
                        try {
                            $changed = $flags->setForTenant($record, EmployeeImports::FEATURE, (bool) $data['enabled'], auth()->user(), $data['reason']);
                            Notification::make()->success()->title($changed ? 'Employee import and export '.($data['enabled'] ? 'switched on' : 'switched off') : 'No change')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                // SaaS.8: the first administrator's invitation, when provisioning could not e-mail it or it has lapsed.
                Action::make('sendInvitation')
                    ->label('Send invitation')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (Tenant $record, $livewire) => $record->status !== TenantStatus::Suspended && $livewire->hasInvitedAdministrator($record))
                    ->modalDescription('E-mails the first administrator a new invitation link (any earlier link stops working).')
                    ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
                    ->action(function (Tenant $record, array $data, TenantFirstAdministrator $administrator) {
                        try {
                            $administrator->send($record, auth()->user(), $data['reason']);
                            Notification::make()->success()->title('Invitation sent')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                EditAction::make(),
            ]);
    }
}
