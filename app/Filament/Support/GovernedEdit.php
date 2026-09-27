<?php

namespace App\Filament\Support;

use App\Domain\Configuration\Services\ConfigurationChanges;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit-page save path for configuration records: strips the audit reason and custom fields,
 * then either applies the change directly (audited) or, when the tenant requires approval for
 * this risk level, parks it in the Configuration Change Centre and leaves the record untouched.
 */
trait GovernedEdit
{
    use SavesCustomFields;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $reason = AuditReasonField::extract($data);
        $this->extractCustomFields($data);

        $changes = app(ConfigurationChanges::class);

        if ($changes->isGoverned($record) && $changes->requiresApproval($record)) {
            $change = $changes->propose($record, $data, $reason);

            Notification::make()
                ->warning()
                ->title('Submitted for approval')
                ->body("Change #{$change->id} is waiting in the Configuration Change Centre. The record stays unchanged until it is approved.")
                ->persistent()
                ->send();

            throw new Halt;
        }

        $record->withAuditReason($reason)->update($data);
        $this->persistCustomFields($record, $reason);

        return $record;
    }
}
