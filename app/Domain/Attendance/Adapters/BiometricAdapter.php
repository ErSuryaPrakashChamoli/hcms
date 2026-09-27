<?php

namespace App\Domain\Attendance\Adapters;

/**
 * Vendor adapter (§24): turn a device payload into normalised punches
 * [{employee_code, punched_at (ISO/parsable), direction in|out|auto, external_id?, latitude?, longitude?}].
 */
interface BiometricAdapter
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function normalise(array $payload): array;
}
