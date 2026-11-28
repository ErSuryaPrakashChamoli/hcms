<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file of a bulk employee-documents upload: where it is (the zip — `source`, an index into the batch's sources — an
 * inner zip `container` when the zip held a zip per employee, and the `entry` path inside; each is read by its position in
 * its zip, `container_index` / `entry_index`, never by a name taken from the zip), the employee folder it came in
 * (`folder`, as named: "EMP001_Asha Rao") and the employee that resolved to, the document type matched from its name or
 * chosen while checking, the dates read from its name, and its status with the reason:
 *
 * - ready: will be uploaded; needs_type: no document type matched (choose one, or it is left out);
 * - skipped: left out on purpose — by the person, or already on file (`flag` existing / duplicate); can be included again;
 * - blocked: cannot be uploaded (the zip's code and name are not an employee's in the person's access, a file type
 *   PeopleOS does not keep, too large, empty …);
 * - uploaded (with the employee document it created) or failed (with why) once run.
 *
 * Items are read only through their batch, which only its creator (or bulk.view_all) opens, and only for employees in the
 * viewer's access scope (ScopedByEmployee; files that found no employee stay visible). DocumentBulkUploads counts and runs
 * a whole upload without the scope, checking each file against the uploader's access when it is stored.
 */
#[Fillable(['tenant_id', 'document_upload_batch_id', 'source', 'container', 'container_index', 'entry', 'entry_index', 'folder', 'file_name', 'size_bytes', 'employee_code', 'name_given', 'employee_id',
    'document_type_id', 'matched_by', 'issued_on', 'expires_on', 'status', 'flag', 'reason', 'employee_document_id', 'uploaded_at'])]
class DocumentUploadItem extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['ready' => 'Ready', 'needs_type' => 'Choose a type', 'skipped' => 'Skipped', 'blocked' => 'Cannot upload', 'uploaded' => 'Uploaded', 'failed' => 'Failed'];

    /** How the document type was found. */
    public const MATCHED_BY = ['name' => 'Its name', 'alias' => 'A usual spelling', 'fuzzy' => 'A close spelling', 'folder' => 'Its folder\'s name', 'chosen' => 'Chosen by you'];

    protected $attributes = ['status' => 'ready', 'source' => 0, 'size_bytes' => 0];

    protected function casts(): array
    {
        return ['source' => 'integer', 'container_index' => 'integer', 'entry_index' => 'integer', 'size_bytes' => 'integer', 'issued_on' => 'date', 'expires_on' => 'date', 'uploaded_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'documents';
    }

    public function auditLabel(): string
    {
        return "Employee documents upload #{$this->document_upload_batch_id}: {$this->folder} / {$this->file_name}";
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DocumentUploadBatch::class, 'document_upload_batch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }

    /** While checking: the person may still change what happens to this file. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['ready', 'needs_type', 'skipped'], true);
    }
}
