<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Models\CompanyEventAttendance;
use App\Domain\Engagement\Models\CompanyEventInvitation;
use App\Domain\Engagement\Models\CompanyEventSession;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity GO-15 (HROne reports "Event details", "Event attendance"; matrix 27 §27.13 #2): one row per invitation to
 * a company event — the event (type, sub-type, host, mode, first date, status), the invitee, how they were invited, their
 * answer and how many of the event's sessions they attended. For whoever runs events (engagement.events), in the reader's
 * access scope (invitations are scoped by employee). Drafts have no invitations, so they never appear.
 */
class EventParticipantsDataset extends Dataset
{
    /** @var array<string, array<string, string>> option list => code => label, read once per run */
    private array $labels = [];

    public function key(): string
    {
        return 'event_participants';
    }

    public function label(): string
    {
        return 'Event participants (invitations, RSVPs and attendance)';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['engagement.events'];
    }

    public function fields(): array
    {
        $event = fn (CompanyEventInvitation $i): ?CompanyEvent => $i->event;

        return [
            'event' => ['label' => 'Event', 'type' => 'string', 'value' => fn (CompanyEventInvitation $i) => $event($i)?->title],
            'event_type' => ['label' => 'Type of event', 'type' => 'string', 'value' => fn (CompanyEventInvitation $i) => $this->option('event_types', $event($i)?->event_type)],
            'event_sub_type' => ['label' => 'Type of sub event', 'type' => 'string', 'value' => fn (CompanyEventInvitation $i) => $this->option('event_sub_types', $event($i)?->event_sub_type)],
            'event_date' => ['label' => 'Event date', 'type' => 'date', 'value' => fn (CompanyEventInvitation $i) => $event($i)?->starts_at?->copy()->setTimezone($event($i)->timezone)->startOfDay()],
            'event_status' => ['label' => 'Event status', 'type' => 'string', 'value' => fn (CompanyEventInvitation $i) => $event($i)?->status],
            'mode' => ['label' => 'Mode', 'type' => 'string', 'value' => fn (CompanyEventInvitation $i) => $event($i)?->modeLabel()],
            'host' => ['label' => 'Host', 'type' => 'string', 'value' => fn (CompanyEventInvitation $i) => $event($i)?->host?->person?->full_name],
            'mandatory' => ['label' => 'Mandatory', 'type' => 'string', 'value' => fn (CompanyEventInvitation $i) => $event($i) === null ? null : ($event($i)->is_mandatory ? 'yes' : 'no')],
            ...$this->employeeFields(fn (CompanyEventInvitation $i) => $i->employee),
            'invitation' => ['label' => 'Nomination (answer)', 'type' => 'string', 'value' => fn (CompanyEventInvitation $i) => $i->status],
            'source' => ['label' => 'How invited', 'type' => 'string', 'value' => fn (CompanyEventInvitation $i) => CompanyEventInvitation::SOURCES[$i->source] ?? $i->source],
            'nominated_by' => ['label' => 'Nominated by', 'type' => 'string', 'value' => fn (CompanyEventInvitation $i) => $i->nominator?->person?->full_name],
            'answered_on' => ['label' => 'Answered on', 'type' => 'date', 'value' => fn (CompanyEventInvitation $i) => $i->responded_at],
            'sessions' => ['label' => 'Sessions', 'type' => 'number', 'value' => fn (CompanyEventInvitation $i) => (int) ($i->getAttributes()['sessions_total'] ?? 0)],
            'sessions_attended' => ['label' => 'Sessions attended', 'type' => 'number', 'value' => fn (CompanyEventInvitation $i) => (int) ($i->getAttributes()['sessions_attended'] ?? 0)],
            'attended' => ['label' => 'Attended', 'type' => 'string', 'value' => fn (CompanyEventInvitation $i) => (int) ($i->getAttributes()['sessions_attended'] ?? 0) > 0 ? 'yes' : 'no'],
            'count' => ['label' => 'Invitations (1 per row)', 'type' => 'number', 'value' => fn (CompanyEventInvitation $i) => 1],
        ];
    }

    private function option(string $list, ?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $this->labels[$list] ??= app(OptionLists::class)->labels($list);

        return $this->labels[$list][$code] ?? $code;
    }

    public function query(): Builder
    {
        $sessions = fn () => CompanyEventSession::query()->select('id')->whereColumn('company_event_sessions.event_id', 'company_event_invitations.event_id');

        return CompanyEventInvitation::query()->select('company_event_invitations.*')
            ->addSelect(['sessions_total' => CompanyEventSession::query()->selectRaw('count(*)')->whereColumn('company_event_sessions.event_id', 'company_event_invitations.event_id')])
            ->addSelect(['sessions_attended' => CompanyEventAttendance::query()->withoutGlobalScopes()->selectRaw('count(*)')
                ->whereColumn('company_event_attendances.employee_id', 'company_event_invitations.employee_id')->where('company_event_attendances.attended', true)
                ->whereIn('company_event_attendances.session_id', $sessions())])
            ->with(['event.host.person', ...self::employeeWith('employee'), 'nominator.person'])
            ->orderByDesc('event_id')->orderBy('id');
    }
}
