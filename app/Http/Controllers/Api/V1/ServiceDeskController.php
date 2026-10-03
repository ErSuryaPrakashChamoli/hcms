<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Employment\Models\Employee;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketComment;
use App\Domain\ServiceDesk\Models\TicketTransition;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Domain\ServiceDesk\Services\ServiceForms;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 12 HR service desk API (`/api/v1/service-desk/*`, scope servicedesk.read). Read-only: requests
 * are raised and worked in PeopleOS, where the same authorisation, workflow and separation of duties
 * apply. Profile changes have no API write path; a future write API must call the same domain actions.
 *
 * What an integration never receives:
 * - restricted (confidential) cases — they do not exist here: 404, as for another tenant's ids;
 * - drafts;
 * - internal or restricted notes, or comments on sensitive cases;
 * - attachments;
 * - free-text subjects, descriptions or resolutions;
 * - sensitive or HR-only form fields;
 * - workflow internals.
 */
class ServiceDeskController extends Controller
{
    use PaginatesApi;

    public function services(Request $request, ServiceCatalogue $catalogue, ServiceForms $forms): JsonResponse
    {
        $query = ServiceDefinition::query()->with('category')->where('status', 'active')->orderBy('sort_order')->orderBy('code');

        return $this->page($query, $request, function (ServiceDefinition $s) use ($catalogue, $forms) {
            $v = $catalogue->versionOn($s);

            return [
                'code' => $s->code, 'name' => $s->name, 'category' => $s->category?->code, 'subcategory' => $s->subcategory,
                'version' => $v ? [
                    'version' => $v->version, 'effective_from' => $v->effective_from->toDateString(), 'effective_to' => $v->effective_to?->toDateString(),
                    'available_to' => collect($v->availability ?? ['employee' => true, 'hr' => true])->filter()->keys()->values()->all(),
                    'attachment_rule' => $v->attachment_rule, 'approval_required' => $v->approval_required, 'domain_action' => $v->domain_action,
                    'confidentiality' => $v->confidentiality,
                    'fields' => collect($forms->fields($v))->map(fn (array $f, string $key) => ['key' => $key, 'label' => $f['label'], 'type' => $f['type'], 'required' => $f['required'], 'classification' => $f['class']])->values()->all(),
                ] : null,
            ];
        });
    }

    public function requests(Request $request): JsonResponse
    {
        $query = $this->base()->with(['service', 'category', 'employee'])
            ->when($request->query('status'), fn (Builder $q, $s) => $q->whereIn('tickets.status', explode(',', (string) $s)))
            ->when($request->query('service'), fn (Builder $q, $c) => $q->whereIn('tickets.service_definition_id', ServiceDefinition::query()->select('id')->where('code', strtoupper((string) $c))))
            ->when($request->query('employee'), fn (Builder $q, $c) => $q->whereIn('tickets.employee_id', Employee::query()->select('id')->where('employee_code', (string) $c)))
            ->when($request->query('from'), fn (Builder $q, $d) => $q->whereDate('tickets.created_at', '>=', $d))
            ->when($request->query('to'), fn (Builder $q, $d) => $q->whereDate('tickets.created_at', '<=', $d));
        $this->sorted($query, $request, ['created_at' => 'tickets.created_at', 'status' => 'tickets.status', 'priority' => 'tickets.priority'], '-id');

        return $this->page($query, $request, fn (Ticket $t) => $this->summary($t));
    }

    public function request(string $number, CaseAccess $access): JsonResponse
    {
        $ticket = $this->find($number);

        return response()->json(['data' => $this->summary($ticket) + [
            'form' => $access->apiFormData($ticket),
            'history' => TicketTransition::query()->where('ticket_id', $ticket->id)->orderBy('id')->get(['to_status', 'created_at'])->map(fn (TicketTransition $t) => ['status' => $t->to_status, 'at' => $t->created_at?->toIso8601String()])->all(),
        ]]);
    }

    public function comments(Request $request, string $number): JsonResponse
    {
        $ticket = $this->find($number);
        abort_if($ticket->confidentiality !== 'standard', 404);
        $query = TicketComment::query()->with('author:id,name')->where('ticket_id', $ticket->id)->where('visibility', 'employee')->orderBy('id');

        return $this->page($query, $request, fn (TicketComment $c) => [
            'id' => $c->id, 'author' => $c->author?->name, 'body' => $c->body, 'created_at' => $c->created_at?->toIso8601String(), 'has_attachment' => $c->attachment_path !== null,
        ]);
    }

    /** Published articles addressed to everyone (no audience rule): an integration has no employee context. */
    public function knowledge(Request $request, KnowledgeBase $kb): JsonResponse
    {
        $query = $kb->readable()->where(fn (Builder $q) => $q->whereNull('audience')->orWhere('audience', '[]'))
            ->when($request->query('category'), fn (Builder $q, $c) => $q->where('category', $c))->orderBy('title');

        return $this->page($query, $request, function (Article $a) use ($kb) {
            $v = $kb->publishedVersion($a);

            return ['slug' => $a->slug, 'title' => $v?->title, 'summary' => $v?->summary, 'category' => $a->category, 'version' => $v?->version, 'version_hash' => $v?->body_hash,
                'published_at' => $v?->published_at?->toIso8601String(), 'requires_acknowledgement' => $a->requires_acknowledgement, 'body' => $v?->body];
        });
    }

    /** Open approval tasks and employee actions on (non-restricted) requests. */
    public function tasks(Request $request): JsonResponse
    {
        $visible = $this->base()->whereIn('tickets.status', Ticket::OPEN);
        $query = WorkflowTask::query()->where('status', TaskStatus::Pending)
            ->whereIn('workflow_instance_id', (clone $visible)->whereNotNull('tickets.workflow_instance_id')->select('tickets.workflow_instance_id'))
            ->orderBy('due_at');
        $numbers = (clone $visible)->whereNotNull('tickets.workflow_instance_id')->pluck('tickets.number', 'tickets.workflow_instance_id');

        return response()->json([
            'data' => [
                'approvals' => $query->limit(200)->get()->map(fn (WorkflowTask $t) => ['request' => $numbers[$t->workflow_instance_id] ?? null, 'type' => $t->type, 'status' => $t->status->value, 'due_at' => $t->due_at?->toIso8601String()])->all(),
                'waiting_for_employee' => (clone $visible)->where('tickets.status', 'waiting_employee')->with('employee:id,employee_code')->limit(200)->get()->map(fn (Ticket $t) => ['request' => $t->number, 'employee_code' => $t->employee?->employee_code, 'since' => $t->status_changed_at?->toIso8601String()])->all(),
            ],
        ]);
    }

    /** Requests an integration may see: tenant-bound, never restricted, never drafts. */
    private function base(): Builder
    {
        return Ticket::query()->where('tickets.confidentiality', '!=', 'restricted')->where('tickets.status', '!=', 'draft');
    }

    private function find(string $number): Ticket
    {
        return $this->base()->with(['service', 'category', 'employee'])->where('tickets.number', $number)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function summary(Ticket $t): array
    {
        return [
            'number' => $t->number, 'service' => $t->service?->code, 'category' => $t->category?->code, 'status' => $t->status, 'priority' => $t->priority,
            'confidentiality' => $t->confidentiality, 'employee_code' => $t->employee?->employee_code, 'source' => $t->source,
            'submitted_at' => $t->submitted_at?->toIso8601String(), 'first_response_due_at' => $t->first_response_due_at?->toIso8601String(),
            'due_at' => $t->due_at?->toIso8601String(), 'sla_paused' => $t->sla_paused_at !== null, 'sla_breached' => $t->isBreached(),
            'resolved_at' => $t->resolved_at?->toIso8601String(), 'closed_at' => $t->closed_at?->toIso8601String(),
            'domain_action' => $t->domain_action, 'domain_action_status' => $t->domain_action_status,
            'domain_reference' => $t->domain_reference_id ? ['type' => class_basename((string) $t->domain_reference_type), 'id' => $t->domain_reference_id] : null,
        ];
    }
}
