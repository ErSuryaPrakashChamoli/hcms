<?php

namespace App\Filament\Resources\AccessScopeGroups\Pages;

use App\Domain\Identity\Services\AccessScopeGroups;
use App\Filament\Resources\AccessScopeGroups\AccessScopeGroupResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleCreateRecord;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateAccessScopeGroup extends PeopleCreateRecord
{
    protected static string $resource = AccessScopeGroupResource::class;

    /** Written through AccessScopeGroups (validated, audited with the reason, access answers forgotten). */
    protected function handleRecordCreation(array $data): Model
    {
        $reason = (string) AuditReasonField::extract($data);
        try {
            return app(AccessScopeGroups::class)->save(null, $data, auth()->user(), $reason);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
    }
}
