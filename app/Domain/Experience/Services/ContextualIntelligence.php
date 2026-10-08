<?php

namespace App\Domain\Experience\Services;

use App\Domain\Ai\Services\AiGateway;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\WorkforceCommandCentre;
use App\Filament\Resources\Employees\EmployeeResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * UX.15 "PeopleOS Intelligence": contextual statements instead of a chatbot greeting. Each statement is
 * computed deterministically from data the viewer may already see in that context (the person workspace,
 * Home, the Approval Center), carries its source, and suggests a screen — never an action taken for the
 * person, and never an employment, pay or compensation recommendation.
 *
 * Shown only to viewers the existing AI policy allows (AiGateway::assistantsFor: the assistant permission
 * and feature flag for that context), and recorded through AiGateway::recordContext so contextual
 * answers are auditable like questions.
 */
final class ContextualIntelligence
{
    public function __construct(private readonly AiGateway $gateway, private readonly ApprovalCenter $approvals) {}

    /**
     * Intelligence for one person, from the person workspace the viewer already has.
     *
     * @param  array<string, mixed>  $workspace  PersonWorkspace::for()
     * @return array{items: list<array<string, mixed>>, context: string}|null
     */
    public function forPerson(User $viewer, Employee $employee, array $workspace): ?array
    {
        if ($workspace === [] || ! $this->allowed($viewer, ['hr', 'manager', 'employee'])) {
            return null;
        }
        $first = Str::before((string) $employee->display_name, ' ') ?: (string) $employee->display_name;
        $own = (int) $employee->user_id === (int) $viewer->id;
        $who = $own ? 'Your' : $first.'’s';
        $profile = EmployeeResource::getUrl('view', ['record' => $employee]);
        $items = [];

        if ($p = $workspace['now']['probation'] ?? null) {
            $items[] = $p['overdue']
                ? ['text' => $who.' probation ended '.abs($p['days']).' '.(abs($p['days']) === 1 ? 'day' : 'days').' ago, and no confirmation decision is recorded.', 'tone' => 'danger']
                : ['text' => $who.' probation ends in '.$p['days'].' '.($p['days'] === 1 ? 'day' : 'days').'. Confirmation is not recorded yet.', 'tone' => $p['days'] <= 30 ? 'warning' : 'info'];
            $items[array_key_last($items)] += ['source' => 'Lifecycle record · probation end date '.$p['ends']->format('j M Y'),
                'action' => ! $own && $viewer->can('transition', $employee) ? ['label' => 'Review confirmation', 'url' => $profile.'?action=lifecycle'] : null];
        }

        $decisions = $own ? collect() : $this->safe(fn () => $this->approvals->pendingAbout($viewer, (int) $employee->id), collect());
        if ($decisions->isNotEmpty()) {
            $d = $decisions->first();
            $items[] = ['text' => $decisions->count() === 1 ? $first.'’s '.mb_strtolower($d->title).' waits for your decision.' : $decisions->count().' requests from '.$first.' wait for your decision.',
                'source' => 'Approval Center', 'tone' => 'warning', 'action' => ['label' => 'Review', 'drawer' => ['type' => 'approval', 'id' => $d->id]]];
        }

        foreach ($workspace['next'] ?? [] as $n) {
            if (str_contains($n['title'], 'required onboarding')) {
                $items[] = ['text' => $n['title'].($n['detail'] ? ': '.mb_strtolower($n['detail']) : '').'.', 'source' => 'Onboarding plan', 'tone' => $n['tone'], 'action' => ['label' => 'See records', 'url' => $profile.'#records']];
            } elseif ($n['title'] === 'Last working day' && $n['date']) {
                $items[] = ['text' => ($own ? 'Your' : $first.'’s').' last working day is '.$n['date']->format('j M').' ('.$n['date']->diffForHumans().').', 'source' => 'Exit case', 'tone' => 'warning', 'action' => null];
            }
        }

        $recent = collect($workspace['changes'] ?? [])->first(fn ($c) => $c['date'] && $c['date']->gte(now()->subDays(14)));
        if ($recent) {
            $items[] = ['text' => 'Recently: '.$recent['title'].' ('.$recent['date']->diffForHumans().').', 'source' => 'Timeline · '.$recent['label'], 'tone' => 'info', 'action' => ['label' => 'Journey', 'url' => $profile.'#journey']];
        }

        return $this->finish($viewer, 'Employee 360 · '.$employee->employee_code, $items, 4);
    }

    /**
     * Intelligence for Home, from what Home already composed for this viewer.
     *
     * @param  array<string, mixed>  $home  HomeComposer::for()
     * @return array{items: list<array<string, mixed>>, context: string}|null
     */
    public function forHome(User $viewer, array $home): ?array
    {
        if (! $this->allowed($viewer, ['employee', 'manager', 'hr', 'workforce', 'policy'])) {
            return null;
        }
        $items = $this->roleStatements($home);
        $decisions = $home['decisions'] ?? collect();
        if (($home['decision_count'] ?? 0) > 0) {
            $oldest = collect($decisions)->filter(fn ($d) => $d->requestedAt !== null)->sortBy(fn ($d) => $d->requestedAt->timestamp)->first();
            $items[] = ['text' => $home['decision_count'].(($home['decision_more'] ?? false) ? '+' : '').' '.($home['decision_count'] === 1 ? 'decision waits' : 'decisions wait').' for you'.($oldest ? '; the oldest has waited '.$oldest->requestedAt->diffForHumans(null, true).'.' : '.'),
                'source' => 'Approval Center', 'tone' => 'warning', 'action' => Approvals::canAccess() ? ['label' => 'Review', 'url' => Approvals::getUrl()] : null];
        }
        if (($tp = $home['pulse_team'] ?? null) && ($tp['away'] ?? 0) > 0) {
            $items[] = ['text' => $tp['away'].' of your '.$tp['size'].' direct reports '.($tp['away'] === 1 ? 'is' : 'are').' away today.', 'source' => 'Approved leave', 'tone' => 'info', 'action' => null];
        }
        if (($c = $home['changes'] ?? null) && $c['count'] > 0) {
            $items[] = ['text' => ucfirst($c['summary']).' '.($c['since'] ? 'since your last visit.' : 'this week.'), 'source' => 'Change feed (what you can see)', 'tone' => 'info', 'action' => null];
        }
        if (($me = $home['me'] ?? null) && ($me['next_leave'] ?? null)) {
            $items[] = ['text' => 'Your next leave: '.$me['next_leave']['label'].' ('.$me['next_leave']['status'].').', 'source' => 'Your leave requests', 'tone' => 'info', 'action' => null];
        }

        return $this->finish($viewer, 'Home', $items, 3);
    }

    /**
     * UX.16: one statement for the person's own kind of work, from figures Home already composed for this viewer
     * (so nothing here is wider than what their Home shows): an employee's leave balance, a manager's team, HR
     * operations, the executive's workforce movement or the administrator's governance. Each names its source.
     *
     * @param  array<string, mixed>  $home
     * @return list<array<string, mixed>>
     */
    private function roleStatements(array $home): array
    {
        $first = fn (array $items) => $items[0] ?? null;
        $count = fn (array $s) => $s['count'] !== null ? $s['count'].' '.$s['title'] : $s['title'];

        return array_values(array_filter(match ($home['experience'] ?? 'employee') {
            'employee' => [($b = collect($home['me']['balances'] ?? [])->sortByDesc('total')->first())
                ? ['text' => 'You have '.rtrim(rtrim(number_format((float) $b['available'], 1), '0'), '.').' days of '.$b['name'].' left this year, of '.rtrim(rtrim(number_format((float) $b['total'], 1), '0'), '.').'.',
                    'source' => 'Your leave balances', 'tone' => 'info', 'action' => null] : null],
            'manager' => [($t = $first(array_values(array_filter($home['team_signals'] ?? [], fn ($s) => $s['key'] !== 'decisions'))))
                ? ['text' => ucfirst($count($t)).'.', 'source' => 'Your team (current reports)', 'tone' => $t['severity'] === 'danger' ? 'warning' : 'info', 'action' => $t['url'] ? ['label' => 'Open', 'url' => $t['url']] : null] : null],
            'hr' => [($o = $first($home['operations'] ?? []))
                ? ['text' => $o['count'].' '.mb_strtolower($o['title']).'. '.$o['why'], 'source' => 'People operations (your scope)', 'tone' => $o['severity'] === 'info' ? 'info' : 'warning', 'action' => $o['url'] ? ['label' => 'Open', 'url' => $o['url']] : null] : null],
            'executive' => [($w = $home['workforce'] ?? null)
                ? ['text' => $w['headline'], 'source' => 'Workforce pulse (positions, joiners, completed exits)', 'tone' => 'info', 'action' => WorkforceCommandCentre::canAccess() ? ['label' => 'Explore', 'url' => WorkforceCommandCentre::getUrl()] : null] : null],
            'admin' => [($g = $first($home['governance']['attention'] ?? []))
                ? ['text' => ucfirst($count($g)).'. '.$g['why'], 'source' => 'Governance (screens you administer)', 'tone' => $g['severity'] === 'info' ? 'info' : 'warning', 'action' => $g['url'] ? ['label' => 'Review', 'url' => $g['url']] : null] : null],
            default => [],
        }));
    }

    /**
     * Intelligence for the Approval Center queue.
     *
     * @param  Collection<int, ApprovalItem>  $pending
     * @return array{items: list<array<string, mixed>>, context: string}|null
     */
    public function forApprovals(User $viewer, Collection $pending): ?array
    {
        if ($pending->isEmpty() || ! $this->allowed($viewer, ['manager', 'hr'])) {
            return null;
        }
        $items = [];
        $soon = $pending->filter(fn ($i) => $i->effectiveOn !== null && $i->effectiveOn->lte(now()->addDays(7)))->count();
        if ($soon > 0) {
            $items[] = ['text' => $soon.' of '.$pending->count().' decisions take effect within a week.', 'source' => 'Approval Center · effective dates', 'tone' => 'warning', 'action' => null];
        }
        $first = $pending->first();
        if ($first && $first->subject) {
            $items[] = ['text' => 'Most urgent: '.$first->subject.'’s '.mb_strtolower($first->title).($first->effectiveOn ? ', from '.$first->effectiveOn->format('D j M') : '').($first->impact ? '. '.$first->impact.'.' : '.'),
                'source' => 'Approval Center', 'tone' => 'info', 'action' => null];
        }

        return $this->finish($viewer, 'Approval Center', $items, 3);
    }

    /** @param list<string> $assistants */
    private function allowed(User $viewer, array $assistants): bool
    {
        return array_intersect($assistants, array_keys($this->gateway->assistantsFor($viewer))) !== [];
    }

    /** @param list<array<string, mixed>> $items */
    private function finish(User $viewer, string $context, array $items, int $limit): ?array
    {
        $items = array_slice($items, 0, $limit);
        if ($items === []) {
            return null;
        }
        $this->safe(fn () => $this->gateway->recordContext($viewer, $context, $items), null);

        return ['items' => $items, 'context' => $context];
    }

    private function safe(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
