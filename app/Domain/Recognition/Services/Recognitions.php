<?php

namespace App\Domain\Recognition\Services;

use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\People\Services\Birthdays;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Recognition\Events\RecognitionEvent;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Models\Recognition;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * HROne setup parity HP-9c (Engage → Badges): giving badges, the monthly quota, custom badge rules, withdrawal,
 * visibility, the wall of fame, certificates and LinkedIn.
 *
 * - **Quota:** one person gives at most engage.badges.per_month badges in a calendar month on the tenant's clock
 *   (0 = no limit). Each giver's badges are serialised on their user row, so two at once cannot both take the last one.
 * - **Rules:** a badge is given only while allowed (status), a custom badge only while custom badges are allowed; the
 *   giver must hold one of its roles and match its giver conditions, the receiver its receiver conditions; a reason or
 *   an attachment when the badge asks for one; and the receiver may receive it at most receive_limit times per calendar
 *   or rolling period (or ever), checked under a lock on the receiver.
 * - **Visibility:** a public recognition is seen by everyone with recognition.view; a private one by the receiver, their
 *   manager, the giver and HR (recognition.manage, within their access scope). A withdrawn one leaves the wall.
 * - **Certificate:** the badge's letter template (else engage.badges.certificate_template) is generated for the receiver
 *   (source = the recognition) and issued at once when the template needs no approval. As with PolicyLetters, a missing
 *   template or a letter error never takes the badge back.
 */
final class Recognitions
{
    public const MESSAGE_MAX = 1000;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly TenantContext $tenants,
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
        private readonly Notifier $notifier,
        private readonly AccessScopes $scopes,
    ) {}

    /** Where attachments of recognitions are kept (DataInventory sweeps the folder with the tenant). */
    public static function directory(): string
    {
        return 'tenants/'.app(TenantContext::class)->id().'/recognition';
    }

    /** Now on the tenant's own clock. */
    public function now(): CarbonInterface
    {
        return Carbon::now($this->tenants->current()?->timezone ?: config('app.timezone'));
    }

    /** Badges one person may give in a calendar month (0 = no limit). */
    public function perMonth(): int
    {
        return max(0, (int) $this->settings->get('engage.badges.per_month', 10));
    }

    public function customAllowed(): bool
    {
        return (bool) $this->settings->get('engage.badges.custom_allowed', true);
    }

    /**
     * Badges $giver gave this calendar month (tenant clock); withdrawn ones do not count. $locking reads the latest
     * committed rows (MySQL repeatable read), as give() needs after waiting for the giver's lock.
     */
    public function givenThisMonth(User $giver, bool $locking = false): int
    {
        return Recognition::query()->withoutGlobalScope(AccessScope::class)->where('giver_user_id', $giver->getKey())->whereNull('withdrawn_at')
            ->where('created_at', '>=', $this->now()->startOfMonth()->setTimezone(config('app.timezone')))
            ->when($locking, fn (Builder $q) => $q->sharedLock())->count();
    }

    /** Badges $giver may still give this month (null = no limit). */
    public function quotaLeft(User $giver): ?int
    {
        $per = $this->perMonth();

        return $per === 0 ? null : max(0, $per - $this->givenThisMonth($giver));
    }

    /** Why $giver may not give $badge (null = they may). */
    public function refusalForGiver(Badge $badge, User $giver): ?string
    {
        if (! $giver->hasPermission('recognition.give')) {
            return 'You cannot give badges.';
        }
        if (! $badge->isActive()) {
            return 'The badge '.$badge->name.' is switched off.';
        }
        if (! $badge->is_system && ! $this->customAllowed()) {
            return 'Custom badges are switched off in this organisation.';
        }
        if ((array) $badge->giver_roles !== [] && array_intersect((array) $badge->giver_roles, $giver->roleSlugs()) === []) {
            return 'Only some roles may give the badge '.$badge->name.'.';
        }
        if ((array) $badge->givers !== []) {
            $me = app(RoleLens::class)->employee($giver);
            if ($me === null || ! $this->rules->matches((array) $badge->givers, $this->context->build($me))) {
                return 'You are not among the people who may give the badge '.$badge->name.'.';
            }
        }

        return null;
    }

    /** Why $receiver may not receive $badge from anyone (null = they may). */
    public function refusalForReceiver(Badge $badge, Employee $receiver): ?string
    {
        if (! in_array($receiver->lifecycle_state, Birthdays::STATES, true)) {
            return 'Badges go to colleagues who work here now.';
        }
        if ((array) $badge->receivers !== [] && ! $this->rules->matches((array) $badge->receivers, $this->context->build($receiver))) {
            return 'The badge '.$badge->name.' cannot be given to '.$receiver->person?->display_name.'.';
        }

        return null;
    }

    /** @return Collection<int, Badge> the badges $giver may give now, in display order */
    public function badgesFor(User $giver): Collection
    {
        return Badge::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (Badge $badge) => $this->refusalForGiver($badge, $giver) === null)->values();
    }

    /** The first moment of the receiving period of $badge (null = ever), in the application's timezone. */
    public function periodStart(Badge $badge): ?CarbonInterface
    {
        $now = $this->now();
        $rolling = $badge->receive_period_basis === 'rolling';
        $start = match ($badge->receive_period) {
            'month' => $rolling ? $now->copy()->subMonthNoOverflow() : $now->copy()->startOfMonth(),
            'quarter' => $rolling ? $now->copy()->subMonthsNoOverflow(3) : $now->copy()->startOfQuarter(),
            'year' => $rolling ? $now->copy()->subYearNoOverflow() : $now->copy()->startOfYear(),
            default => null,
        };

        return $start?->setTimezone(config('app.timezone'));
    }

    /**
     * $giver gives $badge to $receiver, with a reason and attachments (stored files in the tenant's recognition folder).
     *
     * @param  list<string>  $attachments
     */
    public function give(Badge $badge, Employee $receiver, User $giver, ?string $message = null, array $attachments = []): Recognition
    {
        $message = trim(strip_tags((string) $message));
        $receiver->loadMissing(['person', 'user']);
        if (($refusal = $this->refusalForGiver($badge, $giver) ?? $this->refusalForReceiver($badge, $receiver)) !== null) {
            throw new RuntimeException($refusal);
        }
        $giverEmployee = app(RoleLens::class)->employee($giver);
        if ((int) $receiver->user_id === (int) $giver->getKey() || $giverEmployee?->id === $receiver->id) {
            throw new RuntimeException('You cannot give yourself a badge.');
        }
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            throw new RuntimeException('Keep the message to '.self::MESSAGE_MAX.' characters.');
        }
        if ($badge->reason_required && $message === '') {
            throw new RuntimeException('Give the reason for the badge '.$badge->name.'.');
        }
        $attachments = $this->checkedAttachments($attachments);
        if ($badge->attachment_required && $attachments === []) {
            throw new RuntimeException('Attach a file to give the badge '.$badge->name.'.');
        }

        $recognition = DB::transaction(function () use ($badge, $receiver, $giver, $giverEmployee, $message, $attachments) {
            // The giver's row serialises their badges (quota); the receiver's row their receiving limit.
            User::query()->whereKey($giver->getKey())->lockForUpdate()->value('id');
            $per = $this->perMonth();
            if ($per > 0 && $this->givenThisMonth($giver, locking: true) >= $per) {
                throw new RuntimeException("You have given your {$per} badges for this month.");
            }
            if ($badge->receive_limit > 0) {
                Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($receiver->id)->lockForUpdate()->value('id');
                $start = $this->periodStart($badge);
                $received = Recognition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $receiver->id)->where('badge_id', $badge->id)->whereNull('withdrawn_at')
                    ->when($start !== null, fn (Builder $q) => $q->where('created_at', '>=', $start))->sharedLock()->count();
                if ($received >= $badge->receive_limit) {
                    $when = $badge->receive_period === 'ever' ? '' : ($badge->receive_period_basis === 'rolling' ? ' in the last '.$badge->receive_period : ' this '.$badge->receive_period);
                    throw new RuntimeException($receiver->person->display_name.' has already received the badge '.$badge->name.' '.($badge->receive_limit === 1 ? 'once' : $badge->receive_limit.' times').$when.'.');
                }
            }

            return Recognition::query()->create([
                'badge_id' => $badge->id, 'employee_id' => $receiver->id, 'giver_user_id' => $giver->getKey(), 'giver_employee_id' => $giverEmployee?->id,
                'message' => $message === '' ? null : $message, 'visibility' => $badge->visibility, 'attachments' => $attachments === [] ? null : $attachments,
            ]);
        });

        $recipient = $receiver->user;
        if ($recipient !== null && $recipient->isActive()) {
            $this->notifier->send([$recipient], ['in_app'], $giver->name.' gave you the badge '.$badge->name, $message !== '' ? $message : 'Well done!', 'recognition.received', $recognition);
        }
        $this->issueCertificate($recognition, $badge, $receiver, $giver);
        // HROne setup parity HP-9d / HP-9e: the badge's reward points and the social wall post follow the recognition.
        RecognitionEvent::dispatch('recognition.given', $recognition, $receiver, ['badge_id' => $badge->id, 'visibility' => $recognition->visibility]);

        return $recognition;
    }

    /** HR withdraws a recognition once (with the reason): it leaves the wall, the quota and the counts. */
    public function withdraw(Recognition $recognition, User $actor, string $reason): Recognition
    {
        if (! $actor->hasPermission('recognition.manage')) {
            throw new RuntimeException('Only HR can withdraw a badge.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for withdrawing the badge.');
        }

        $row = DB::transaction(function () use ($recognition, $actor, $reason) {
            $row = Recognition::query()->withoutGlobalScope(AccessScope::class)->whereKey($recognition->getKey())->lockForUpdate()->firstOrFail();
            if ($row->isWithdrawn()) {
                throw new RuntimeException('This badge was already withdrawn.');
            }
            $row->withAuditReason($reason)->update(['withdrawn_at' => now(), 'withdrawn_by' => $actor->getKey(), 'withdraw_reason' => mb_substr(trim($reason), 0, 255)]);

            return $row;
        });
        // HP-9d / HP-9e: the badge's points go back to the budget and its wall post is taken down.
        RecognitionEvent::dispatch('recognition.withdrawn', $row, null, ['actor_id' => $actor->getKey()]);

        return $row;
    }

    /**
     * The recognitions $viewer may see: HR (recognition.manage) every one in their access scope; others the public ones
     * (not withdrawn) plus those they gave, received, or that their direct reports received.
     *
     * @return Builder<Recognition>
     */
    public function visibleTo(User $viewer): Builder
    {
        $query = Recognition::query()->with(['badge', 'employee.person', 'giver', 'giverEmployee.person', 'certificate']);
        if ($this->scopes->isScoped($viewer) && $viewer->getKey() !== auth()->id()) {
            $query = $this->scopes->constrainByEmployee($query, $viewer);
        }
        if ($viewer->hasPermission('recognition.manage')) {
            return $query;
        }
        if (! $viewer->hasPermission('recognition.view') && ! $viewer->hasPermission('recognition.give')) {
            return $query->whereRaw('1 = 0');
        }
        $me = app(RoleLens::class)->employee($viewer);
        $reports = $me === null ? [] : $me->directReports()->currentlyEffective()->pluck('employee_id')->map(fn ($id) => (int) $id)->all();

        return $query->where(fn (Builder $q) => $q->where(fn (Builder $p) => $p->where('visibility', 'public')->whereNull('withdrawn_at'))
            ->orWhere('giver_user_id', $viewer->getKey())
            ->orWhere('employee_id', $me?->id ?? 0)
            ->when($reports !== [], fn (Builder $r) => $r->orWhereIn('employee_id', $reports)));
    }

    public function canSee(User $viewer, Recognition $recognition): bool
    {
        return $this->visibleTo($viewer)->whereKey($recognition->getKey())->exists();
    }

    /**
     * The wall of fame (EN-03): the people with the most public badges this calendar month (tenant clock), withdrawn
     * ones not counted, with their latest badge.
     *
     * @return list<array{employee: Employee, count: int, badge: ?Badge}>
     */
    public function wallOfFame(User $viewer, int $limit = 5): array
    {
        if (! $viewer->hasPermission('recognition.view') && ! $viewer->hasPermission('recognition.manage')) {
            return [];
        }
        $rows = Recognition::query()->where('visibility', 'public')->whereNull('withdrawn_at')
            ->where('created_at', '>=', $this->now()->startOfMonth()->setTimezone(config('app.timezone')))
            ->selectRaw('employee_id, count(*) as received, max(id) as latest')->groupBy('employee_id')
            ->orderByDesc('received')->orderByDesc('latest')->limit($limit)->toBase()->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $employees = Employee::query()->with(['person', 'currentPosition.designation'])->whereKey($rows->pluck('employee_id')->all())->get()->keyBy('id');
        $latest = Recognition::query()->with('badge')->whereKey($rows->pluck('latest')->all())->get()->keyBy('id');

        return $rows->filter(fn ($row) => $employees->has($row->employee_id))
            ->map(fn ($row) => ['employee' => $employees->get($row->employee_id), 'count' => (int) $row->received, 'badge' => $latest->get($row->latest)?->badge])
            ->values()->all();
    }

    /** The certificate template of $badge: its own, else the organisation's default (engage.badges.certificate_template). */
    public function certificateTemplate(Badge $badge): ?LetterTemplate
    {
        $own = $badge->certificate_template_id ? LetterTemplate::query()->where('status', 'active')->find($badge->certificate_template_id) : null;
        $code = $this->settings->get('engage.badges.certificate_template');

        return $own ?? (filled($code) ? LetterTemplate::query()->where('status', 'active')->where('code', (string) $code)->first() : null);
    }

    /**
     * The variables a certificate gets besides the employee's: {{ badge.name }}, {{ badge.description }},
     * {{ recognition.reference }}, {{ recognition.date }}, {{ recognition.given_by }}, {{ recognition.reason }}.
     *
     * @return array<string, array<string, ?string>>
     */
    public static function certificateVariables(Recognition $recognition, Badge $badge, User $giver): array
    {
        return [
            'badge' => ['name' => $badge->name, 'description' => $badge->description],
            'recognition' => ['reference' => $recognition->reference(), 'date' => $recognition->created_at?->format('d M Y'), 'given_by' => $giver->name, 'reason' => $recognition->message],
        ];
    }

    /**
     * "Share on LinkedIn" (engage.badges.linkedin_share): LinkedIn's own "Add to profile" link for a certification, filled
     * with the badge, the organisation, the month and the reference (no LinkedIn key and no public page). Only the receiver
     * of a public badge that was not withdrawn gets one.
     */
    public function linkedInUrl(Recognition $recognition, User $viewer): ?string
    {
        $recognition->loadMissing(['badge', 'employee']);
        if (! (bool) $this->settings->get('engage.badges.linkedin_share', false) || $recognition->visibility !== 'public' || $recognition->isWithdrawn()
            || (int) $recognition->employee?->user_id !== (int) $viewer->getKey()) {
            return null;
        }
        $given = $recognition->created_at ?? now();

        return 'https://www.linkedin.com/profile/add?'.http_build_query([
            'startTask' => 'CERTIFICATION_NAME',
            'name' => $recognition->badge->name,
            'organizationName' => $this->settings->get('branding.display_name') ?: $this->tenants->current()?->name,
            'issueYear' => $given->year,
            'issueMonth' => $given->month,
            'certId' => $recognition->reference(),
        ]);
    }

    private function issueCertificate(Recognition $recognition, Badge $badge, Employee $receiver, User $giver): ?Letter
    {
        try {
            $template = $this->certificateTemplate($badge);
            if ($template === null) {
                return null;
            }
            $letters = app(Letters::class);
            $letter = $letters->generate($template, $receiver, self::certificateVariables($recognition, $badge, $giver), $giver, $recognition);

            return $letter->status === 'approved' ? $letters->issue($letter, $giver) : $letter;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string> the files, each stored in the tenant's recognition folder
     */
    private function checkedAttachments(array $paths): array
    {
        $paths = array_values(array_filter(array_map('strval', $paths)));
        $disk = Storage::disk(config('peopleos.documents.disk', 'local'));
        $prefix = self::directory().'/';
        foreach ($paths as $path) {
            if (! str_starts_with($path, $prefix) || str_contains($path, '..') || ! $disk->exists($path)) {
                throw new RuntimeException('Attach the file again: it could not be found.');
            }
        }

        return array_slice($paths, 0, 3);
    }
}
