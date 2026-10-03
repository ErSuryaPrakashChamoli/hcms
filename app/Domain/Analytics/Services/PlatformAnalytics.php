<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Career\Models\CareerAspirationEntry;
use App\Domain\Career\Models\MobilityInterest;
use App\Domain\Communication\Models\AnnouncementRead;
use App\Domain\Communication\Models\CommunicationRecipient;
use App\Domain\Compensation\Services\CompensationAnalytics;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\EmployeeFeedback;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Services\LearningAnalytics;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Performance\Services\PerformanceAnalytics;
use App\Domain\ServiceDesk\Services\ServiceDeskAnalytics;
use App\Domain\Talent\Services\TalentAnalytics;
use App\Domain\Workforce\Services\WorkforceAnalytics;
use App\Filament\Pages\AttendanceExceptionCentre;
use App\Filament\Pages\CompensationAnalyticsPage;
use App\Filament\Pages\LearningDashboard;
use App\Filament\Pages\LeaveCalendar;
use App\Filament\Pages\PerformanceAnalyticsPage;
use App\Filament\Pages\ServiceDeskAnalyticsPage;
use App\Filament\Pages\SurveyResults;
use App\Filament\Pages\TalentAnalyticsPage;
use App\Filament\Pages\WorkforceAnalyticsPage;
use App\Filament\Pages\WorkforceCommandCentre;
use Illuminate\Support\Carbon;

/**
 * Phase 14 cross-domain analytics: one factual overview over the domains' own analytics services.
 *
 * - Each area is shown only to viewers holding that domain's analytics permission. Every figure comes
 *   from the owning domain: its analytics service where one exists, otherwise its own records in the
 *   viewer's organisation scope.
 * - Counts and shares over fewer than the privacy threshold are suppressed.
 * - Factual only. No per-person scores, rankings, risk, or predictions of attrition, promotion,
 *   performance or termination. No amounts outside the compensation area (whose service applies its
 *   own suppression).
 */
final class PlatformAnalytics
{
    /** area => [label, permissions (any), the owning domain's analytics page] */
    public const AREAS = [
        'people' => ['People', ['analytics.view', 'workforce.analytics'], WorkforceCommandCentre::class],
        'workforce' => ['Workforce', ['workforce.analytics'], WorkforceAnalyticsPage::class],
        'attendance' => ['Attendance (30 days)', ['attendance.view'], AttendanceExceptionCentre::class],
        'leave' => ['Leave', ['leave.view'], LeaveCalendar::class],
        'performance' => ['Performance', ['performance.analytics'], PerformanceAnalyticsPage::class],
        'learning' => ['Learning', ['learning.analytics'], LearningDashboard::class],
        'talent' => ['Career & talent', ['talent.analytics'], TalentAnalyticsPage::class],
        'compensation' => ['Compensation', ['compensation.analytics'], CompensationAnalyticsPage::class],
        'service' => ['HR service', ['servicedesk.analytics'], ServiceDeskAnalyticsPage::class],
        'engagement' => ['Engagement & communication', ['engagement.analytics'], SurveyResults::class],
    ];

    public function __construct(
        private readonly WorkforceMetrics $metrics,
        private readonly WorkforceAnalytics $workforce,
        private readonly PerformanceAnalytics $performance,
        private readonly LearningAnalytics $learning,
        private readonly TalentAnalytics $talent,
        private readonly CompensationAnalytics $compensation,
        private readonly ServiceDeskAnalytics $service,
    ) {}

    public function minGroup(): int
    {
        return max(1, (int) config('peopleos.analytics.min_group', 5));
    }

    public function allows(User $viewer, string $area): bool
    {
        foreach (self::AREAS[$area][1] ?? [] as $permission) {
            if ($viewer->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{key: string, label: string, facts: array<string, string|int|float|null>, page: class-string}> */
    public function overview(User $viewer, ?string $asOf = null): array
    {
        $day = Carbon::parse($asOf ?? now())->toDateString();
        $out = [];
        foreach (self::AREAS as $key => [$label, , $page]) {
            if ($this->allows($viewer, $key)) {
                $out[] = ['key' => $key, 'label' => $label, 'facts' => $this->{$key}($viewer, $day), 'page' => $page];
            }
        }

        return $out;
    }

    /** A count, or "fewer than k" when it describes a group below the threshold. */
    public function suppress(int $value): int|string
    {
        return $value > 0 && $value < $this->minGroup() ? 'fewer than '.$this->minGroup() : $value;
    }

    private function people(User $viewer, string $day): array
    {
        $headcount = $this->metrics->headcount($day);
        $from = Carbon::parse($day)->subDays(30)->toDateString();
        $states = Employee::query()->selectRaw('lifecycle_state as k, count(*) as n')->groupBy('lifecycle_state')->pluck('n', 'k');

        return [
            'Headcount on '.$day => $headcount,
            'Joiners (30 days)' => $this->suppress(Employee::query()->whereBetween('joining_date', [$from, $day])->whereNotIn('lifecycle_state', ['pre_employee', 'preboarding'])->count()),
            'Exits (30 days)' => $this->suppress(ExitCase::query()->where('status', 'completed')->whereBetween('last_working_day', [$from, $day])->count()),
            'Exits in progress' => $this->suppress(ExitCase::query()->whereIn('status', ExitCase::OPEN)->count()),
        ] + $states->mapWithKeys(fn ($n, $state) => ['Lifecycle: '.str_replace('_', ' ', (string) $state) => $this->suppress((int) $n)])->all();
    }

    private function workforce(User $viewer, string $day): array
    {
        $s = $this->workforce->summary($viewer, $day);

        $h = $s['headcount'];

        return ['Positions' => $h['positions'], 'Approved seats' => $h['approved_seats'], 'Occupied seats' => $h['occupied_seats'], 'Vacant seats' => $h['vacant_seats'],
            'Critical positions (active)' => $s['critical_positions']];
    }

    private function attendance(User $viewer, string $day): array
    {
        $rows = AttendanceRecord::query()->whereBetween('date', [Carbon::parse($day)->subDays(30)->toDateString(), $day])
            ->selectRaw('status as k, count(*) as n')->groupBy('status')->pluck('n', 'k');
        $people = AttendanceRecord::query()->whereBetween('date', [Carbon::parse($day)->subDays(30)->toDateString(), $day])->distinct()->count('employee_id');
        if ($people < $this->minGroup()) {
            return ['Note' => 'Fewer than '.$this->minGroup().' employees in scope; no figures are shown.'];
        }
        $scheduled = $rows->except(['weekly_off', 'holiday', 'not_processed'])->sum();

        return ['Employees with records' => $people, 'Present days' => (int) ($rows['present'] ?? 0), 'Absent days' => (int) ($rows['absent'] ?? 0),
            'Absenteeism (%)' => $scheduled > 0 ? round((int) ($rows['absent'] ?? 0) / $scheduled * 100, 1) : null];
    }

    private function leave(User $viewer, string $day): array
    {
        return [
            'Pending requests' => $this->suppress(LeaveRequest::query()->where('status', 'pending')->count()),
            'On leave on '.$day => $this->suppress(LeaveRequest::query()->where('status', 'approved')->whereDate('from_date', '<=', $day)->whereDate('to_date', '>=', $day)->count()),
            'Approved days this year' => (float) LeaveRequest::query()->where('status', 'approved')->whereYear('from_date', Carbon::parse($day)->year)->sum('days'),
        ];
    }

    private function performance(User $viewer, string $day): array
    {
        $o = $this->performance->summary()['overall'];
        if ($o['suppressed'] ?? false) {
            return ['Note' => 'Population below the privacy threshold; no figures are shown.'];
        }

        return ['Employees' => $o['employees'], 'Appraisal completion (%)' => $o['appraisal_completion'], 'Average goal progress (%)' => $o['average_goal_progress'], 'Check-ins (90 days)' => $o['check_ins_90_days']];
    }

    private function learning(User $viewer, string $day): array
    {
        $s = $this->learning->summary(false, Carbon::parse($day)->subYear(), $day);

        return ['Enrolments (12 months)' => $s['enrolments']['total'], 'Completion rate (%)' => $s['completion_rate'], 'Mandatory compliance (%)' => $s['mandatory']['compliance_rate'],
            'Mandatory overdue' => $this->suppress((int) $s['mandatory']['overdue']), 'Learning hours' => $s['learning_hours'], 'Certificates expiring (30 days)' => $s['certificates']['expiring_30']];
    }

    private function talent(User $viewer, string $day): array
    {
        $s = $this->talent->summary();

        return ['Critical positions' => $s['critical_positions']['active'], 'Succession coverage (%)' => $s['succession']['coverage_rate'], 'Ready-now coverage (%)' => $s['succession']['ready_now_rate'],
            'Current career aspirations' => $this->suppress(CareerAspirationEntry::query()->where('status', 'current')->count()),
            'Active mobility interests' => $this->suppress(MobilityInterest::query()->where('status', 'active')->count())];
    }

    private function compensation(User $viewer, string $day): array
    {
        $s = $this->compensation->summary($viewer, [], $day);
        if ($s['suppressed']) {
            return ['Note' => $s['note']];
        }

        return ['Population' => $s['population'], 'Basis' => $s['basis'], 'Detail' => 'Amounts, bands and range penetration are in Compensation analytics'];
    }

    private function service(User $viewer, string $day): array
    {
        $s = $this->service->summary($viewer, Carbon::parse($day)->subDays(90), $day);
        if ($s['suppressed']) {
            return ['Note' => $s['note']];
        }

        return ['Received (90 days)' => $s['received'], 'Open' => $s['open'], 'Overdue backlog' => $s['backlog_overdue'], 'SLA compliance (%)' => $s['sla_compliance_percent'],
            'Average resolution (hours)' => $s['average_resolution_hours']];
    }

    private function engagement(User $viewer, string $day): array
    {
        // Participation status is aggregated across all surveys; nothing is broken down per survey or person here.
        $invited = SurveyParticipation::query()->count();
        $submitted = SurveyParticipation::query()->where('status', 'submitted')->count();
        $since = Carbon::parse($day)->subDays(90);
        $ackRecipients = CommunicationRecipient::query()->where('status', '!=', 'skipped')->where('created_at', '>=', $since)
            ->whereHas('announcement', fn ($q) => $q->where('requires_acknowledgement', true))->count();
        $acknowledged = AnnouncementRead::query()->whereNotNull('acknowledged_at')->where('acknowledged_at', '>=', $since)->count();

        return [
            'Survey invitations' => $this->suppress($invited),
            'Survey response rate (%)' => $invited >= $this->minGroup() ? round($submitted / $invited * 100, 1) : null,
            'Feedback received (90 days)' => $this->suppress(EmployeeFeedback::query()->where('created_at', '>=', $since)->count()),
            'Acknowledgement rate, 90 days (%)' => $ackRecipients >= $this->minGroup() ? min(100.0, round($acknowledged / $ackRecipients * 100, 1)) : null,
        ];
    }
}
