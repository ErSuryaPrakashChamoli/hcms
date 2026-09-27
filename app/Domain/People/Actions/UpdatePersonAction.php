<?php

namespace App\Domain\People\Actions;

use App\Domain\People\Models\Person;
use Illuminate\Support\Facades\DB;

/** Authoritative person update: only person-owned facts, audited with a reason (contract §3). */
final class UpdatePersonAction
{
    public const FIELDS = ['first_name', 'middle_name', 'last_name', 'preferred_name', 'date_of_birth', 'gender', 'marital_status', 'nationality', 'blood_group', 'personal_email', 'personal_phone', 'photo_path', 'metadata'];

    /** @param  array<string, mixed>  $attributes */
    public function handle(Person $person, array $attributes, ?string $reason = null): Person
    {
        $attributes = array_intersect_key($attributes, array_flip(self::FIELDS));

        return DB::transaction(function () use ($person, $attributes, $reason) {
            $person->withAuditReason($reason)->update($attributes);

            return $person->refresh();
        });
    }
}
