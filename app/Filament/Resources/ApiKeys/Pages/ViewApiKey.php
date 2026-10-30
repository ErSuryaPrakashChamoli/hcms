<?php

namespace App\Filament\Resources\ApiKeys\Pages;

use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Filament\Widgets\ApiKeyUsageChart;

/** HROne parity CA-11: one API key — overview, auth, IP addresses and rate limit, usage chart, API calls and security events. */
class ViewApiKey extends PeopleViewRecord
{
    protected static string $resource = ApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApiKeyResource::apiReferenceAction(),
            ApiKeyResource::editAction(),
            ApiKeyResource::regenerateAction(),
            ApiKeyResource::revokeAction(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [ApiKeyUsageChart::class];
    }

    public function getWidgetData(): array
    {
        return ['apiKeyId' => $this->record->getKey()];
    }
}
