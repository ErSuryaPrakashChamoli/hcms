<?php

namespace App\Domain\Attendance\Adapters;

/** {"punches": [{"employee_code": "EMP00001", "punched_at": "...", "direction": "in", "id": "..."}]} */
final class GenericJsonAdapter implements BiometricAdapter
{
    public function normalise(array $payload): array
    {
        return array_values(array_map(fn (array $p) => [
            'employee_code' => (string) ($p['employee_code'] ?? ''),
            'punched_at' => (string) ($p['punched_at'] ?? ''),
            'direction' => in_array($p['direction'] ?? 'auto', ['in', 'out'], true) ? $p['direction'] : 'auto',
            'external_id' => isset($p['id']) ? (string) $p['id'] : null,
            'latitude' => $p['latitude'] ?? null,
            'longitude' => $p['longitude'] ?? null,
        ], $payload['punches'] ?? []));
    }
}
