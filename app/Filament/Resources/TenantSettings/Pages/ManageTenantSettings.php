<?php

namespace App\Filament\Resources\TenantSettings\Pages;

use App\Domain\Platform\Models\TenantSetting;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\TenantSettings\TenantSettingResource;
use App\Filament\Support\AuditReasonField;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTenantSettings extends ManageRecords
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
