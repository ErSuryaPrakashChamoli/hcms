<?php

namespace App\Domain\Documents\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Notifications\Services\NotificationEngine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Document management core (§39, §83): private storage, versions per type, verification,
 * audited access, temporary signed download links.
 */
final class Documents
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
        private readonly NotificationEngine $notifications,
        private readonly NotificationContext $context,
    ) {}

    public function store(Employee $employee, UploadedFile $file, ?DocumentType $type = null, ?string $title = null, ?string $expiresOn = null, ?string $issuedOn = null, ?string $reason = null): EmployeeDocument
    {
        $disk = config('peopleos.documents.disk');
        $tenantId = $employee->tenant_id;
        $path = $file->storeAs("tenants/{$tenantId}/employees/{$employee->id}", Str::ulid().'.'.$file->getClientOriginalExtension(), $disk);

        $version = $type
            ? (int) EmployeeDocument::query()->where('employee_id', $employee->id)->where('document_type_id', $type->id)->max('version') + 1
            : 1;

        $document = new EmployeeDocument([
            'employee_id' => $employee->id,
            'document_type_id' => $type?->id,
            'title' => $title ?: ($type?->name ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize() ?: 0,
            'version' => $version,
            'status' => 'pending',
            'issued_on' => $issuedOn,
            'expires_on' => $expiresOn,
            'uploaded_by' => auth()->id(),
        ]);
        $document->withAuditReason($reason)->save();

        if ($version > 1) {
            EmployeeDocument::query()->where('employee_id', $employee->id)->where('document_type_id', $type->id)
                ->where('version', '<', $version)->where('status', '!=', 'archived')
                ->get()->each(fn (EmployeeDocument $old) => $old->withAuditReason("Superseded by v{$version}")->update(['status' => 'archived']));
        }

        $this->notifications->fire('document.uploaded', $this->context->build($employee, ['document' => $this->vars($document)]), $document);

        return $document;
    }

    public function review(EmployeeDocument $document, bool $verified, ?string $note = null): EmployeeDocument
    {
        $document->loadMissing(['employee', 'type']);
        $document->withAuditReason($note)->update([
            'status' => $verified ? 'verified' : 'rejected',
            'verified_by' => auth()->id(),
            'verified_at' => now(),
            'review_note' => $note,
        ]);

        $this->audit->record($verified ? AuditAction::Approved : AuditAction::Rejected, 'documents', $document, reason: $note);

        if ($verified) {
            $this->timeline->record($document->employee, 'document', "{$document->title} verified", now(), $note, $document);
        }

        $this->notifications->fire($verified ? 'document.verified' : 'document.rejected', $this->context->build($document->employee, ['document' => $this->vars($document)]), $document);

        return $document;
    }

    /** Authorised, audited access: returns a short-lived signed URL (§83). */
    public function downloadUrl(EmployeeDocument $document, int $minutes = 10): string
    {
        return URL::temporarySignedRoute('documents.download', now()->addMinutes($minutes), ['document' => $document->id]);
    }

    public function recordAccess(EmployeeDocument $document, string $purpose = 'download'): void
    {
        if (! $document->isSensitive()) {
            return;
        }

        $this->audit->record($purpose === 'download' ? AuditAction::Download : AuditAction::View, 'documents', $document, metadata: ['purpose' => $purpose, 'employee_id' => $document->employee_id], reason: $purpose);
    }

    public function delete(EmployeeDocument $document, ?string $reason = null): void
    {
        Storage::disk($document->disk)->delete($document->path);
        $document->withAuditReason($reason)->delete();
    }

    /** @return Collection<int, EmployeeDocument> */
    public function expiringWithin(int $days)
    {
        return EmployeeDocument::query()
            ->with(['employee.person', 'type'])
            ->whereIn('status', ['pending', 'verified'])
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->get();
    }

    /** @return array<string, mixed> */
    public function vars(EmployeeDocument $document): array
    {
        return ['id' => $document->id, 'title' => $document->title, 'type' => $document->type?->name, 'status' => $document->status, 'expires_on' => $document->expires_on, 'version' => $document->version];
    }
}
