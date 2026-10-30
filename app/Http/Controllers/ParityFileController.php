<?php

namespace App\Http\Controllers;

use App\Domain\Assets\Models\AssetModel;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Identity\Services\OwnRecords;
use App\Domain\Organisation\Models\Company;
use App\Domain\Recognition\Models\Recognition;
use App\Domain\Recognition\Models\WallPost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files attached to HROne-parity records (investment proofs, reimbursement bills, product documents, expense receipts,
 * company logos, document type samples, time-office request and regularisation documents). Each download goes through:
 * signed link → authenticated user → tenant → the record's own policy (or the employee's own record) → audit →
 * stream from the private documents disk. Records resolve after the tenant is bound, so foreign ids fail closed.
 */
class ParityFileController extends Controller
{
    /** type => [model, attribute holding the list of paths, audit module] */
    private const TYPES = [
        'investment-proof' => [TdsEmployeeInvestment::class, 'proof_files', 'compliance'],
        'claim' => [ReimbursementClaim::class, 'attachments', 'payroll'],
        // HROne setup parity AS-A: product (asset model) documents, for whoever may view the product.
        'asset-model' => [AssetModel::class, 'documents', 'assets'],
        // HROne setup parity EX-C/EX-D: expense receipt files and advance documents (own records, or in scope).
        'expense-receipt' => [ExpenseReceipt::class, 'attachments', 'expense'],
        'expense-advance' => [ExpenseAdvance::class, 'attachments', 'expense'],
        // HROne setup parity CA-2 / CA-4: a company's logo (company.view) and a document type's sample file (anyone who
        // may see document types, including employees with their own documents, so the upload dialog can offer it).
        'company-logo' => [Company::class, 'logo_path', 'organisation'],
        'document-type-sample' => [DocumentType::class, 'sample_path', 'documents'],
        // HROne setup parity HP-9c: a recognition's attachment, for whoever may see the recognition.
        'recognition' => [Recognition::class, 'attachments', 'recognition'],
        // HROne setup parity HP-9e: an HR post's attachment on the social wall, for whoever may see the post.
        'wall-post' => [WallPost::class, 'attachments', 'recognition'],
        // HROne parity TX-1: supporting documents of a time-office request or a regularisation (own, or in scope).
        'time-office-request' => [TimeOfficeRequest::class, 'attachments', 'attendance'],
        'regularisation' => [AttendanceRegularisation::class, 'attachments', 'attendance'],
    ];

    public static function url(string $type, Model $record, int $index): string
    {
        return URL::temporarySignedRoute('parity.file', now()->addMinutes(30), ['type' => $type, 'id' => $record->getKey(), 'index' => $index]);
    }

    public function __invoke(Request $request, string $type, int $id, int $index, AuditRecorder $audit): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        abort_unless(isset(self::TYPES[$type]), 404);
        [$class, $attribute, $module] = self::TYPES[$type];

        $record = $class::query()->findOrFail($id);
        $employeeId = $record->getAttributes()['employee_id'] ?? null;
        abort_unless(Gate::allows('view', $record) || ($employeeId !== null && app(OwnRecords::class)->isOwn($request->user(), $employeeId)), 403);

        $path = array_values((array) ($record->{$attribute} ?? []))[$index] ?? null;
        $disk = config('peopleos.documents.disk', 'local');
        abort_unless(is_string($path) && Storage::disk($disk)->exists($path), 404);

        $audit->record(AuditAction::AttachmentDownloaded, $module, $record, metadata: ['type' => $type, 'file' => basename($path)], reason: 'attachment download');

        return Storage::disk($disk)->download($path, basename($path));
    }
}
