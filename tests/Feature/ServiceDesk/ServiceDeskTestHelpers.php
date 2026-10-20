<?php

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Domain\Workflow\Models\Workflow;
use App\Support\Tenancy\TenantContext;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/** Phase 12: publish a knowledge article the way the product does — author submits, a second person approves, the author publishes. */
function kbPublishForTests(Article $article, ?User $author = null): Article
{
    $tenant = app(TenantContext::class)->current();
    $author ??= tenantUser($tenant, ['kb.manage', 'kb.view']);
    $reviewer = tenantUser($tenant, ['kb.review', 'kb.view']);
    $kb = app(KnowledgeBase::class);
    if ($article->status === 'published') {
        $kb->startRevision($article, $author);
    }
    $kb->submitForReview($article->refresh(), $author);
    $kb->review($article->refresh(), $reviewer, true, 'Looks right');

    return $kb->publish($article->refresh(), $author);
}

/**
 * Phase 12: an approved, active catalogue service (prepared by one person, approved by another).
 *
 * @param  array<string, mixed>  $version
 */
function sdApprovedService(string $code, array $version = [], string $category = 'OTHER'): ServiceDefinition
{
    $tenant = app(TenantContext::class)->current();
    $preparer = tenantUser($tenant, ['servicedesk.manage']);
    $approver = tenantUser($tenant, ['servicedesk.catalogue_approve']);
    $catalogue = app(ServiceCatalogue::class);
    $service = $catalogue->create(['code' => $code, 'name' => ucfirst(strtolower(str_replace('_', ' ', $code))), 'category' => $category, 'effective_from' => now()->toDateString()] + $version, $preparer);
    $draft = $service->versions()->first();
    $catalogue->submit($draft, $preparer);
    $catalogue->approve($draft->refresh(), $approver);

    return $service->refresh();
}

/** Phase 12: an active service-desk agent (works cases in scope) with optional extra permissions. */
function sdAgent(array $extra = []): User
{
    return tenantUser(app(TenantContext::class)->current(), ['servicedesk.view', 'servicedesk.agent', 'employee.view', ...$extra]);
}

/** Phase 12: a team (role) holding the agents; returns the role. */
function sdTeam(User ...$members): Role
{
    $role = Role::factory()->create(['name' => 'HR desk '.uniqid()]);
    $role->permissions()->sync(app(PermissionRegistry::class)->idsMatching(['servicedesk.view', 'servicedesk.agent']));
    foreach ($members as $member) {
        $member->roles()->attach($role);
    }

    return $role;
}

/** Phase 12: a one-step approval workflow decided by the given user. */
function sdApprovalWorkflow(User $approver, string $key = 'sd_approval'): Workflow
{
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve request', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $approver->id]]]]);

    return publishWorkflow($nodes, $edges, ['key' => $key, 'name' => 'Service approval '.$key]);
}
