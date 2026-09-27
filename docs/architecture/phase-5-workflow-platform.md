# Phase 5 — Workflow Platform

Implements blueprint §121 Phase 5: workflow builder, approval engine, escalation engine, task
engine, notification engine, automation, conditions and webhooks. Domains: `App\Domain\Workflow`
and `App\Domain\Notifications`.

## Definitions and versions (§44)

- `workflows`: name, key, `trigger_event` (manual or a domain event), optional `subject_type`,
  optional employee-based `start_conditions`, status.
- `workflow_versions`: `definition = {nodes: [{id, type, name, config}], edges: [{from, to, label}]}`.
  Draft → published (immutable, validated by `WorkflowDefinition`) → retired. Running instances keep
  the version they started with.
- Node types: start, approval, condition, task, notification, wait, webhook, automation ("update
  record"), document (placeholder until the Letter Factory), end. Edge labels route conditions
  (`yes`/`no`) and approvals (`approved`/`rejected`; a rejection without an edge ends the run).
- The builder is `WorkflowBuilderSchema`: a nodes repeater with type-specific settings and an edges
  repeater, plus a text rendering of the flow. A drag-and-drop canvas can be layered on later; the
  definition format is the contract.

## Execution (§44–§45)

`WorkflowEngine::start()` creates a `workflow_instances` row and advances through nodes until one
waits for a person (approval, task) or for time (wait). `completeTask()` records the decision and
resumes; `tick()` resumes waits that are due; `cancel()` closes open tasks. Every step lands in
`workflow_actions` (the run log) and material ones in the audit trail.

Approvals: `ApproverResolver` turns a spec into a user or a role (manager, N levels up, role,
specific user, initiator's manager, user id from a context field). Modes: single, sequential (one
task at a time), parallel (all must approve, any rejection ends it), majority. Tasks assigned to a
role can be acted on by any holder. Amount-based routing is a condition node before the approval.

Conditions reuse the Phase 4 `RuleEngine` over a flat context: employee dimensions, `subject.*`
attributes, `data.*` form answers and instance context keys.

Automation nodes update the subject through its model (audited with the workflow as reason);
webhook nodes dispatch `SendWebhook` (queued, retried, logged); notification nodes go through the
Notifier with template variables.

## Escalation (§46)

`EscalationEngine` runs from `peopleos:workflows:tick` (every five minutes): per node,
`escalation` steps `[{after_hours, action: remind | escalate_to_manager | escalate_to_role}]`
measured from task creation; without steps, overdue tasks get a daily reminder. Reassignment is
audited as ESCALATED.

## Triggers (automation)

`WorkflowTrigger` subscribes to `EmployeeLifecycleChanged` (`employee.<state>`), `FormSubmitted`
and `ConfigurationChangeProposed` and starts every active, published workflow listening for the
event whose subject type and start conditions match. Manual workflows on employees start from the
Employee 360 header ("Start workflow").

## Notification engine (§47)

Event → `notification_rules` (event, audience specs, channels, template, optional conditions) →
`AudienceResolver` (subject, manager, initiator, role, user, assignee) → `TemplateRenderer`
(`{{ employee.name }}` style variables from `NotificationContext`) → `Notifier` →
`notification_deliveries` (one row per person per channel with status and error). Channels are
drivers in `config('peopleos.notifications.channels')`: in-app (Filament database notifications,
the bell in the panel), email (Mailable), and log placeholders for SMS, WhatsApp and push until the
Integration Hub adds providers. `NotificationEventBridge` feeds domain and workflow events into the
engine, and always sends an in-app message for task assignments, reminders and escalations so the
inbox works before any rule exists.

## Task inbox (§57)

`TaskInbox` page: "Waiting for me" (assigned directly or via a role), "Handled by me", and "All
open tasks" for users with `task.view_all`, with approve / reject / complete actions and a
navigation badge. The same actions appear on the run viewer.

## Permissions

`workflow.*` (view, create, update, delete, publish, run, cancel), `task.*` (view, act, view_all,
reassign), `notification.*` (view, update, deliveries). Employees and managers get `task.view` and
`task.act` by default so approvals reach them.

## Tests

`tests/Feature/Workflow/*`, `tests/Feature/Notifications/*`, `tests/Feature/Admin/WorkflowPagesRenderTest.php`:
approval end to end, rejection routing, condition branches, sequential/parallel/majority, role
tasks, to-do + wait + webhook + notification nodes, webhook job, cancel, definition validation,
immutability, reminders and configured escalation steps, lifecycle/form/change triggers, template
rendering, audience resolution, multi-channel rules with in-app and mail assertions, failed
deliveries, inbox approval and badge, run cancellation, invalid publish, permissions.
