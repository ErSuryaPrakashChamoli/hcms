<?php

namespace App\Filament\Resources\ServiceResolverRules\Pages;

use App\Domain\ServiceDesk\Models\ServiceResolverRule;
use App\Filament\Resources\ServiceResolverRules\ServiceResolverRuleResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateServiceResolverRule extends PeopleCreateRecord
{
    protected static string $resource = ServiceResolverRuleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return ServiceResolverRule::query()->create($data);
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
