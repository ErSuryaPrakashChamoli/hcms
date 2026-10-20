<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Communication\Services\CommunicationPreferences;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\EngagementReminderLog;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Notifications\Services\Notifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Phase 13: survey invitations, reminders and closing reminders, sent through the existing Notifier
 * (in-app and email, subject to the employee's "survey" preference).
 *
 * Each notice is claimed once per participation and bucket in engagement_reminder_logs (unique key),
 * so repeated or concurrent runs never send twice. Reminders stop at the policy's maximum.
 *
 * Anonymous and confidential surveys remind EVERY invited person, whether or not they have
 * responded ("if you have already responded, thank you"). So the notification log reveals nothing
 * about who took part. Identified surveys remind only people who have not submitted. No notice
 * ever carries an answer.
 */
final class SurveyNotices
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly CommunicationPreferences $preferences,
        private readonly Surveys $surveys,
        private readonly AuditRecorder $audit,
    ) {}

    public function invite(SurveyVersion $version): int
    {
        if ($version->status !== 'open') {
            return 0;
        }
        $refs = $this->surveys->refs($version);
        $sent = $this->each($version, ['invited', 'opened', 'submitted'], 'survey.invitation', 'invitation', fn () => [
            'Survey: '.$refs['survey'],
            'You are invited to take part in "'.$refs['survey'].'"'.($refs['closes_at'] ? ' (closes on '.$refs['closes_at'].')' : '').'. Open My HR → Surveys. '.$this->modeLine($version),
        ], countAsReminder: false);
        if ($sent > 0) {
            $this->audit->record(AuditAction::SurveyInvitationSent, 'engagement', $version, [], null, metadata: ['invitations' => $sent]);
        }

        return $sent;
    }

    /** Reminders due on the day: "after N days" buckets, then one closing reminder. */
    public function remind(SurveyVersion $version, ?CarbonInterface $today = null): int
    {
        if ($version->status !== 'open' || $version->opened_at === null) {
            return 0;
        }
        $today = Carbon::parse($today ?? now())->startOfDay();
        $policy = $version->reminder_policy ?? config('peopleos.engagement.reminders');
        $buckets = [];
        foreach ((array) ($policy['after_days'] ?? []) as $days) {
            if ($version->opened_at->copy()->startOfDay()->addDays((int) $days)->lte($today)) {
                $buckets[] = 'after:'.(int) $days;
            }
        }
        if ($version->closes_at !== null && isset($policy['closing_days_before']) && $version->closes_at->copy()->startOfDay()->subDays((int) $policy['closing_days_before'])->lte($today)) {
            $buckets[] = 'closing';
        }
        $statuses = $version->isIdentified() ? ['invited', 'opened'] : ['invited', 'opened', 'submitted'];
        $refs = $this->surveys->refs($version);
        $sent = 0;
        foreach ($buckets as $bucket) {
            $sent += $this->each($version, $statuses, 'survey.reminder', $bucket, fn () => [
                ($bucket === 'closing' ? 'Closing soon: ' : 'Reminder: ').$refs['survey'],
                '"'.$refs['survey'].'" closes on '.$refs['closes_at'].'. '.($version->isIdentified() ? 'Please respond in My HR → Surveys.' : 'If you have already responded, thank you — nothing more is needed. '.$this->modeLine($version)),
            ], countAsReminder: true, max: (int) ($policy['max'] ?? 2));
        }

        return $sent;
    }

    private function modeLine(SurveyVersion $version): string
    {
        return match ($version->anonymity_mode) {
            'anonymous' => 'Responses are anonymous.',
            'confidential' => 'Responses are confidential.',
            default => 'Responses are identified.',
        };
    }

    private function each(SurveyVersion $version, array $statuses, string $event, string $bucket, callable $message, bool $countAsReminder, int $max = PHP_INT_MAX): int
    {
        $sent = 0;
        [$subject, $body] = $message();
        AccessScope::withoutScoping(function () use ($version, $statuses, $event, $bucket, $subject, $body, $countAsReminder, $max, &$sent) {
            SurveyParticipation::query()->where('survey_version_id', $version->id)->whereIn('status', $statuses)
                ->when($countAsReminder, fn ($q) => $q->where('reminders_sent', '<', $max))
                ->chunkById(500, function ($participations) use ($version, $event, $bucket, $subject, $body, $countAsReminder, &$sent) {
                    $employees = Employee::query()->whereIn('id', $participations->pluck('employee_id'))->get(['id', 'user_id'])->keyBy('id');
                    $users = User::query()->whereIn('id', $employees->pluck('user_id')->filter())->get()->keyBy('id');
                    foreach ($participations as $participation) {
                        $claimed = EngagementReminderLog::query()->insertOrIgnore([
                            'tenant_id' => $version->tenant_id, 'reminder' => $event, 'subject_type' => 'survey_participation', 'subject_id' => (string) $participation->id, 'bucket' => $bucket, 'created_at' => now(),
                        ]);
                        if ($claimed === 0) {
                            continue;
                        }
                        if ($countAsReminder) {
                            SurveyParticipation::query()->withoutGlobalScope(AccessScope::class)->whereKey($participation->id)->increment('reminders_sent');
                        }
                        $employee = $employees[$participation->employee_id] ?? null;
                        $user = $employee ? ($users[$employee->user_id] ?? null) : null;
                        if ($user === null || ! $user->isActive()) {
                            continue;
                        }
                        $channels = $this->preferences->channelsFor($employee, 'survey');
                        if ($channels !== []) {
                            $this->notifier->send([$user], $channels, $subject, $body, $event, $version);
                            $sent++;
                        }
                    }
                });
        });

        return $sent;
    }
}
