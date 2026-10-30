<?php

namespace App\Domain\Enterprise\Support;

/**
 * HROne parity SV-26 (event test): a sample `data` block for every webhook event, in the shape the real event carries
 * (WebhookEventBridge), built from fixed placeholder values. Never real records: ids are 0, codes start with SAMPLE-,
 * and every sample says `"sample": true`, so a receiver can tell a test from a real event.
 */
final class WebhookSamples
{
    public const PING = 'ping';

    private const LIFECYCLE = ['employee.created' => 'created', 'employee.joined' => 'active', 'employee.probation' => 'probation', 'employee.confirmed' => 'confirmed',
        'employee.notice_period' => 'notice_period', 'employee.exited' => 'exited', 'employee.alumni' => 'alumni', 'employee.rehired' => 'active'];

    /** @return array<string, string> event => label, ping first */
    public static function events(): array
    {
        $events = [self::PING => 'ping (connection test)'];
        foreach (config('peopleos.enterprise.webhook_events', []) as $event) {
            $events[$event] = $event;
        }

        return $events;
    }

    public static function exists(string $event): bool
    {
        return $event === self::PING || in_array($event, config('peopleos.enterprise.webhook_events', []), true);
    }

    /** @return array<string, mixed> the sample `data` of an event */
    public static function data(string $event): array
    {
        $today = now()->toDateString();
        $employee = ['employee_id' => 0, 'employee_code' => 'SAMPLE-0001'];
        $family = explode('.', $event)[0];

        $data = match (true) {
            $event === self::PING => ['message' => 'PeopleOS webhook test'],
            isset(self::LIFECYCLE[$event]) => $employee + ['from' => 'probation', 'to' => self::LIFECYCLE[$event], 'effective_date' => $today],
            $event === 'workflow.completed' => ['instance_id' => 0, 'workflow' => 'sample_workflow', 'outcome' => 'approved'],
            $event === 'document.expiring' => ['document_id' => 0, 'employee_id' => 0, 'expires_on' => now()->addDays(30)->toDateString()],
            $family === 'leave' => $employee + ['leave_type' => 'SAMPLE', 'from' => $today, 'to' => $today, 'days' => 1],
            $family === 'attendance' => $employee + ['date' => $today],
            $family === 'payroll' => ['employee_id' => null, 'employee_code' => null, 'run' => 'SAMPLE-RUN', 'period' => now()->format('Y-m')],
            in_array($family, ['compliance', 'workforce', 'career', 'succession', 'talent', 'survey', 'campaign', 'communication', 'kb'], true) => ['employee_id' => null, 'employee_code' => null, 'reference' => 'SAMPLE-0001'],
            default => $employee + ['reference' => 'SAMPLE-0001'],
        };

        return $event === self::PING ? $data : $data + ['sample' => true];
    }
}
