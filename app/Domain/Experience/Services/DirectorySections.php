<?php

namespace App\Domain\Experience\Services;

use App\Domain\Attendance\Services\AttendanceGlance;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Services\Leaves;
use App\Domain\People\Models\PersonExperience;
use App\Domain\People\Models\PersonQualification;
use App\Domain\Performance\Models\Goal;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Skills\Services\SkillProfiles;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * HROne setup parity CA-6 (Core HR → Employee → Social profile access): the extra sections of a person's directory card.
 *
 * The organisation chooses the sections (`directory.sections_visible`; none = the card as before) and which of them a
 * person may hide from colleagues (`directory.sections_employee_may_hide`; the person's choice is `people.directory_hidden`,
 * set on Preferences). The card is shown only for people the viewer may already find (PeopleVisibility), and each section
 * is read through its owning domain:
 * - experience and education: the person's earlier employment and qualifications (People);
 * - skills: the current skill profile, names and levels (Skills, SkillProfiles);
 * - goals: only the person's goals the viewer may already open (Performance policy; sharing with colleagues is MS-6);
 * - planned leaves: approved leave in the next 60 days as "Away" dates, never the leave type or reason (Leave);
 * - last check-in and days off: the last punch time, holidays and week-offs for the coming week (Attendance).
 * A person always sees their own sections, hidden or not. Reads only.
 */
final class DirectorySections
{
    private const LIMIT = 5;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly PeopleVisibility $people,
    ) {}

    /** @return array<string, string> section key => label */
    public static function options(): array
    {
        return (array) config('peopleos.directory.sections', []);
    }

    /** @return list<string> the sections the organisation shows on directory cards, in catalogue order */
    public function shown(): array
    {
        $chosen = array_map('strval', (array) $this->settings->get('directory.sections_visible', []));

        return array_values(array_filter(array_keys(self::options()), fn (string $key) => in_array($key, $chosen, true)));
    }

    /** @return list<string> the shown sections a person may hide from colleagues */
    public function hideable(): array
    {
        $allowed = array_map('strval', (array) $this->settings->get('directory.sections_employee_may_hide', []));

        return array_values(array_filter($this->shown(), fn (string $key) => in_array($key, $allowed, true)));
    }

    /** @return list<string> the sections this person hides from colleagues (only those the organisation lets them hide) */
    public function hiddenBy(Employee $employee): array
    {
        $employee->loadMissing('person');
        $hidden = array_map('strval', (array) ($employee->person?->directory_hidden ?? []));

        return array_values(array_intersect($this->hideable(), $hidden));
    }

    /**
     * The extra sections of $employee's card as $viewer sees it; sections with nothing to show are left out.
     *
     * @return list<array{key: string, label: string, items: list<array{title: string, detail: ?string}>}>
     */
    public function for(User $viewer, Employee $employee): array
    {
        $shown = $this->shown();
        if ($shown === [] || ! $this->people->canSee($viewer, (int) $employee->getKey())) {
            return [];
        }
        $own = $employee->getAttribute('user_id') !== null && (int) $employee->getAttribute('user_id') === (int) $viewer->getKey();
        $hidden = $own ? [] : $this->hiddenBy($employee);
        $out = [];
        foreach ($shown as $key) {
            if (in_array($key, $hidden, true)) {
                continue;
            }
            try {
                $items = $this->items($key, $viewer, $employee);
            } catch (Throwable $e) {
                report($e);
                $items = [];
            }
            if ($items !== []) {
                $out[] = ['key' => $key, 'label' => self::options()[$key], 'items' => $items];
            }
        }

        return $out;
    }

    /** @return list<array{title: string, detail: ?string}> */
    private function items(string $key, User $viewer, Employee $employee): array
    {
        $today = now()->startOfDay();

        return match ($key) {
            'experience' => PersonExperience::query()->where('person_id', $employee->person_id)->orderByDesc('from_date')->limit(self::LIMIT)->get()
                ->map(fn (PersonExperience $x) => ['title' => collect([$x->designation, $x->employer])->filter()->implode(' · '),
                    'detail' => collect([$x->from_date?->format('M Y'), $x->to_date?->format('M Y') ?? ($x->from_date ? 'present' : null)])->filter()->implode(' – ') ?: null])
                ->values()->all(),
            'education' => PersonQualification::query()->where('person_id', $employee->person_id)->orderByDesc('year_of_completion')->limit(self::LIMIT)->get()
                ->map(fn (PersonQualification $q) => ['title' => collect([$q->qualification, $q->specialisation])->filter()->implode(', '),
                    'detail' => collect([$q->institution ?: $q->board_or_university, $q->year_of_completion])->filter()->implode(' · ') ?: null])
                ->values()->all(),
            'skills' => collect(app(SkillProfiles::class)->profile($employee))->filter(fn (array $s) => $s['skill'] !== null && $s['level'] !== null)->take(10)
                ->map(fn (array $s) => ['title' => (string) $s['skill'], 'detail' => $s['label']])->values()->all(),
            'goals' => Goal::query()->where('employee_id', $employee->getKey())->whereNotIn('status', ['cancelled', 'completed'])->orderBy('due_date')->limit(20)->get()
                ->filter(fn (Goal $g) => $viewer->can('view', $g))->take(self::LIMIT)
                ->map(fn (Goal $g) => ['title' => (string) $g->title, 'detail' => round((float) $g->progress).'% done'])->values()->all(),
            'planned_leaves' => app(Leaves::class)->approvedBetween($employee, $today, $today->copy()->addDays(60))->sortBy('from_date')->take(self::LIMIT)
                ->map(fn (LeaveRequest $r) => ['title' => 'Away', 'detail' => $r->from_date->isSameDay($r->to_date) ? $r->from_date->format('D j M') : $r->from_date->format('j M').' – '.$r->to_date->format('j M')])
                ->values()->all(),
            'last_checkin' => ($at = app(AttendanceGlance::class)->lastCheckIn($employee)) ? [['title' => $at->format('D j M, H:i'), 'detail' => $at->diffForHumans()]] : [],
            'off_days' => array_map(fn (array $d) => ['title' => $d['date']->format('D j M'), 'detail' => $d['label']], app(AttendanceGlance::class)->offDays($employee, Carbon::instance($today), 7)),
            default => [],
        };
    }
}
