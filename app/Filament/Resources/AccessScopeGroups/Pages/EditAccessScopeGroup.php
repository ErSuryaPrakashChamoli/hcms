<?php

namespace App\Filament\Resources\AccessScopeGroups\Pages;

use App\Domain\Identity\Models\AccessScopeGroup;
use App\Domain\Identity\Services\AccessScopeGroups;
use App\Filament\Resources\AccessScopeGroups\AccessScopeGroupResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditAccessScopeGroup extends PeopleEditRecord
{
    protected static string $resource = AccessScopeGroupResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var AccessScopeGroup $group */
        $group = $this->getRecord();
        $groups = app(AccessScopeGroups::class);

        return $data + ['rules' => array_map(fn (array $ids) => array_map('strval', $ids), $groups->rulesOf($group)), 'members' => array_map('strval', $groups->membersOf($group))];
    }

    /** Written through AccessScopeGroups (validated, audited with the reason, access answers forgotten). */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $reason = (string) AuditReasonField::extract($data);
        try {
            /** @var AccessScopeGroup $record */
            return app(AccessScopeGroups::class)->save($record, $data, auth()->user(), $reason);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
    }
}
