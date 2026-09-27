<?php

namespace App\Filament\Widgets;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\TenantContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TenantOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Tenant at a glance';

    public static function canView(): bool
    {
        return app(TenantContext::class)->has();
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Companies', Company::query()->count()),
            Stat::make('Users', User::query()->forCurrentTenant()->count()),
            Stat::make('Roles', Role::query()->count()),
            Stat::make('Changes (24h)', AuditEvent::query()->where('occurred_at', '>=', now()->subDay())->count()),
        ];
    }
}
