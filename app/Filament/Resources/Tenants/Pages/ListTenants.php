<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\PlatformOperationsOverview;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListTenants extends PeopleListRecords
{
    protected static string $resource = TenantResource::class;

    /** @var list<int>|null SaaS.8: read once per request (non-public properties do not survive Livewire requests). */
    private ?array $invited = null;

    /** SaaS.8: whether the tenant's first super administrator has not accepted an invitation yet. */
    public function hasInvitedAdministrator(Tenant $tenant): bool
    {
        return in_array($tenant->id, $this->invited ??= app(PlatformOperationsOverview::class)->tenantsWithInvitedAdministrator(), true);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
