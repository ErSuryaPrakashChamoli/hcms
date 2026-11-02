<?php

namespace App\Domain\Employment\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Notifications\Jobs\DeliverNotification;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Domain\Onboarding\Support\ProfileSections;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * HROne parity WO-4 (§23.3.1 #8, "Share for profile completion" mailer): HR writes to an upcoming joiner, at their personal
 * e-mail, listing the profile details and joining documents still missing.
 *
 * - Off unless the organisation switches `employee.joining.profile_completion_mail` on, and only by hand (no rule or schedule
 *   sends it). Needs employee.message and the joiner's `view`; the joiner must still be an upcoming joiner.
 * - It carries NO sign-in link: joiners have no access before day one (owner decision WRK-7 / D-WO11 open). The template's
 *   variables leave out the application's address, the rendered mail is refused if it still names it, and it goes as plain
 *   text without the mail layout (whose header links to the application; peopleos.notifications.plain_text_events).
 * - Subject and body come from the notification template `employee_profile_completion_requested` when the organisation has
 *   an active one (Notification templates), otherwise from the built-in text (peopleos.lifecycle.joiners.profile_completion).
 *   Variables: employee.*, tenant.name, initiator.name, joiner.expected_joining_date, joiner.missing (one line per item),
 *   joiner.note.
 * - Sent through the notification delivery log (event employee.profile_completion_requested, one delivery per address,
 *   delivered after commit) and audited on the employee as EMPLOYEE_EMAILED (purpose profile_completion, the items asked
 *   for, the kinds of address) — never the body or an address.
 */
final class ProfileCompletionMails
{
    public const EVENT = 'employee.profile_completion_requested';

    public const TEMPLATE_KEY = 'employee_profile_completion_requested';

    public const RECIPIENTS = ['personal' => 'Personal e-mail', 'work' => 'Work e-mail'];

    public function __construct(private readonly AuditRecorder $audit, private readonly TemplateRenderer $renderer, private readonly NotificationContext $context) {}

    public static function enabled(): bool
    {
        return (bool) app(SettingsRepository::class)->get('employee.joining.profile_completion_mail', false);
    }

    public static function mayUse(?User $user): bool
    {
        return $user !== null && self::enabled() && $user->hasPermission('employee.message');
    }

    /**
     * @param  list<string>  $sections  profile section keys (peopleos.lifecycle.joiners.profile_sections)
     * @param  list<int|string>  $documentTypeIds  document types asked for
     * @param  list<string>  $to  personal and/or work
     * @param  list<int|string>  $ccEmployeeIds  colleagues copied (their work e-mail, inside the sender's access)
     * @return int the number of e-mails queued
     */
    public function send(Employee $employee, User $actor, array $sections, array $documentTypeIds = [], array $to = ['personal'], ?string $note = null, array $ccEmployeeIds = [], bool $copyToMe = false): int
    {
        if (! self::enabled()) {
            throw new RuntimeException('Profile-completion mails are switched off (Setup → Workforce).');
        }
        if (! $actor->hasPermission('employee.message') || Gate::forUser($actor)->denies('view', $employee)) {
            throw new RuntimeException('You cannot write to this employee.');
        }
        if (! $employee->lifecycle_state->isPreJoining()) {
            throw new RuntimeException('Only an upcoming joiner is asked to complete their profile.');
        }
        if (! config('peopleos.notifications.channels.email')) {
            throw new RuntimeException('E-mail is not available.');
        }
        $allowed = (array) config('peopleos.lifecycle.joiners.profile_sections', []);
        $sections = array_values(array_intersect($allowed, array_map('strval', $sections)));
        $documents = DocumentType::query()->where('status', 'active')->whereKey(array_values(array_filter(array_map('intval', $documentTypeIds))))->orderBy('name')->pluck('name')->map(fn ($n) => (string) $n)->all();
        if ($sections === [] && $documents === []) {
            throw new RuntimeException('Choose at least one detail or document to ask for.');
        }
        $note = trim((string) $note);
        if (mb_strlen($note) > 2000) {
            throw new RuntimeException('Keep the note to 2,000 characters.');
        }

        $employee->loadMissing('person');
        $to = array_values(array_intersect(array_keys(self::RECIPIENTS), $to)) ?: ['personal'];
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
        $scopes = app(AccessScopes::class);
        foreach (Employee::query()->whereKey(array_values(array_filter(array_map('intval', $ccEmployeeIds))))->get() as $colleague) {
            if ($scopes->allows($actor, $colleague) && filled($colleague->work_email) && filter_var($colleague->work_email, FILTER_VALIDATE_EMAIL)) {
                $addresses[mb_strtolower((string) $colleague->work_email)] ??= [(string) $colleague->work_email, 'copy'];
            }
        }
        if ($copyToMe && filled($actor->email)) {
            $addresses[mb_strtolower((string) $actor->email)] ??= [(string) $actor->email, 'copy'];
        }

        $missing = [...array_map(fn (string $s) => ProfileSections::label($s), $sections), ...array_map(fn (string $d) => $d.' (document)', $documents)];
        [$subject, $body] = $this->render($employee, $actor, $missing, $note);

        $deliveries = [];
        foreach ($addresses as [$address, $kind]) {
            $delivery = NotificationDelivery::create([
                'user_id' => $kind === 'copy' ? User::forCurrentTenant()->where('email', $address)->value('id') : null,
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
            'purpose' => 'profile_completion',
            'subject' => mb_substr($subject, 0, 200),
            'sections' => $sections,
            'documents' => $documents,
            'to' => $to,
            'copies' => count(array_filter($addresses, fn (array $a) => $a[1] === 'copy')),
            'deliveries' => $deliveries,
        ], actor: $actor);

        return count($deliveries);
    }

    /**
     * Subject and body for the joiner, without any link to the application.
     *
     * @param  list<string>  $missing
     * @return array{0: string, 1: string}
     */
    public function render(Employee $employee, User $actor, array $missing, string $note = ''): array
    {
        $template = NotificationTemplate::query()->where('key', self::TEMPLATE_KEY)->where('status', ActiveStatus::Active)->first();
        $subject = $template?->subject ?? (string) config('peopleos.lifecycle.joiners.profile_completion.subject');
        $body = $template?->body ?? (string) config('peopleos.lifecycle.joiners.profile_completion.body');
        $expected = UpcomingJoiners::expectedDate($employee);
        $context = $this->context->build($employee, [
            'joiner' => [
                'expected_joining_date' => $expected?->format('d M Y') ?? 'your joining date',
                'missing' => implode("\n", array_map(fn (string $item) => '- '.$item, $missing)),
                'note' => $note,
            ],
        ], $actor);
        // No address of the application reaches the joiner (no sign-in before day one).
        unset($context['app']);
        $subject = trim($this->renderer->render($subject, $context));
        $body = trim((string) preg_replace("/\n{3,}/", "\n\n", $this->renderer->render($body, $context)));
        if ($subject === '' || $body === '') {
            throw new RuntimeException('The profile-completion template has no subject or body.');
        }
        foreach ($this->applicationHosts() as $host) {
            if (str_contains(mb_strtolower($subject.' '.$body), $host)) {
                throw new RuntimeException('The profile-completion mail cannot carry a link to PeopleOS: joiners have no access before day one. Remove it from the template.');
            }
        }

        return [mb_substr($subject, 0, 200), $body];
    }

    /** @return list<string> the application's own host names (lower case) */
    private function applicationHosts(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($url) => is_string($url) ? mb_strtolower((string) parse_url($url, PHP_URL_HOST)) : null,
            [config('app.url'), url('/')],
        ))));
    }
}
