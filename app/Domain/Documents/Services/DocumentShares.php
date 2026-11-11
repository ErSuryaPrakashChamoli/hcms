<?php

namespace App\Domain\Documents\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Jobs\RunDocumentShare;
use App\Domain\Documents\Models\DocumentShareBatch;
use App\Domain\Documents\Models\DocumentShareItem;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\EmployeeSelection;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Experience\Services\ScreenAccess;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Jobs\DeliverNotification;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Services\Notifier;
use App\Support\Csv\SpreadsheetCell;
use App\Support\Storage\FileSafety;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * HROne parity WO-14 ("Share letters / documents", matrix 23 §23.7.3 #1–9, §23.7.4 #1–3, §23.9 #20): HR shares ready-made
 * files (made outside PeopleOS) with many employees at once. Letters made in PeopleOS are published through Letters
 * ("Generate & publish"); this is for every other file.
 *
 * - Needs `document.share`. The document type must be active and one employees may see (CA-4 "Employee can view").
 * - Files: a zip with a sheet of employee code + file name ("sheet"; the name with or without its extension), or one common
 *   file for everyone chosen with the WO-2 / WO-16 employee selection ("common"). Limits `peopleos.documents.share`.
 * - start() checks the request, keeps the uploaded files on the documents disk under the tenant's document-shares folder,
 *   writes one item per sheet row or chosen employee (pasted codes nobody has are listed as failed at once) and queues
 *   RunDocumentShare. run() works as the person who shared: only employees in their access scope are reached (anyone else
 *   is "not in your access"), every file is checked like any upload (FileSafety) and stored as an ordinary employee
 *   document through Documents::share — so the employee sees it where they see their documents (My HR, My profile,
 *   "Download all"). Each item ends shared or failed with the reason; the files given are removed once the share has run.
 * - Employees are told in-app (on by default; the notification log, event `document.shared`); e-mail is off by default and
 *   goes only to the employee (work or personal e-mail), with the document attached through the item's MailEnvelope — never a
 *   copy to anyone else.
 * - Unpublish (D-WO5): one document, several, or a whole batch — the item is marked unpublished (who, when, why), the
 *   employee document archived, so the employee no longer lists or opens it; the file and every row are kept; audited
 *   DOCUMENT_UNPUBLISHED. Nothing is deleted.
 */
final class DocumentShares
{
    public const EVENT = 'document.shared';

    public const DONE_EVENT = 'document.share_completed';

    public const PURPOSE = 'document_share';

    /** Sheet columns (the template's header), and the header spellings accepted for each. */
    public const COLUMNS = ['employee_code' => 'Employee code', 'file_name' => 'File name'];

    private const HEADER_ALIASES = ['employeecode' => 'employee_code', 'empcode' => 'employee_code', 'code' => 'employee_code',
        'filename' => 'file_name', 'file' => 'file_name', 'documentname' => 'file_name', 'lettername' => 'file_name'];

    public function __construct(
        private readonly Documents $documents,
        private readonly AuditRecorder $audit,
        private readonly Notifier $notifier,
        private readonly AccessScopes $scopes,
    ) {}

    public static function mayShare(?User $user): bool
    {
        return $user !== null && $user->hasPermission('document.share');
    }

    /** @return Collection<int, DocumentType> the types a share may use: active and visible to employees */
    public static function types(): Collection
    {
        return DocumentType::query()->where('status', 'active')->where('employee_can_view', true)->orderBy('name')->get();
    }

    public static function emailAvailable(): bool
    {
        return (bool) config('peopleos.notifications.channels.email');
    }

    public static function limit(string $key, int $default): int
    {
        return max(1, (int) config("peopleos.documents.share.{$key}", $default));
    }

    public static function directory(int $batchId): string
    {
        return 'tenants/'.app(TenantContext::class)->id()."/document-shares/{$batchId}";
    }

    /** The sheet to fill in (step 1 of "Share documents"): employee code and file name, one example row. */
    public static function template(): string
    {
        return self::csv([array_values(self::COLUMNS), ['EMP001', 'EMP001-form16']]);
    }

    /**
     * Start a share (queued). $data: document_type_id, title, mode (sheet | common), message, notify_in_app, email,
     * email_to (work | personal) and, for a common file, the employee selection (EmployeeSelection criteria). $files: zip +
     * sheet, or file. $confirmed: the typed count asked when more than `bulk.confirm_over` employees get a common file.
     *
     * @param  array<string, mixed>  $data
     * @param  array{zip?: UploadedFile|null, sheet?: UploadedFile|null, file?: UploadedFile|null}  $files
     */
    public function start(User $actor, array $data, array $files, ?int $confirmed = null): DocumentShareBatch
    {
        if (! self::mayShare($actor)) {
            throw new RuntimeException('You cannot share documents.');
        }
        $type = self::types()->firstWhere('id', (int) ($data['document_type_id'] ?? 0))
            ?? throw new RuntimeException('Choose an active document type that employees may see.');
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new RuntimeException('Give the document a title (at most 255 characters).');
        }
        $message = trim(strip_tags((string) ($data['message'] ?? '')));
        if (mb_strlen($message) > self::limit('message_max', 1000)) {
            throw new RuntimeException('Write a message of at most '.self::limit('message_max', 1000).' characters.');
        }
        $mode = (string) ($data['mode'] ?? '');
        if (! array_key_exists($mode, DocumentShareBatch::MODES)) {
            throw new RuntimeException('Choose how the files are given: a zip with a sheet, or one common file.');
        }
        $email = (bool) ($data['email'] ?? false);
        $emailTo = $email ? (string) ($data['email_to'] ?? 'work') : null;
        if ($email && ! self::emailAvailable()) {
            throw new RuntimeException('E-mail is not available.');
        }
        if ($email && ! array_key_exists((string) $emailTo, DocumentShareBatch::EMAIL_TO)) {
            throw new RuntimeException('Choose the work or the personal e-mail.');
        }
        if ($emailTo === 'personal' && ! $actor->hasPermission('employee.sensitive.view')) {
            throw new RuntimeException('Writing to the personal e-mail needs access to sensitive personal data.');
        }

        // The selection is read as the person sharing (their access scope), whoever is signed in.
        [$rows, $selection] = $mode === 'sheet' ? [$this->checkSheetAndZip($files), null] : ScreenAccess::as($actor, fn () => $this->chooseEmployees($data, $files, $confirmed));

        $disk = (string) config('peopleos.documents.disk');
        $stored = [];
        try {
            return DB::transaction(function () use ($actor, $type, $title, $message, $mode, $email, $emailTo, $data, $files, $rows, $selection, $disk, &$stored) {
                $batch = DocumentShareBatch::create([
                    'document_type_id' => $type->id, 'title' => $title, 'mode' => $mode, 'message' => $message !== '' ? $message : null,
                    'notify_in_app' => (bool) ($data['notify_in_app'] ?? true), 'email' => $email, 'email_to' => $emailTo,
                    'selection' => $selection, 'status' => 'queued', 'total' => count($rows), 'created_by' => $actor->id, 'source_disk' => $disk,
                ]);
                $directory = self::directory($batch->id);
                $paths = [];
                foreach (['zip' => 'files.zip', 'sheet' => 'sheet.csv', 'file' => null] as $key => $name) {
                    $file = $files[$key] ?? null;
                    if ($mode === 'sheet' && $key === 'file' || $mode === 'common' && $key !== 'file' || ! $file instanceof UploadedFile) {
                        continue;
                    }
                    $name ??= 'common.'.strtolower($file->getClientOriginalExtension());
                    $stored[] = $paths[$key] = $file->storeAs($directory, $name, $disk);
                }
                $batch->withoutAuditing()->forceFill([
                    'source_path' => $paths[$mode === 'sheet' ? 'zip' : 'file'] ?? null,
                    'source_name' => mb_substr((string) ($files[$mode === 'sheet' ? 'zip' : 'file'] ?? null)?->getClientOriginalName(), 0, 255) ?: null,
                    'sheet_path' => $paths['sheet'] ?? null,
                    'sheet_name' => $mode === 'sheet' ? (mb_substr((string) $files['sheet']->getClientOriginalName(), 0, 255) ?: null) : null,
                ])->save();

                $now = now();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DocumentShareItem::query()->insert(array_map(fn (array $row) => [
                        'tenant_id' => $batch->tenant_id, 'document_share_batch_id' => $batch->id, 'row' => $row['row'], 'employee_code' => $row['code'],
                        'file_name' => $row['file'], 'employee_id' => $row['employee_id'], 'status' => $row['reason'] === null ? 'pending' : 'failed',
                        'reason' => $row['reason'], 'created_at' => $now, 'updated_at' => $now,
                    ], $chunk));
                }
                RunDocumentShare::dispatch($batch->id)->afterCommit();

                return $batch;
            });
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk($disk)->delete($path);
            }
            throw $e;
        }
    }

    /** The job: share every waiting item as the person who started the share. */
    public function run(DocumentShareBatch $batch): DocumentShareBatch
    {
        $claimed = DocumentShareBatch::query()->whereKey($batch->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return $batch->refresh();
        }
        $batch->refresh();
        $actor = User::query()->whereKey($batch->created_by)->where('tenant_id', $batch->tenant_id)->first();
        if ($actor === null || $actor->status !== UserStatus::Active || ! self::mayShare($actor)) {
            return $this->stop($batch, 'The person who started the share can no longer share documents.');
        }
        $type = DocumentType::query()->find($batch->document_type_id);
        if ($type === null || $type->status->value !== 'active' || ! $type->employee_can_view) {
            return $this->stop($batch, 'The document type is no longer active or visible to employees.');
        }

        $local = null;
        $temporary = false;
        $zip = null;
        try {
            if (! filled($batch->source_path) || ! Storage::disk($batch->source_disk)->exists($batch->source_path)) {
                return $this->stop($batch, 'The uploaded files are no longer available.');
            }
            [$local, $temporary] = $this->localCopy($batch->source_disk, $batch->source_path);
            $index = [];
            if ($batch->mode === 'sheet') {
                $archive = new ZipArchive;
                if ($archive->open($local) !== true) {
                    return $this->stop($batch, 'The zip file could not be opened.');
                }
                $zip = $archive;
                $index = self::zipIndex($zip);
            }

            ScreenAccess::as($actor, fn () => $this->audit->operation('documents', 'Share documents: '.$batch->title, function () use ($batch, $actor, $type, $zip, $index, $local) {
                $done = ['shared' => 0, 'failed' => 0];
                // Every waiting item, whatever the scope says now: each is checked against the person's access below.
                DocumentShareItem::query()->withoutGlobalScope(AccessScope::class)->where('document_share_batch_id', $batch->id)->where('status', 'pending')
                    ->chunkById(100, function ($items) use ($batch, $actor, $type, $zip, $index, $local, &$done) {
                        foreach ($items as $item) {
                            $done[$this->shareOne($batch, $item, $actor, $type, $zip, $index, $local) === 'failed' ? 'failed' : 'shared']++;
                        }
                    });

                return ['succeeded' => $done['shared'], 'failed' => $done['failed']];
            }, entityType: 'document_share_batch'));

            return $this->finish($batch, $actor, $batch->mode === 'sheet' ? self::unusedFiles($batch, $index) : []);
        } finally {
            $zip?->close();
            if ($temporary && is_string($local)) {
                @unlink($local);
            }
        }
    }

    /** The job's last attempt failed: the share stops with the reason, waiting items are marked failed. */
    public function markFailed(int $batchId, string $error): void
    {
        $batch = DocumentShareBatch::query()->whereKey($batchId)->whereIn('status', ['queued', 'running'])->first();
        if ($batch !== null) {
            $this->stop($batch, $error);
        }
    }

    /**
     * Unpublish one shared document (D-WO5): hidden from the employee, kept with its file. Needs document.share and the
     * employee within the person's access.
     */
    public function unpublishItem(DocumentShareItem $item, User $actor, string $reason): DocumentShareItem
    {
        $reason = $this->checkUnpublish($actor, $reason);

        return DB::transaction(function () use ($item, $actor, $reason) {
            $locked = DocumentShareItem::query()->withoutGlobalScope(AccessScope::class)->lockForUpdate()->findOrFail($item->id);
            $this->withdraw($locked, $actor, $reason);
            $this->closeBatchIfEmpty($locked->document_share_batch_id, $actor, $reason);

            return $locked->refresh();
        });
    }

    /**
     * Unpublish several shared documents (the published documents list) or a whole batch: each one checked and audited on
     * its own; documents no longer shared or outside the person's access are counted as skipped.
     *
     * @param  iterable<DocumentShareItem>|DocumentShareBatch  $items
     * @return array{unpublished: int, skipped: int}
     */
    public function unpublish(iterable|DocumentShareBatch $items, User $actor, string $reason): array
    {
        $reason = $this->checkUnpublish($actor, $reason);
        $batch = $items instanceof DocumentShareBatch ? $items : null;
        $ids = $batch !== null
            ? DocumentShareItem::query()->withoutGlobalScope(AccessScope::class)->where('document_share_batch_id', $batch->id)->whereIn('status', ['shared', 'unpublished'])->pluck('id')->all()
            : collect($items)->map(fn (DocumentShareItem $i) => $i->id)->all();
        $result = ['unpublished' => 0, 'skipped' => 0];
        $this->audit->operation('documents', $batch !== null ? 'Unpublish document share #'.$batch->id : 'Unpublish shared documents', function () use ($ids, $actor, $reason, &$result) {
            $batches = [];
            foreach ($ids as $id) {
                try {
                    DB::transaction(function () use ($id, $actor, $reason, &$batches) {
                        $locked = DocumentShareItem::query()->withoutGlobalScope(AccessScope::class)->lockForUpdate()->findOrFail($id);
                        $this->withdraw($locked, $actor, $reason);
                        $batches[$locked->document_share_batch_id] = true;
                    });
                    $result['unpublished']++;
                } catch (RuntimeException) {
                    $result['skipped']++;
                }
            }
            foreach (array_keys($batches) as $batchId) {
                $this->closeBatchIfEmpty((int) $batchId, $actor, $reason);
            }

            return ['succeeded' => $result['unpublished'], 'failed' => $result['skipped']];
        }, $reason, 'document_share_item');

        return $result;
    }

    /** The rows that were not shared (and the zip's files no row named), for the "Download errors" CSV. */
    public function errorCsv(DocumentShareBatch $batch): string
    {
        $rows = [['Row', 'Employee code', 'File name', 'Reason']];
        DocumentShareItem::query()->where('document_share_batch_id', $batch->id)->where('status', 'failed')->orderBy('id')
            ->each(function (DocumentShareItem $item) use (&$rows) {
                $rows[] = [(string) ($item->row ?? ''), (string) $item->employee_code, (string) $item->file_name, (string) $item->reason];
            });
        foreach ((array) ($batch->unused_files ?? []) as $name) {
            $rows[] = ['', '', (string) $name, 'In the zip, but no row of the sheet names this file'];
        }

        return self::csv($rows);
    }

    // ------------------------------------------------------------------ start

    /** @return list<array{row: int, code: ?string, file: ?string, employee_id: ?int, reason: ?string}> */
    private function checkSheetAndZip(array $files): array
    {
        $zip = $files['zip'] ?? null;
        $sheet = $files['sheet'] ?? null;
        if (! $zip instanceof UploadedFile || ! $sheet instanceof UploadedFile) {
            throw new RuntimeException('Upload the zip of files and the filled-in sheet.');
        }
        if (strtolower($zip->getClientOriginalExtension()) !== 'zip') {
            throw new RuntimeException('The files must be given as one .zip file.');
        }
        if ((int) $zip->getSize() > 1024 * self::limit('zip_max_kb', 12288)) {
            throw new RuntimeException('The zip is too large (at most '.self::limit('zip_max_kb', 12288).' KB): share in several batches.');
        }
        $archive = new ZipArchive;
        if ($archive->open((string) $zip->getRealPath()) !== true) {
            throw new RuntimeException('The zip file could not be opened.');
        }
        try {
            $index = self::zipIndex($archive);
        } finally {
            $archive->close();
        }
        if ($index['count'] === 0) {
            throw new RuntimeException('The zip holds no files.');
        }
        if ($index['count'] > self::limit('zip_max_entries', 2000)) {
            throw new RuntimeException('The zip holds more than '.self::limit('zip_max_entries', 2000).' files: share in several batches.');
        }
        if ($index['bytes'] > 1024 * self::limit('zip_max_uncompressed_kb', 512000)) {
            throw new RuntimeException('The files in the zip are too large together: share in several batches.');
        }

        return $this->readSheet($sheet);
    }

    /** @return list<array{row: int, code: ?string, file: ?string, employee_id: ?int, reason: ?string}> */
    private function readSheet(UploadedFile $sheet): array
    {
        if (! in_array(strtolower($sheet->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            throw new RuntimeException('The sheet must be a .csv file (download the template).');
        }
        if ((int) $sheet->getSize() > 2 * 1024 * 1024) {
            throw new RuntimeException('The sheet is too large.');
        }
        try {
            $csv = Reader::from((string) $sheet->getRealPath());
            $csv->setHeaderOffset(0);
            $header = $csv->getHeader();
        } catch (Throwable) {
            throw new RuntimeException('The sheet could not be read: use the template (a .csv file).');
        }
        $columns = [];
        foreach ($header as $position => $label) {
            $key = self::HEADER_ALIASES[preg_replace('/[^a-z]/', '', mb_strtolower((string) $label))] ?? null;
            if ($key !== null && ! isset($columns[$key])) {
                $columns[$key] = (string) $label;
            }
        }
        if (! isset($columns['employee_code'], $columns['file_name'])) {
            throw new RuntimeException('The sheet needs the columns "Employee code" and "File name" (download the template).');
        }
        $rows = [];
        $max = self::limit('max_rows', 2000);
        $seen = [];
        foreach ($csv->getRecords() as $offset => $record) {
            $code = trim((string) ($record[$columns['employee_code']] ?? ''));
            $file = trim((string) ($record[$columns['file_name']] ?? ''));
            if ($code === '' && $file === '') {
                continue;
            }
            if (count($rows) >= $max) {
                throw new RuntimeException("The sheet has more than {$max} rows: share in several batches.");
            }
            $line = (int) $offset + 1;
            $key = mb_strtolower($code.'|'.$file);
            $reason = match (true) {
                $code === '' => 'No employee code on this row.',
                $file === '' => 'No file name on this row.',
                mb_strlen($code) > 64 => 'The employee code is too long.',
                mb_strlen($file) > 255 => 'The file name is too long.',
                isset($seen[$key]) => "Repeats row {$seen[$key]}.",
                default => null,
            };
            $seen[$key] ??= $line;
            $rows[] = ['row' => $line, 'code' => $code !== '' ? mb_substr($code, 0, 64) : null, 'file' => $file !== '' ? mb_substr($file, 0, 255) : null, 'employee_id' => null, 'reason' => $reason];
        }
        if ($rows === []) {
            throw new RuntimeException('The sheet has no rows: add one row per employee and file.');
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: list<array{row: ?int, code: ?string, file: ?string, employee_id: ?int, reason: ?string}>, 1: array<string, mixed>}
     */
    private function chooseEmployees(array $data, array $files, ?int $confirmed): array
    {
        $file = $files['file'] ?? null;
        if (! $file instanceof UploadedFile) {
            throw new RuntimeException('Upload the file to share.');
        }
        FileSafety::assertAllowed($file->getClientOriginalName(), (int) $file->getSize(), FileSafety::sniffFile((string) $file->getRealPath()) ?? $file->getMimeType());

        $criteria = array_intersect_key((array) ($data['selection'] ?? []), array_flip(['source', 'team_of', 'team_type', 'codes', 'lifecycle_state', 'joined_from', 'joined_until', 'created_from', 'created_until', 'everyone', ...EmployeeSelection::ORGANISATION]));
        $max = self::limit('max_rows', 2000);
        $ids = app(EmployeeSelection::class)->ids($criteria, $max + 1);
        $unknown = [];
        if (($criteria['source'] ?? null) === 'codes') {
            $codes = EmployeeSelection::parseCodes($criteria['codes'] ?? null);
            $found = array_map('mb_strtolower', Employee::query()->whereKey($ids)->pluck('employee_code')->all());
            $unknown = array_values(array_filter($codes, fn (string $c) => ! in_array(mb_strtolower($c), $found, true)));
        }
        if ($ids === [] && $unknown === []) {
            throw new RuntimeException('Nobody matches: change who the document is for.');
        }
        if (count($ids) > $max) {
            throw new RuntimeException("More than {$max} employees match: narrow the choice or share in several batches.");
        }
        if (BulkRuns::needsTypedCount(count($ids)) && $confirmed !== count($ids)) {
            throw new RuntimeException('Type the number of employees ('.count($ids).') to confirm.');
        }
        $codes = Employee::query()->whereKey($ids)->pluck('employee_code', 'id');
        $rows = array_map(fn (int $id) => ['row' => null, 'code' => (string) ($codes[$id] ?? ''), 'file' => null, 'employee_id' => $id, 'reason' => null], $ids);
        foreach ($unknown as $code) {
            $rows[] = ['row' => null, 'code' => mb_substr($code, 0, 64), 'file' => null, 'employee_id' => null, 'reason' => 'No employee with this code in your access.'];
        }

        return [$rows, $criteria];
    }

    // ------------------------------------------------------------------ run

    /**
     * @param  array<string, mixed>  $index
     * @return string shared | emailed | failed
     */
    private function shareOne(DocumentShareBatch $batch, DocumentShareItem $item, User $actor, DocumentType $type, ?ZipArchive $zip, array $index, string $local): string
    {
        $fail = function (string $reason) use ($item): string {
            $item->withoutAuditing()->update(['status' => 'failed', 'reason' => mb_substr($reason, 0, 500)]);

            return 'failed';
        };

        $employee = $item->employee_id !== null
            ? Employee::query()->with('person')->find($item->employee_id)
            : Employee::query()->with('person')->where('employee_code', (string) $item->employee_code)->first();
        if ($employee === null || Gate::forUser($actor)->denies('view', $employee)) {
            return $fail('No employee with this code in your access.');
        }
        if ($employee->lifecycle_state === LifecycleState::NotJoined) {
            return $fail('This person never joined: documents are not shared with them.');
        }

        $tmp = null;
        $name = null;
        if ($batch->mode === 'sheet') {
            $wanted = mb_strtolower(trim((string) $item->file_name));
            // The exact name, or the name without its extension ("Do not add extension"); never another extension.
            $matches = $index['by_name'][$wanted] ?? $index['by_stem'][$wanted] ?? [];
            if ($matches === []) {
                return $fail('No file named "'.$item->file_name.'" in the zip.');
            }
            if (count($matches) > 1) {
                return $fail('Several files in the zip are named "'.$item->file_name.'": give the file name with its extension.');
            }
            $entry = $matches[0];
            if ($entry['size'] <= 0) {
                return $fail('The file is empty.');
            }
            if ($entry['size'] > 1024 * (int) config('peopleos.documents.max_kb', 10240)) {
                return $fail('The file is too large.');
            }
            $tmp = (string) tempnam(sys_get_temp_dir(), 'peopleos-share-');
            $in = $zip?->getStream($entry['path']);
            if (! is_resource($in)) {
                @unlink($tmp);

                return $fail('The file could not be read from the zip.');
            }
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($in, $out, 1024 * (int) config('peopleos.documents.max_kb', 10240) + 1);
            fclose($out);
            fclose($in);
            $name = $entry['name'];
            $path = $tmp;
        } else {
            $name = (string) ($batch->source_name ?: basename((string) $batch->source_path));
            $path = $local;
        }

        try {
            $document = DB::transaction(function () use ($batch, $item, $employee, $type, $path, $name) {
                $document = $this->documents->share($employee, new UploadedFile($path, $name, null, null, true), $type, $batch->title, "Shared (document share #{$batch->id})", $name);
                $item->withoutAuditing()->update(['status' => 'shared', 'reason' => null, 'employee_id' => $employee->id, 'employee_code' => $employee->employee_code,
                    'employee_document_id' => $document->id, 'shared_at' => now()]);

                return $document;
            });
        } catch (RuntimeException $e) {
            return $fail($e->getMessage());
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }

        $this->notifyEmployee($batch, $item, $employee, $type);

        return $this->emailEmployee($batch, $item, $employee, $document) ? 'emailed' : 'shared';
    }

    private function notifyEmployee(DocumentShareBatch $batch, DocumentShareItem $item, Employee $employee, DocumentType $type): void
    {
        if (! $batch->notify_in_app || $employee->user_id === null) {
            return;
        }
        $user = User::query()->whereKey($employee->user_id)->whereIn('status', [UserStatus::Active, UserStatus::Invited])->first();
        if ($user === null) {
            return;
        }
        rescue(fn () => $this->notifier->send([$user], ['in_app'], 'New document: '.$batch->title,
            $batch->message ?: "HR shared a document with you ({$type->name}). Open My HR → Documents to see it.", self::EVENT, $item), report: true);
    }

    /** E-mail (off by default) only to the employee, with the document attached when the mail goes (MailEnvelope). */
    private function emailEmployee(DocumentShareBatch $batch, DocumentShareItem $item, Employee $employee, EmployeeDocument $document): bool
    {
        if (! $batch->email || ! self::emailAvailable()) {
            return false;
        }
        $address = $batch->email_to === 'personal' ? $employee->person?->personal_email : $employee->work_email;
        if (! filled($address) || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $item->withoutAuditing()->update(['reason' => 'Shared; not e-mailed: no '.mb_strtolower(DocumentShareBatch::EMAIL_TO[$batch->email_to] ?? 'e-mail').' on file.']);

            return false;
        }
        $body = trim(($batch->message ? $batch->message."\n\n" : '')."{$batch->title} is attached. You can also find it in My HR → Documents.");
        $delivery = NotificationDelivery::create([
            'user_id' => $employee->user_id !== null && $batch->email_to !== 'personal' ? $employee->user_id : null,
            'channel' => 'email', 'event' => self::EVENT, 'subject' => mb_substr('Document: '.$batch->title, 0, 200), 'body' => $body, 'status' => 'queued',
            'recipient' => (string) $address, 'source_type' => $item->getMorphClass(), 'source_id' => $item->getKey(), 'correlation_id' => Context::get('request_id'),
        ]);
        $item->withoutAuditing()->update(['notification_delivery_id' => $delivery->id]);
        DeliverNotification::dispatch($delivery->id)->afterCommit();

        return true;
    }

    private function finish(DocumentShareBatch $batch, User $actor, array $unused): DocumentShareBatch
    {
        $counts = DocumentShareItem::query()->withoutGlobalScope(AccessScope::class)->where('document_share_batch_id', $batch->id)
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->toBase()->get()->pluck('aggregate', 'status')->map(fn ($n) => (int) $n);
        $emailed = DocumentShareItem::query()->withoutGlobalScope(AccessScope::class)->where('document_share_batch_id', $batch->id)->whereNotNull('notification_delivery_id')->count();
        $batch->withoutAuditing()->update([
            'status' => 'completed', 'finished_at' => now(), 'unused_files' => $unused === [] ? null : $unused,
            'total' => (int) $counts->sum(), 'shared' => (int) ($counts['shared'] ?? 0) + (int) ($counts['unpublished'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0), 'emailed' => $emailed,
        ]);
        $this->removeSources($batch);
        $this->audit->record(AuditAction::DocumentsShared, 'documents', $batch, [], null, actor: $actor, metadata: [
            'batch' => $batch->id, 'document_type' => $batch->document_type_id, 'mode' => $batch->mode, 'total' => $batch->total, 'shared' => $batch->shared,
            'failed' => $batch->failed, 'emailed' => $emailed, 'notify_in_app' => $batch->notify_in_app, 'unused_files' => count($unused),
        ]);
        rescue(fn () => $this->notifier->send([$actor], ['in_app'], 'Documents shared: '.$batch->title, $batch->summary()
            .($batch->failed > 0 ? '. Open the share to see each row that failed and why, or download the errors.' : '.'), self::DONE_EVENT, $batch), report: true);

        return $batch->refresh();
    }

    private function stop(DocumentShareBatch $batch, string $error): DocumentShareBatch
    {
        DocumentShareItem::query()->withoutGlobalScope(AccessScope::class)->where('document_share_batch_id', $batch->id)->where('status', 'pending')
            ->update(['status' => 'failed', 'reason' => mb_substr('Not shared: '.$error, 0, 500), 'updated_at' => now()]);
        $counts = DocumentShareItem::query()->withoutGlobalScope(AccessScope::class)->where('document_share_batch_id', $batch->id)
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->toBase()->get()->pluck('aggregate', 'status')->map(fn ($n) => (int) $n);
        $batch->withoutAuditing()->update(['status' => 'failed', 'error' => mb_substr($error, 0, 500), 'finished_at' => now(),
            'shared' => (int) ($counts['shared'] ?? 0) + (int) ($counts['unpublished'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0)]);
        $this->removeSources($batch);

        return $batch->refresh();
    }

    private function removeSources(DocumentShareBatch $batch): void
    {
        $disk = Storage::disk($batch->source_disk ?: (string) config('peopleos.documents.disk'));
        foreach (array_filter([$batch->source_path, $batch->sheet_path]) as $path) {
            $disk->delete($path);
        }
        $batch->withoutAuditing()->update(['source_path' => null, 'sheet_path' => null]);
    }

    /** @return array{0: string, 1: bool} a local path of the stored file, and whether it is a temporary copy */
    private function localCopy(string $diskName, string $path): array
    {
        $disk = Storage::disk($diskName);
        $local = rescue(fn () => $disk->path($path), null, false);
        if (is_string($local) && is_file($local)) {
            return [$local, false];
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'peopleos-share-src-');
        $in = $disk->readStream($path) ?: throw new RuntimeException('The uploaded files are no longer available.');
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out);
        is_resource($in) && fclose($in);

        return [$tmp, true];
    }

    /**
     * The zip's files that no row of the sheet names (whatever happened to the row), at most 200, by name.
     *
     * @param  array<string, mixed>  $index
     * @return list<string>
     */
    private static function unusedFiles(DocumentShareBatch $batch, array $index): array
    {
        $named = [];
        DocumentShareItem::query()->withoutGlobalScope(AccessScope::class)->where('document_share_batch_id', $batch->id)->whereNotNull('file_name')
            ->pluck('file_name')->each(function ($name) use ($index, &$named) {
                $wanted = mb_strtolower(trim((string) $name));
                foreach ($index['by_name'][$wanted] ?? $index['by_stem'][$wanted] ?? [] as $entry) {
                    $named[$entry['path']] = true;
                }
            });
        $unused = [];
        foreach ($index['by_name'] ?? [] as $entries) {
            foreach ($entries as $entry) {
                if (! isset($named[$entry['path']])) {
                    $unused[] = $entry['name'];
                }
            }
        }
        sort($unused);

        return array_slice(array_values(array_unique($unused)), 0, 200);
    }

    /**
     * The zip's files by lower-case name and by name without extension (folders, hidden and macOS resource files left out).
     * Entry names are only used to match; nothing is ever extracted to a path taken from the zip.
     *
     * @return array{count: int, bytes: int, by_name: array<string, list<array{path: string, name: string, size: int}>>, by_stem: array<string, list<array{path: string, name: string, size: int}>>}
     */
    private static function zipIndex(ZipArchive $zip): array
    {
        $out = ['count' => 0, 'bytes' => 0, 'by_name' => [], 'by_stem' => []];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $path = (string) ($stat['name'] ?? '');
            if ($path === '' || str_ends_with($path, '/') || str_starts_with($path, '__MACOSX/') || str_contains($path, '/__MACOSX/')) {
                continue;
            }
            $name = basename(str_replace('\\', '/', $path));
            if ($name === '' || str_starts_with($name, '.')) {
                continue;
            }
            $entry = ['path' => $path, 'name' => $name, 'size' => (int) ($stat['size'] ?? 0)];
            $out['by_name'][mb_strtolower($name)][] = $entry;
            $out['by_stem'][mb_strtolower(pathinfo($name, PATHINFO_FILENAME))][] = $entry;
            $out['count']++;
            $out['bytes'] += $entry['size'];
        }

        return $out;
    }

    // ------------------------------------------------------------------ unpublish

    private function checkUnpublish(User $actor, string $reason): string
    {
        if (! self::mayShare($actor)) {
            throw new RuntimeException('You cannot unpublish shared documents.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('Say why the document is unpublished (at most 500 characters).');
        }

        return $reason;
    }

    /** One locked item: the employee within the person's access, still shared; the document archived, the file kept. */
    private function withdraw(DocumentShareItem $item, User $actor, string $reason): void
    {
        if ($item->status !== 'shared') {
            throw new RuntimeException('Only a shared document can be unpublished.');
        }
        if ($item->employee_id === null || ! $this->scopes->allows($actor, $item)) {
            throw new RuntimeException('This employee is not in your access.');
        }
        $item->withoutAuditing()->update(['status' => 'unpublished', 'unpublished_at' => now(), 'unpublished_by' => $actor->id, 'unpublish_reason' => $reason]);
        $document = EmployeeDocument::query()->withoutGlobalScope(AccessScope::class)->lockForUpdate()->find($item->employee_document_id);
        if ($document !== null && $document->status !== 'archived') {
            $document->withAuditReason("Unpublished: {$reason}")->update(['status' => 'archived']);
        }
        $this->audit->record(AuditAction::DocumentUnpublished, 'documents', $document ?? $item, [], $reason, actor: $actor, metadata: [
            'batch' => $item->document_share_batch_id, 'item' => $item->id, 'employee_id' => $item->employee_id, 'document_id' => $item->employee_document_id,
        ]);
    }

    /** A batch none of whose documents is still with an employee is marked unpublished (who, when, why). */
    private function closeBatchIfEmpty(int $batchId, User $actor, string $reason): void
    {
        $items = DocumentShareItem::query()->withoutGlobalScope(AccessScope::class)->where('document_share_batch_id', $batchId);
        if ((clone $items)->where('status', 'shared')->exists() || ! (clone $items)->where('status', 'unpublished')->exists()) {
            return;
        }
        DocumentShareBatch::query()->whereKey($batchId)->whereNull('unpublished_at')->first()
            ?->withAuditReason($reason)->update(['unpublished_at' => now(), 'unpublished_by' => $actor->id, 'unpublish_reason' => $reason]);
    }

    /** @param  list<list<string>>  $rows */
    private static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            // A spreadsheet never runs a cell as a formula.
            fputcsv($out, SpreadsheetCell::row($row), escape: '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
