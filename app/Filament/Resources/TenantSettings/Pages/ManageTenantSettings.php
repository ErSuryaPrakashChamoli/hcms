<?php

namespace App\Filament\Resources\TenantSettings\Pages;

use App\Domain\Platform\Models\TenantSetting;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\TenantSettings\TenantSettingResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageTenantSettings extends PeopleManageRecords
{
    protected static string $resource = TenantSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data) {
                    $setting = new TenantSetting;
                    $setting->fill($data)->withAuditReason(AuditReasonField::extract($data))->fill($data)->save();

                    return $setting;
                })
                ->after(fn () => app(SettingsRepository::class)->forget()),
        ];
    }
}
