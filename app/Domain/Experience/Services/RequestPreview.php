<?php

namespace App\Domain\Experience\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\DescribesApprovalRoute;
use App\Domain\Experience\Contracts\RequestType;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Workflow\Support\RequestWorkflows;
use App\Filament\Resources\Articles\ArticleResource;
use Throwable;

/**
 * HROne parity RQ-2 (matrix 20 §20.1.1 #2, #3; §20.1.18 Leave #5): what the request chrome shows before a request is
 * raised — a READ-ONLY dry run. Nothing is started, created or written.
 *
 * - Who approves it: the deciding workflows that would start (RequestWorkflows::preview: first match by priority, start
 *   conditions, approval steps resolved through ApproverResolver), else the module's own approvers ("Leave approvers in
 *   the Approval Center"), naming only the line manager when they hold the module's approval right. A workflow whose
 *   start conditions depend on the request's own details is listed as one that "may" apply instead (an exceptional route).
 * - Who is informed: the audiences of the active notification rules on the request's raised events, as kinds of people
 *   ("Your manager", "Everyone with the role HR Manager", "A person chosen by HR") — never a list of names.
 * - Read the policy: published knowledge-base articles of the type's category that the viewer may read (their own
 *   audience rules), at most POLICIES.
 *
 * Approver names shown are the ones the requester sees once the request is raised (its timeline's "waiting with").
 */
final class RequestPreview
{
    public const POLICIES = 3;

    /** @var array<string, list<array{title: string, url: ?string}>> */
    private array $policies = [];

    /**
     * @return array{routes: list<array<string, mixed>>, definite: bool, built_in: ?string, manager: ?string, informed: list<string>, policies: list<array{title: string, url: ?string}>}
     */
    public function for(RequestType $type, User $viewer, ?Employee $employee): array
    {
        $route = $type instanceof DescribesApprovalRoute ? $type : null;
        $routes = [];
        if ($route !== null && $route->workflowSubject() !== null && $route->raisedEvents() !== []) {
            try {
                $routes = RequestWorkflows::preview((string) $route->workflowSubject(), $route->raisedEvents(), $employee, $viewer);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $definite = collect($routes)->contains(fn (array $r) => ! $r['conditional']);

        return [
            'routes' => $routes,
            'definite' => $definite,
            'built_in' => $definite ? null : ($route?->builtInApprovers() ?? null),
            'manager' => $definite || $route === null ? null : $this->managerHint($route, $employee),
            'informed' => $route !== null ? $this->informed($route->raisedEvents(), $viewer, $employee) : [],
            'policies' => $this->policies($type, $viewer),
        ];
    }

    /**
     * Published articles of the type's knowledge-base category the viewer may read (none without kb.view or an employee
     * record of their own).
     *
     * @return list<array{title: string, url: ?string}>
     */
    public function policies(RequestType $type, User $viewer): array
    {
        $category = $type instanceof DescribesApprovalRoute ? $type->knowledgeCategory() : null;
        if ($category === null || ! $viewer->hasPermission('kb.view')) {
            return [];
        }

        return $this->policies[$viewer->getKey().':'.$category] ??= rescue(function () use ($viewer, $category) {
            $me = app(CurrentEmployee::class)->of($viewer);
            if ($me === null) {
                return [];
            }
            $open = (int) auth()->id() === (int) $viewer->getKey() && ArticleResource::canAccess();

            return app(KnowledgeBase::class)->visibleTo($me, null, $category)->take(self::POLICIES)
                ->map(fn (Article $a) => ['title' => (string) $a->title, 'url' => $open ? ArticleResource::getUrl('view', ['record' => $a]) : null])->values()->all();
        }, [], true);
    }

    /** The line manager's name when they hold one of the module's approval rights (they usually decide it). */
    private function managerHint(DescribesApprovalRoute $route, ?Employee $employee): ?string
    {
        if ($employee === null || $route->approverPermissions() === []) {
            return null;
        }
        $employee->loadMissing('currentManager.manager.person');
        $manager = $employee->currentManager?->manager;
        $user = $manager?->user_id ? User::query()->forCurrentTenant()->where('status', UserStatus::Active->value)->find($manager->user_id) : null;
        if ($user === null || ! collect($route->approverPermissions())->contains(fn (string $p) => $user->hasPermission($p))) {
            return null;
        }

        return $manager->person?->display_name ?? $user->name;
    }

    /**
     * @param  list<string>  $events
     * @return list<string> who the active notification rules on the raised events inform, as kinds of people
     */
    private function informed(array $events, User $viewer, ?Employee $employee): array
    {
        if ($events === []) {
            return [];
        }
        $own = $employee !== null && (int) $employee->user_id === (int) $viewer->getKey();
        $labels = [];
        $rules = NotificationRule::query()->whereIn('event', $events)->where('status', 'active')
            ->whereHas('template', fn ($q) => $q->where('status', 'active'))->orderBy('id')->get();
        foreach ($rules as $rule) {
            $some = ! empty($rule->conditions) ? ' (in some cases)' : '';
            foreach ((array) ($rule->audience ?? []) as $spec) {
                $label = match ($spec['type'] ?? null) {
                    'subject' => $own ? 'You' : 'The employee',
                    'manager' => $own ? 'Your manager' : 'The employee\'s manager',
                    'initiator' => $own ? 'You' : 'Whoever raises it',
                    'assignee' => 'The approvers',
                    'role' => ($role = $this->role($spec)) !== null ? 'Everyone with the role '.$role->name : null,
                    'user' => 'A person chosen by HR',
                    default => null,
                };
                if ($label !== null) {
                    $labels[$label.$some] = $label.$some;
                }
            }
        }

        return array_values($labels);
    }

    /** @param  array<string, mixed>  $spec */
    private function role(array $spec): ?Role
    {
        return isset($spec['role_id']) ? Role::query()->find($spec['role_id']) : (isset($spec['role']) ? Role::query()->where('slug', $spec['role'])->first() : null);
    }
}
