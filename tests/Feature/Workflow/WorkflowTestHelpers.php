<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\Workflows;
use App\Support\Tenancy\TenantContext;

/** Build + publish a workflow from a compact node list. */
function publishWorkflow(array $nodes, array $edges, array $attributes = []): Workflow
{
    $workflow = Workflow::create(['name' => $attributes['name'] ?? 'Test flow', 'key' => $attributes['key'] ?? 'flow_'.uniqid(), 'trigger_event' => $attributes['trigger_event'] ?? 'manual'] + $attributes);
    app(Workflows::class)->draft($workflow, ['nodes' => $nodes, 'edges' => $edges]);
    app(Workflows::class)->publish($workflow);

    return $workflow->refresh();
}

/** An employee with a login and (optionally) a manager who also has a login. */
function employeeWithUser(?Employee $manager = null, array $userPermissions = ['task.view', 'task.act']): Employee
{
    $tenant = app(TenantContext::class)->current();
    $user = tenantUser($tenant, $userPermissions);
    $company = Company::query()->first() ?? Company::factory()->create();

    return app(HireEmployeeAction::class)->handle(
        ['first_name' => $user->name, 'last_name' => 'Emp'],
        ['joining_date' => '2025-01-01', 'work_email' => $user->email, 'user_id' => $user->id],
        ['company_id' => $company->id],
        $manager?->id,
    );
}

function linear(array $middle): array
{
    $nodes = [['id' => 'start', 'type' => 'start', 'name' => 'Start', 'config' => []], ...$middle, ['id' => 'end', 'type' => 'end', 'name' => 'End', 'config' => []]];
    $edges = [];
    $ids = array_column($nodes, 'id');

    for ($i = 0; $i < count($ids) - 1; $i++) {
        $edges[] = ['from' => $ids[$i], 'to' => $ids[$i + 1], 'label' => null];
    }

    return [$nodes, $edges];
}
