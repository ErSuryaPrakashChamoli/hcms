<?php

namespace App\Domain\Employment\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Jobs\DeliverNotification;
use App\Domain\Notifications\Models\NotificationDelivery;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * HROne parity WO-2 ("Send email" on an employee): HR writes to an employee from the Employee 360 or a register.
 *
 * - Needs employee.message and the employee within the sender's access (`view`); the personal e-mail also needs
 *   employee.sensitive.view (it is sensitive personal data).
 * - Sent through the notification delivery log on the tenant's mail transport (one delivery per address, delivered by
 *   the tenant-bound job after the transaction commits), so it shows on the delivery log and can be resent like any other.
 * - Audited on the employee as EMPLOYEE_EMAILED with the subject and which addresses (work / personal) — never the body.
 */
final class EmployeeMessages
{
    public const RECIPIENTS = ['work' => 'Work e-mail', 'personal' => 'Personal e-mail'];

    public const EVENT = 'employee.emailed';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  list<string>  $to  work and/or personal
     * @return int the number of e-mails queued
     */
    public function email(Employee $employee, User $actor, string $subject, string $body, array $to = ['work'], bool $copyToMe = false): int
    {
        if (! $actor->hasPermission('employee.message')) {
            throw new RuntimeException('You cannot e-mail employees.');
        }
        if (Gate::forUser($actor)->denies('view', $employee)) {
            throw new RuntimeException('You cannot see this employee.');
        }
        if (! config('peopleos.notifications.channels.email')) {
            throw new RuntimeException('E-mail is not available.');
        }
        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || mb_strlen($subject) > 200) {
            throw new RuntimeException('Give a subject of at most 200 characters.');
        }
        if ($body === '' || mb_strlen($body) > 10000) {
            throw new RuntimeException('Write a message of at most 10,000 characters.');
        }
        $to = array_values(array_intersect(array_keys(self::RECIPIENTS), $to)) ?: ['work'];
        if (in_array('personal', $to, true) && ! $actor->hasPermission('employee.sensitive.view')) {
            throw new RuntimeException('Writing to the personal e-mail needs access to sensitive personal data.');
        }

        $employee->loadMissing('person');
        $addresses = [];
        foreach ($to as $kind) {
            $address = $kind === 'work' ? $employee->work_email : $employee->person?->personal_email;
            if (filled($address) && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $addresses[mb_strtolower((string) $address)] = [(string) $address, $kind];
            }
        }
        if ($addresses === []) {
            throw new RuntimeException('No '.implode(' or ', array_map(fn ($k) => mb_strtolower(self::RECIPIENTS[$k]), $to)).' on file.');
        }
        if ($copyToMe && filled($actor->email) && ! isset($addresses[mb_strtolower($actor->email)])) {
            $addresses[mb_strtolower($actor->email)] = [$actor->email, 'copy'];
        }

        $deliveries = [];
        foreach ($addresses as [$address, $kind]) {
            $delivery = NotificationDelivery::create([
                'user_id' => match ($kind) {
                    'copy' => $actor->id,
                    'work' => $employee->user_id,
                    default => null,
                },
                'channel' => 'email',
                'event' => self::EVENT,
                'subject' => $subject,
                'body' => $body,
                'status' => 'queued',
                'recipient' => $address,
                'source_type' => $employee->getMorphClass(),
                'source_id' => $employee->getKey(),
                'correlation_id' => Context::get('request_id'),
            ]);
            DeliverNotification::dispatch($delivery->id)->afterCommit();
            $deliveries[] = $delivery->id;
        }

        $this->audit->record(AuditAction::EmployeeEmailed, 'employment', $employee, [], null, metadata: [
            'subject' => mb_substr($subject, 0, 200),
            'to' => $to,
            'copy_to_sender' => $copyToMe,
            'deliveries' => $deliveries,
        ], actor: $actor);

        return count($deliveries);
    }
}
