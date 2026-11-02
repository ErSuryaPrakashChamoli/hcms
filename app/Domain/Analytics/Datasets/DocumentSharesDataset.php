<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Documents\Models\DocumentShareItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity WO-14 (§23.7.3 #9, matrix 21 Workforce #41 "Shared letter/document report"): every row of the bulk document
 * shares — the employee, the share (number, type, title, published on / by), shared / failed with the reason / unpublished
 * (when, by whom, why). Rows of codes nobody in the viewer's access has are left out (no employee).
 */
class DocumentSharesDataset extends Dataset
{
    public function key(): string
    {
        return 'document_shares';
    }

    public function label(): string
    {
        return 'Shared letters / documents (bulk shares per employee)';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['document.share'];
    }

    public function fields(): array
    {
        return [
            'share' => ['label' => 'Share number', 'type' => 'number', 'value' => fn ($i) => (int) $i->document_share_batch_id],
            ...$this->employeeFields(fn ($i) => $i->employee),
            'document_type' => ['label' => 'Document type', 'type' => 'string', 'value' => fn ($i) => $i->batch?->type?->name],
            'title' => ['label' => 'Title', 'type' => 'string', 'value' => fn ($i) => $i->batch?->title],
            'file' => ['label' => 'File', 'type' => 'string', 'value' => fn ($i) => $i->document?->original_name ?? $i->file_name],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($i) => $i->status],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($i) => $i->reason],
            'published_on' => ['label' => 'Published on', 'type' => 'date', 'value' => fn ($i) => $i->shared_at ?? $i->created_at],
            'published_by' => ['label' => 'Published by', 'type' => 'string', 'value' => fn ($i) => $i->batch?->creator?->name],
            'unpublished_on' => ['label' => 'Unpublished on', 'type' => 'date', 'value' => fn ($i) => $i->unpublished_at],
            'unpublished_by' => ['label' => 'Unpublished by', 'type' => 'string', 'value' => fn ($i) => $i->unpublisher?->name],
            'unpublish_reason' => ['label' => 'Unpublished because', 'type' => 'string', 'value' => fn ($i) => $i->unpublish_reason],
            'month' => ['label' => 'Publish month', 'type' => 'string', 'value' => fn ($i) => ($i->shared_at ?? $i->created_at)?->format('Y-m')],
            'count' => ['label' => 'Documents (1 per row)', 'type' => 'number', 'value' => fn ($i) => 1],
        ];
    }

    public function query(): Builder
    {
        return DocumentShareItem::query()->whereNotNull('employee_id')->whereIn('status', ['shared', 'failed', 'unpublished'])
            ->with([...self::employeeWith('employee'), 'batch.type', 'batch.creator', 'document', 'unpublisher'])->orderByDesc('id');
    }
}
