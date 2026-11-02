<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Contracts\MailEnvelope;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * HROne parity WO-14: one row of a document share — a sheet row (employee code + file name) or an employee chosen for a
 * common file — with the employee document it created or the reason it failed, and, once unpublished, who, when and why
 * (§23.7.3 #7, §23.7.4 #3; D-WO5: the document is archived and hidden from the employee, the file is kept).
 *
 * It is also the source of the optional e-mail to the employee (MailEnvelope, as WO-13's e-mailed letters): the document's
 * own file is attached, read when the mail goes, and a document unpublished meanwhile is not sent. No other recipient and
 * no copy (CC / BCC) is ever added.
 */
#[Fillable(['tenant_id', 'document_share_batch_id', 'row', 'employee_code', 'file_name', 'employee_id', 'employee_document_id', 'status', 'reason', 'shared_at', 'notification_delivery_id',
    'unpublished_at', 'unpublished_by', 'unpublish_reason'])]
class DocumentShareItem extends Model implements MailEnvelope
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Waiting', 'shared' => 'Shared', 'failed' => 'Failed', 'unpublished' => 'Unpublished'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['row' => 'integer', 'shared_at' => 'datetime', 'unpublished_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'documents';
    }

    public function auditLabel(): string
    {
        return "Document share #{$this->document_share_batch_id}, ".($this->employee_code ?? 'row '.$this->row);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DocumentShareBatch::class, 'document_share_batch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }

    public function unpublisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unpublished_by');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(NotificationDelivery::class, 'notification_delivery_id');
    }

    public function mailAddresses(NotificationDelivery $delivery): array
    {
        return ['to' => [], 'cc' => [], 'bcc' => []];
    }

    public function mailAttachments(NotificationDelivery $delivery): array
    {
        $document = $this->status === 'shared' ? $this->document()->first() : null;
        if ($document === null || $document->status === 'archived') {
            throw new RuntimeException('The document is no longer shared with the employee; it is not sent.');
        }
        $disk = Storage::disk($document->disk);
        if (! $disk->exists($document->path)) {
            throw new RuntimeException('The document\'s file is missing; it is not sent.');
        }

        return [['content' => (string) $disk->get($document->path), 'name' => (string) $document->original_name, 'mime' => (string) ($document->mime_type ?: 'application/octet-stream')]];
    }
}
