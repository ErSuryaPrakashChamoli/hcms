<?php

namespace App\Domain\Experience\Services;

use App\Domain\Analytics\Models\Report;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\Letters\Models\Letter;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workforce\Models\Position;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\OrganisationMap;
use App\Filament\Pages\People;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Workflows\WorkflowResource;
use Illuminate\Support\Str;
use Throwable;

/**
 * UX: the Command Center's search (§10). One query, grouped results across people, actions, smart
 * answers, modules and records. Each group is gated by the same permission, scope and visibility
 * rules as the screen it opens; a group the viewer cannot use is simply absent. Results carry row
 * actions (open, preview, timeline, org, start an action). Nothing is cached across users.
 */
final class CommandSearch
{
    private const PER_GROUP = 6;

    public function __construct(
        private readonly PeopleVisibility $people,
        private readonly QuickActions $actions,
        private readonly IntentSearch $intents,
        private readonly ModuleCatalogue $modules,
        private readonly RoleLens $lenses,
    ) {}

    /**
     * @return list<array{key: string, label: string, items: list<array<string, mixed>>}>
     */
    public function search(User $user, string $query, string $mode = 'all'): array
    {
        $q = $this->clean($query);
        if ($q === '') {
            return $this->idle($user, $mode);
        }
        $groups = [];
        $add = function (string $key, string $label, callable $items) use (&$groups) {
            try {
                $rows = array_values(array_filter($items()));
            } catch (Throwable $e) {
                report($e);
                $rows = [];
            }
            if ($rows !== []) {
                $groups[] = ['key' => $key, 'label' => $label, 'items' => array_slice($rows, 0, self::PER_GROUP)];
            }
        };

        if ($mode === 'actions') {
            $add('actions', 'Actions', fn () => $this->matchActions($user, $q));

            return $groups;
        }
        if ($mode === 'people') {
            $add('people', 'People', fn () => $this->matchPeople($user, $q));

            return $groups;
        }

        $add('answers', 'Smart answers', fn () => array_map(fn ($a) => ['id' => 'answer:'.$a['key'], 'type' => 'answer', 'title' => $a['title'], 'subtitle' => $a['answer'],
            'icon' => 'heroicon-m-sparkles', 'url' => $a['url'], 'rows' => $a['rows']], $this->intents->answer($user, $q)));
        $add('actions', 'Actions', fn () => $this->matchActions($user, $q));
        $add('people', 'People', fn () => $this->matchPeople($user, $q));
        $add('modules', 'Go to', fn () => $this->matchModules($user, $q));
        $add('requests', 'Requests', fn () => $this->matchRequests($user, $q));
        $add('knowledge', 'Policies & knowledge', fn () => $this->matchKnowledge($user, $q));
        $add('documents', 'Documents', fn () => $this->matchDocuments($user, $q));
        $add('organisation', 'Organisation', fn () => $this->matchOrganisation($user, $q));
        $add('records', 'Workflows & reports', fn () => $this->matchRecords($user, $q));
        $add('services', 'HR services', fn () => $this->matchServices($user, $q));

        return $groups;
    }

    /** Before typing: recent items, then the viewer's most useful actions. */
    private function idle(User $user, string $mode): array
    {
        $groups = [];
        if ($mode !== 'actions') {
            $recent = $this->recent($user);
            if ($recent !== []) {
                $groups[] = ['key' => 'recent', 'label' => 'Recent', 'items' => $recent];
            }
        }
        $lens = $this->lenses->primary($user, app(ExperiencePreferences::class)->for($user)['lens'] ?? null);
        $all = $this->actions->for($user);
        usort($all, fn ($a, $b) => (int) ($b['lens'] === $lens) <=> (int) ($a['lens'] === $lens));
        $groups[] = ['key' => 'actions', 'label' => $mode === 'actions' ? 'Start something' : 'Suggested', 'items' => array_map(fn ($a) => $this->actionRow($a), array_slice($all, 0, $mode === 'actions' ? 12 : 6))];

        return array_values(array_filter($groups, fn ($g) => $g['items'] !== []));
    }

    /** @return list<array<string, mixed>> */
    private function recent(User $user): array
    {
        $rows = [];
        foreach (app(ExperiencePreferences::class)->for($user)['recent'] ?? [] as $r) {
            if (($r['type'] ?? null) === 'person') {
                // Re-resolve people so a recent item never outlives the viewer's access.
                $e = $this->people->query($user)->with(['person', 'currentPosition.designation', 'currentPosition.department'])->find((int) ($r['id'] ?? 0));
                if ($e !== null) {
                    $rows[] = $this->personRow($user, $e);
                }
            } elseif (isset($r['url'], $r['label'])) {
                $rows[] = ['id' => 'recent:'.md5($r['url']), 'type' => $r['type'] ?? 'page', 'title' => $r['label'], 'subtitle' => $r['meta'] ?? null, 'icon' => 'heroicon-m-clock', 'url' => $r['url']];
            }
            if (count($rows) >= 5) {
                break;
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function matchActions(User $user, string $q): array
    {
        $scored = [];
        foreach ($this->actions->for($user) as $a) {
            $score = $this->score($q, $a['label'], [...$a['keywords'], $a['verb'], $a['hint']]);
            if ($score > 0) {
                $scored[] = [$score, $a];
            }
        }
        usort($scored, fn ($x, $y) => $y[0] <=> $x[0]);

        return array_map(fn ($s) => $this->actionRow($s[1]), $scored);
    }

    /** @return list<array<string, mixed>> */
    private function matchPeople(User $user, string $q): array
    {
        if (mb_strlen($q) < 2) {
            return [];
        }
        $people = PeopleVisibility::matchName($this->people->query($user)->with(['person', 'currentPosition.designation', 'currentPosition.department']), $q)
            ->limit(self::PER_GROUP)->get();

        return $people->map(fn (Employee $e) => $this->personRow($user, $e))->all();
    }

    /** @return array<string, mixed> */
    public function personRow(User $user, Employee $e): array
    {
        $open = $this->people->canOpenProfile($user, $e);
        $profile = $open ? EmployeeResource::getUrl('view', ['record' => $e]) : null;
        $p = $e->currentPosition;
        $actions = array_values(array_filter([
            $open ? ['label' => 'Open profile', 'url' => $profile, 'key' => 'open'] : null,
            ['label' => 'Preview', 'drawer' => ['type' => 'person', 'id' => $e->id], 'key' => 'preview'],
            $open ? ['label' => 'Journey', 'url' => $profile.'#journey', 'key' => 'journey'] : null,
            OrganisationMap::canAccess() ? ['label' => 'In org map', 'url' => OrganisationMap::getUrl(['focus' => $e->id]), 'key' => 'org'] : null,
            $open && ($user->can('update', $e) || $user->hasPermission('employee.lifecycle') || $user->hasPermission('employee.position')) ? ['label' => 'Start a change', 'drawer' => ['type' => 'person-action', 'id' => $e->id], 'key' => 'action'] : null,
        ]));

        return [
            'id' => 'person:'.$e->id, 'type' => 'person', 'person_id' => $e->id,
            'title' => $e->display_name ?? $e->employee_code,
            'subtitle' => collect([$p?->designation?->name, $p?->department?->name])->filter()->implode(' · ') ?: $e->employee_code,
            'avatar' => $e->display_name, 'url' => $profile, 'drawer' => ['type' => 'person', 'id' => $e->id], 'actions' => $actions,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function matchModules(User $user, string $q): array
    {
        $nav = app(ExperienceNavigation::class);
        $scored = [];
        foreach ($this->modules->for($user) as $m) {
            $score = $this->score($q, (string) $m['label'], [(string) $m['group'], (string) $m['space']]);
            if ($score > 0) {
                $section = ExperienceNavigation::SECTIONS[$nav->sectionOf($m)]['label'] ?? '';
                $scored[] = [$score, ['id' => 'module:'.md5($m['key']), 'type' => 'module', 'title' => $m['label'], 'subtitle' => trim($section.' · '.$m['group'], ' ·'),
                    'icon' => is_string($m['icon']) ? $m['icon'] : 'heroicon-m-squares-2x2', 'url' => $m['url']]];
            }
        }
        usort($scored, fn ($x, $y) => $y[0] <=> $x[0]);

        return array_map(fn ($s) => $s[1], $scored);
    }

    /** @return list<array<string, mixed>> */
    private function matchRequests(User $user, string $q): array
    {
        $rows = [];
        $access = app(CaseAccess::class);
        $me = $this->lenses->employee($user);
        $tickets = $access->visible(Ticket::query()->with(['service', 'category']), $user)
            ->where(fn ($t) => $t->where('tickets.number', 'like', $q.'%')->orWhere('tickets.subject', 'like', '%'.$q.'%'));
        if (! $user->hasPermission('servicedesk.agent') && ! $user->hasPermission('servicedesk.view')) {
            $tickets->where('tickets.employee_id', $me?->id ?? 0);
        }
        foreach ($tickets->latest('tickets.id')->limit(4)->get() as $t) {
            $rows[] = ['id' => 'ticket:'.$t->id, 'type' => 'request', 'title' => $t->number.' · '.$t->serviceName(), 'subtitle' => Str::limit((string) $t->subject, 60).' · '.str_replace('_', ' ', (string) $t->status),
                'icon' => 'heroicon-m-lifebuoy', 'url' => rescue(fn () => TicketResource::canAccess() ? TicketResource::getUrl('view', ['record' => $t]) : MyHr::getUrl(['tab' => 'requests']), null, false)];
        }
        if (LetterResource::canAccess()) {
            foreach (Letter::query()->with('employee.person')->where('number', 'like', $q.'%')->limit(3)->get() as $l) {
                if ($user->can('view', $l)) {
                    $rows[] = ['id' => 'letter:'.$l->id, 'type' => 'request', 'title' => $l->number.' · '.($l->subject ?: 'Letter'), 'subtitle' => $l->employee?->display_name, 'icon' => 'heroicon-m-document-text', 'url' => LetterResource::getUrl('view', ['record' => $l])];
                }
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function matchKnowledge(User $user, string $q): array
    {
        if (mb_strlen($q) < 3) {
            return [];
        }
        $rows = [];
        if (ArticleResource::canAccess()) {
            foreach (Article::query()->where('title', 'like', '%'.$q.'%')->orderBy('title')->limit(4)->get() as $a) {
                $rows[] = ['id' => 'article:'.$a->id, 'type' => 'knowledge', 'title' => $a->title, 'subtitle' => 'Knowledge · '.(Article::STATUSES[$a->status] ?? $a->status), 'icon' => 'heroicon-m-book-open',
                    'url' => ArticleResource::getUrl('view', ['record' => $a])];
            }
        } elseif (($me = $this->lenses->employee($user)) !== null && MyHr::canAccess()) {
            foreach (app(KnowledgeBase::class)->visibleTo($me, $q)->take(4) as $a) {
                $rows[] = ['id' => 'article:'.$a->id, 'type' => 'knowledge', 'title' => $a->title, 'subtitle' => 'Policy · '.config('peopleos.kb.categories.'.$a->category, 'Knowledge'), 'icon' => 'heroicon-m-book-open',
                    'url' => MyHr::getUrl(['tab' => 'policies'])];
            }
        }
        if (PolicyResource::canAccess()) {
            foreach (Policy::query()->where('name', 'like', '%'.$q.'%')->limit(3)->get() as $p) {
                $rows[] = ['id' => 'policy:'.$p->id, 'type' => 'knowledge', 'title' => $p->name, 'subtitle' => 'Policy rule · '.$p->type, 'icon' => 'heroicon-m-scale', 'url' => PolicyResource::getUrl('edit', ['record' => $p])];
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function matchDocuments(User $user, string $q): array
    {
        if (mb_strlen($q) < 3) {
            return [];
        }
        $me = $this->lenses->employee($user);
        $rows = [];
        if ($me !== null && $user->hasPermission('document.own')) {
            foreach (EmployeeDocument::query()->where('employee_id', $me->id)->where('status', '!=', 'archived')->where('title', 'like', '%'.$q.'%')->limit(4)->get() as $d) {
                $rows[] = ['id' => 'doc:'.$d->id, 'type' => 'document', 'title' => $d->title, 'subtitle' => 'Your document'.($d->expires_on ? ' · expires '.$d->expires_on->format('d M Y') : ''), 'icon' => 'heroicon-m-document', 'url' => MyHr::getUrl(['tab' => 'documents'])];
            }
        }
        if ($user->hasPermission('document.view')) {
            $visible = $this->people->query($user)->select('employees.id');
            foreach (EmployeeDocument::query()->with('employee.person')->whereIn('employee_id', $visible)->where('employee_id', '!=', $me?->id ?? 0)->where('title', 'like', '%'.$q.'%')->limit(4)->get() as $d) {
                if ($d->employee !== null && $user->can('view', $d->employee)) {
                    $rows[] = ['id' => 'doc:'.$d->id, 'type' => 'document', 'title' => $d->title, 'subtitle' => $d->employee->display_name, 'icon' => 'heroicon-m-document', 'url' => EmployeeResource::getUrl('view', ['record' => $d->employee]).'#documents'];
                }
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function matchOrganisation(User $user, string $q): array
    {
        if (mb_strlen($q) < 2) {
            return [];
        }
        $rows = [];
        if (DepartmentResource::canAccess()) {
            foreach (Department::query()->where('name', 'like', '%'.$q.'%')->limit(3)->get() as $d) {
                $rows[] = ['id' => 'dept:'.$d->id, 'type' => 'organisation', 'title' => $d->name, 'subtitle' => 'Department', 'icon' => 'heroicon-m-building-office',
                    'url' => People::canAccess() ? People::getUrl(['department' => $d->id]) : DepartmentResource::getUrl('edit', ['record' => $d])];
            }
        }
        if (LocationResource::canAccess()) {
            foreach (Location::query()->where('name', 'like', '%'.$q.'%')->limit(2)->get() as $l) {
                $rows[] = ['id' => 'loc:'.$l->id, 'type' => 'organisation', 'title' => $l->name, 'subtitle' => 'Location'.($l->city ? ' · '.$l->city : ''), 'icon' => 'heroicon-m-map-pin',
                    'url' => People::canAccess() ? People::getUrl(['location' => $l->id]) : LocationResource::getUrl('edit', ['record' => $l])];
            }
        }
        if (PositionResource::canAccess()) {
            foreach (Position::query()->where(fn ($p) => $p->where('title', 'like', '%'.$q.'%')->orWhere('code', 'like', $q.'%'))->limit(3)->get() as $p) {
                $rows[] = ['id' => 'pos:'.$p->id, 'type' => 'organisation', 'title' => $p->title, 'subtitle' => 'Position '.$p->code.' · '.$p->status, 'icon' => 'heroicon-m-briefcase', 'url' => PositionResource::getUrl('view', ['record' => $p])];
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function matchRecords(User $user, string $q): array
    {
        if (mb_strlen($q) < 3) {
            return [];
        }
        $rows = [];
        if (WorkflowResource::canAccess()) {
            foreach (Workflow::query()->where('name', 'like', '%'.$q.'%')->limit(3)->get() as $w) {
                $rows[] = ['id' => 'workflow:'.$w->id, 'type' => 'workflow', 'title' => $w->name, 'subtitle' => 'Workflow · '.$w->status, 'icon' => 'heroicon-m-arrows-right-left', 'url' => WorkflowResource::getUrl('edit', ['record' => $w])];
            }
        }
        if (ReportResource::canAccess()) {
            foreach (Report::query()->where('name', 'like', '%'.$q.'%')->where(fn ($r) => $r->where('owner_id', $user->id)->orWhere('is_shared', true))->limit(3)->get() as $r) {
                $rows[] = ['id' => 'report:'.$r->id, 'type' => 'report', 'title' => $r->name, 'subtitle' => 'Report · '.$r->dataset, 'icon' => 'heroicon-m-chart-bar', 'url' => ReportResource::getUrl('view', ['record' => $r])];
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function matchServices(User $user, string $q): array
    {
        if (mb_strlen($q) < 3 || ! MyHr::canAccess() || ! $user->hasPermission('servicedesk.request')) {
            return [];
        }

        return ServiceDefinition::query()->where('status', 'active')->where('name', 'like', '%'.$q.'%')->limit(4)->get()
            ->map(fn ($s) => ['id' => 'service:'.$s->id, 'type' => 'service', 'title' => $s->name, 'subtitle' => 'Request this from HR', 'icon' => 'heroicon-m-paper-airplane', 'url' => MyHr::getUrl(['tab' => 'services'])])->all();
    }

    /** @return array<string, mixed> */
    private function actionRow(array $a): array
    {
        return ['id' => 'action:'.$a['key'], 'type' => 'action', 'title' => $a['label'], 'subtitle' => $a['hint'], 'icon' => $a['icon'], 'url' => $a['url'], 'verb' => $a['verb']];
    }

    /** Word-prefix scoring with synonyms (keywords). 0 = no match. */
    private function score(string $q, string $label, array $keywords): int
    {
        $label = mb_strtolower($label);
        $hay = $label.' '.mb_strtolower(implode(' ', $keywords));
        $score = 0;
        if (str_starts_with($label, $q)) {
            $score += 50;
        }
        if (str_contains($hay, $q)) {
            $score += 20;
        }
        foreach (preg_split('/\s+/', $q) as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/(^|\s|[-\/])'.preg_quote($token, '/').'/u', $hay)) {
                $score += 10;
            } else {
                return 0;
            }
        }

        return $score;
    }

    private function clean(string $query): string
    {
        return mb_strtolower(trim(preg_replace('/[%_\\\\]+/', ' ', mb_substr($query, 0, 80)) ?? ''));
    }
}
