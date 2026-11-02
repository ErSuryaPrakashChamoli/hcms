<?php

namespace App\Domain\Letters\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterEmail;
use App\Domain\Notifications\Jobs\DeliverNotification;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * HROne parity WO-13 ("Do you want to share the letter on email Id?", §23.7.1 #14, §23.7.2 #8): an issued letter e-mailed
 * to the employee with the letter's file attached.
 *
 * - Off unless the organisation switches `letters.email.enabled` on (Setup → Workforce); e-mail must be an available channel.
 * - Needs letter.issue and the employee within the sender's access (`view`); the personal e-mail also needs
 *   employee.sensitive.view. Addresses come from the person record only (work e-mail, personal e-mail).
 * - Copies (CC / BCC) go only to people of the organisation (their sign-in e-mail) or to addresses typed in and checked as
 *   e-mail addresses; at most `peopleos.letters.email_max_copies` in all.
 * - One e-mail on the notification delivery log (event letter.emailed, the tenant's mail transport, delivered by the
 *   tenant-bound job after the transaction commits, retried and resendable like any other); its envelope (other
 *   recipients, copies, the attachment) is a LetterEmail row read when the mail is sent, so a letter revoked before then
 *   is not sent.
 * - Audited on the letter as LETTER_EMAILED with the kinds of address and the number of copies — never the message.
 */
final class LetterEmails
{
    public const RECIPIENTS = ['work' => 'Work e-mail', 'personal' => 'Personal e-mail'];

    public const EVENT = 'letter.emailed';

    public function __construct(private readonly AuditRecorder $audit, private readonly SettingsRepository $settings) {}

    /** Is e-mailing letters switched on for the organisation (and e-mail available)? */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('letters.email.enabled', false) && (bool) config('peopleos.notifications.channels.email');
    }

    /**
     * @param  list<string>  $to  work and/or personal
     * @param  array{cc?: array{users?: list<int|string>, addresses?: list<string>}, bcc?: array{users?: list<int|string>, addresses?: list<string>}}  $copies
     */
    public function send(Letter $letter, User $actor, array $to = ['work'], array $copies = [], ?string $subject = null, ?string $message = null): LetterEmail
    {
        if (! $this->enabled()) {
            throw new RuntimeException('E-mailing letters is switched off (Setup → Workforce).');
        }
        if (! $actor->hasPermission('letter.issue')) {
            throw new RuntimeException('You cannot e-mail letters.');
        }
        if ($letter->status !== 'issued') {
            throw new RuntimeException('Only an issued letter can be e-mailed.');
        }
        $employee = $letter->employee()->with('person')->first() ?? throw new RuntimeException('The letter\'s employee is not in your access.');
        if (Gate::forUser($actor)->denies('view', $employee) || Gate::forUser($actor)->denies('view', $letter)) {
            throw new RuntimeException('You cannot see this employee.');
        }
        $to = array_values(array_intersect(array_keys(self::RECIPIENTS), $to)) ?: ['work'];
        if (in_array('personal', $to, true) && ! $actor->hasPermission('employee.sensitive.view')) {
            throw new RuntimeException('Writing to the personal e-mail needs access to sensitive personal data.');
        }
        $recipients = [];
        foreach ($to as $kind) {
            $address = $kind === 'work' ? $employee->work_email : $employee->person?->personal_email;
            if (filled($address) && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $recipients[mb_strtolower((string) $address)] = (string) $address;
            }
        }
        if ($recipients === []) {
            throw new RuntimeException('No '.implode(' or ', array_map(fn ($k) => mb_strtolower(self::RECIPIENTS[$k]), $to)).' on file.');
        }
        $cc = $this->copies($copies['cc'] ?? [], $recipients);
        $bcc = $this->copies($copies['bcc'] ?? [], $recipients + $cc);
        $max = max(0, (int) config('peopleos.letters.email_max_copies', 10));
        if (count($cc) + count($bcc) > $max) {
            throw new RuntimeException("At most {$max} copies (CC and BCC together).");
        }
        $subject = trim((string) ($subject ?: $letter->subject));
        $message = trim((string) ($message ?: "Please find attached your letter {$letter->number}: {$letter->subject}."));
        if ($subject === '' || mb_strlen($subject) > 200) {
            throw new RuntimeException('Give a subject of at most 200 characters.');
        }
        if (mb_strlen($message) > 5000) {
            throw new RuntimeException('Write a message of at most 5,000 characters.');
        }

        return DB::transaction(function () use ($letter, $actor, $employee, $to, $recipients, $cc, $bcc, $subject, $message) {
            $email = (new LetterEmail([
                'letter_id' => $letter->id, 'sent_by' => $actor->id, 'to_kinds' => $to, 'recipients' => array_values($recipients),
                'cc' => array_values($cc), 'bcc' => array_values($bcc), 'subject' => $subject,
            ]))->withoutAuditing();
            $email->save();
            $primary = array_values($recipients)[0];
            $delivery = NotificationDelivery::create([
                'user_id' => mb_strtolower((string) $employee->work_email) === mb_strtolower($primary) ? $employee->user_id : null,
                'channel' => 'email', 'event' => self::EVENT, 'subject' => $subject, 'body' => $message, 'status' => 'queued', 'recipient' => $primary,
                'source_type' => $email->getMorphClass(), 'source_id' => $email->getKey(), 'correlation_id' => Context::get('request_id'),
            ]);
            $email->forceFill(['notification_delivery_id' => $delivery->id])->saveQuietly();
            DeliverNotification::dispatch($delivery->id)->afterCommit();

            $this->audit->record(AuditAction::LetterEmailed, 'letters', $letter, [], null, actor: $actor, metadata: [
                'subject' => mb_substr($subject, 0, 200), 'to' => $to, 'cc' => count($cc), 'bcc' => count($bcc),
                'letter_email' => $email->id, 'delivery' => $delivery->id,
            ]);

            return $email;
        });
    }

    /** People of the organisation the sender may copy: active sign-ins with an e-mail. @return array<int, string> */
    public static function people(): array
    {
        return User::forCurrentTenant()->where('status', UserStatus::Active)->whereNotNull('email')->orderBy('name')->limit(500)->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} <{$u->email}>"])->all();
    }

    /**
     * @param  array{users?: list<int|string>, addresses?: list<string>}  $copy
     * @param  array<string, string>  $already  addresses already on the e-mail (lower-case => address)
     * @return array<string, string> lower-case => address
     */
    private function copies(array $copy, array $already): array
    {
        $out = [];
        $ids = array_values(array_unique(array_map('intval', array_filter((array) ($copy['users'] ?? []), 'is_numeric'))));
        if ($ids !== []) {
            $users = User::forCurrentTenant()->where('status', UserStatus::Active)->whereKey($ids)->get(['id', 'email']);
            if ($users->count() !== count($ids)) {
                throw new RuntimeException('Copies go only to active people of your organisation.');
            }
            foreach ($users as $user) {
                if (filled($user->email) && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                    $out[mb_strtolower($user->email)] = $user->email;
                }
            }
        }
        foreach ((array) ($copy['addresses'] ?? []) as $address) {
            $address = trim((string) $address);
            if ($address === '') {
                continue;
            }
            if (mb_strlen($address) > 254 || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("{$address} is not an e-mail address.");
            }
            $out[mb_strtolower($address)] = $address;
        }

        return array_diff_key($out, $already);
    }
}
