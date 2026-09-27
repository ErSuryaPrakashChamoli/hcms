<?php

namespace App\Domain\Attendance\Adapters;

/**
 * eSSL-style push: {"logs": [{"EmployeeCode": "...", "LogDate": "2026-09-26 09:02:11", "Direction": "in"|"out", "SerialNumber": "..."}]}
 * Field names follow the common eSSL/ZK push format; adjust per device firmware.
 */
final class EsslAdapter implements BiometricAdapter
{
    public function normalise(array $payload): array
    {
        return array_values(array_map(fn (array $log) => [
            'employee_code' => (string) ($log['EmployeeCode'] ?? $log['employee_code'] ?? ''),
            'punched_at' => (string) ($log['LogDate'] ?? $log['punched_at'] ?? ''),
            'direction' => match (strtolower((string) ($log['Direction'] ?? ''))) {
                'in', '0' => 'in',
                'out', '1' => 'out',
                default => 'auto',
            },
            'external_id' => isset($log['SerialNumber'], $log['LogDate']) ? $log['SerialNumber'].'@'.$log['LogDate'] : null,
            'latitude' => null,
            'longitude' => null,
        ], $payload['logs'] ?? []));
    }
}
