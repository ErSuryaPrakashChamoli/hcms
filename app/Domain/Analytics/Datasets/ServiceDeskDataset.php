<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

class ServiceDeskDataset extends Dataset
{
    public function key(): string
    {
        return 'servicedesk';
    }

    public function label(): string
    {
        return 'HR service desk tickets';
    }

    public function permissions(): array
    {
        return ['servicedesk.view'];
    }

    public function fields(): array
    {
        return [
            'number' => ['label' => 'Ticket', 'type' => 'string', 'value' => fn ($t) => $t->number],
            'category' => ['label' => 'Category', 'type' => 'string', 'value' => fn ($t) => $t->category?->name],
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($t) => $t->employee?->employee_code],
            'priority' => ['label' => 'Priority', 'type' => 'string', 'value' => fn ($t) => $t->priority],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($t) => $t->status],
            'agent' => ['label' => 'Agent', 'type' => 'string', 'value' => fn ($t) => $t->assignee?->name],
            'raised_on' => ['label' => 'Raised on', 'type' => 'date', 'value' => fn ($t) => $t->created_at],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($t) => $t->created_at?->format('Y-m')],
            'resolution_hours' => ['label' => 'Resolution time (hours)', 'type' => 'number', 'value' => fn ($t) => $t->resolved_at ? round($t->created_at->diffInMinutes($t->resolved_at) / 60, 1) : null],
            'breached' => ['label' => 'SLA breached (1/0)', 'type' => 'number', 'value' => fn ($t) => $t->escalated_at ? 1 : 0],
            'satisfaction' => ['label' => 'Satisfaction (1–5)', 'type' => 'number', 'value' => fn ($t) => $t->satisfaction],
            'count' => ['label' => 'Tickets (1 per row)', 'type' => 'number', 'value' => fn ($t) => 1],
        ];
    }

    public function query(): Builder
    {
        return Ticket::query()->with(['category', 'employee', 'assignee'])->orderByDesc('id');
    }
}
