<?php

namespace App\Domain\Documents\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Documents\Jobs\RunDocumentBulkUpload;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\DocumentUploadBatch;
use App\Domain\Documents\Models\DocumentUploadItem;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ScreenAccess;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Services\Notifier;
use App\Support\Csv\SpreadsheetCell;
use App\Support\Numbering\NumberSeries;
use App\Support\Storage\FileSafety;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Bulk upload of employee documents (Admin → Data import → Employee documents). HR gives many zips at once — one per
 * employee, named "employee code_name" ("EMP001_Asha Rao.zip") — or one zip holding a folder or a zip per employee. Every
 * file inside is matched to a document type by its name (DocumentNameMatcher: "Aadhar card.pdf" → Aadhaar) and, once
 * the person has looked at the matches, stored as an ordinary employee document (Documents::store: the same file checks,
 * versions, audit; pending verification, never verified by the uploader).
 *
 * - Needs `document.bulk_upload`. A zip or folder is named "employee code_First Last name" (or "code_First name"): the
 *   code, matched exactly, finds the employee within the person's access, and the name must be that employee's name in
 *   PeopleOS (owner, 2026-11-27: both must match), or none of its files is uploaded.
 * - check() reads the zips' directories (nothing is extracted to a path taken from a zip), writes one item per file —
 *   ready, waiting for a type, skipped (already on file, or the same file twice) or blocked with the reason — and keeps
 *   the zips on the documents disk under the tenant's document-uploads folder. Nothing reaches an employee yet.
 * - While checking (status review) the person changes a file's type or dates, skips or includes files; start() queues
 *   RunDocumentBulkUpload and run() uploads every ready file as that person, each one checked again (access, active
 *   type, size, content). Files of a type the employee already has become its new version (the earlier one archived)
 *   unless the person chose to keep the one on file; a second file of the same type in one upload (an ID's front and
 *   back) is added beside the first, not over it.
 * - The zips are removed once the upload has run or is discarded; an upload left unchecked for 7 days is discarded.
 *   Each item keeps what happened to its file (the employee document, or why not) for the results sheet.
 */
final class DocumentBulkUploads
{
    public const PERMISSION = 'document.bulk_upload';

    public const DONE_EVENT = 'document.bulk_upload_completed';

    /** Days an upload may wait to be checked before it is discarded (and its zips removed). */
    public const REVIEW_DAYS = 7;

    /** Files every zip tool adds that are not documents. */
    private const JUNK = ['thumbs.db', 'desktop.ini', '.ds_store'];

    /** Words in a zip's or folder's name after the employee code that are not the employee's name. */
    private const NAME_FILLERS = ['documents', 'document', 'docs', 'doc', 'files', 'file', 'kyc', 'papers', 'scan', 'scans', 'records', 'copy'];

    /** @var array<string, DocumentNameMatcher> */
    private array $matchers = [];

    /** @var array<int, ?int> employee id => company id */
    private array $companies = [];

    public function __construct(
        private readonly Documents $documents,
        private readonly AuditRecorder $audit,
        private readonly Notifier $notifier,
    ) {}

    public static function mayUpload(?User $user): bool
    {
        return $user !== null && $user->hasPermission(self::PERMISSION);
    }

    /** The upload's creator opens it; bulk.view_all holders may look at anyone's. */
    public static function canSee(?User $user, DocumentUploadBatch $batch): bool
    {
        return self::mayUpload($user) && ((int) $batch->created_by === (int) $user->id || $user->hasPermission('bulk.view_all'));
    }

    /** Only the person who uploaded the zips changes, starts or discards the upload. */
    public static function canChange(?User $user, DocumentUploadBatch $batch): bool
    {
        return self::mayUpload($user) && (int) $batch->created_by === (int) $user->id;
    }

    public static function limit(string $key, int $default): int
    {
        return max(1, (int) config("peopleos.documents.bulk_upload.{$key}", $default));
    }

    public static function directory(int $batchId): string
    {
        return 'tenants/'.app(TenantContext::class)->id()."/document-uploads/{$batchId}";
    }

    // ------------------------------------------------------------------ check

    /**
     * Read the zips and match every file (nothing is uploaded yet). $options: existing (version | skip: a type the
     * employee already has), notify (the usual "document uploaded" notifications, off by default).
     *
     * @param  list<UploadedFile>  $zips
     * @param  array<string, mixed>  $options
     */
    public function check(User $actor, array $zips, array $options = []): DocumentUploadBatch
    {
        if (! self::mayUpload($actor)) {
            throw new RuntimeException('You cannot upload employee documents in bulk.');
        }
        $zips = array_values(array_filter($zips, fn ($zip) => $zip instanceof UploadedFile));
        if ($zips === []) {
            throw new RuntimeException('Upload at least one zip.');
        }
        if (count($zips) > ($most = self::limit('zips_max', 50))) {
            throw new RuntimeException("Upload at most {$most} zips at a time: upload the rest in another go.");
        }
        $options = [
            'existing' => array_key_exists((string) ($options['existing'] ?? ''), DocumentUploadBatch::EXISTING) ? (string) $options['existing'] : 'version',
            'notify' => (bool) ($options['notify'] ?? false),
        ];
        $this->discardStale();

        $items = ScreenAccess::as($actor, fn () => $this->scan($zips, $options));
        $disk = (string) config('peopleos.documents.disk');
        $stored = [];
        try {
            return DB::transaction(function () use ($actor, $zips, $options, $items, $disk, &$stored) {
                $batch = DocumentUploadBatch::create([
                    'status' => 'review', 'options' => $options, 'source_disk' => $disk, 'zip_count' => count($zips), 'created_by' => $actor->id,
                    'employee_count' => count(array_unique(array_filter(array_column($items, 'employee_id')))), 'file_count' => count($items),
                ]);
                $sources = [];
                foreach ($zips as $i => $zip) {
                    $stored[] = $path = $zip->storeAs(self::directory($batch->id), $i.'.zip', $disk);
                    $sources[] = ['path' => $path, 'name' => mb_substr($zip->getClientOriginalName(), 0, 255), 'size' => (int) $zip->getSize()];
                }
                $batch->withoutAuditing()->forceFill(['sources' => $sources])->save();
                $now = now();
                foreach (array_chunk($items, 500) as $chunk) {
                    DocumentUploadItem::query()->insert(array_map(fn (array $item) => $item + [
                        'tenant_id' => $batch->tenant_id, 'document_upload_batch_id' => $batch->id, 'created_at' => $now, 'updated_at' => $now,
                    ], $chunk));
                }

                return $this->refreshCounts($batch);
            });
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk($disk)->delete($path);
            }
            throw $e;
        }
    }

    /**
     * Change a file while checking: its document type (chosen by the person) and the dates. A file waiting for a type is
     * ready once it has one; a ready file is checked again against what is on file.
     *
     * @param  array{document_type_id?: int|string|null, issued_on?: ?string, expires_on?: ?string}  $data
     */
    public function edit(DocumentUploadItem $item, User $actor, array $data): DocumentUploadItem
    {
        $batch = $this->openBatch($item, $actor);
        if (! $item->isOpen()) {
            throw new RuntimeException('This file cannot be uploaded, so it cannot be changed.');
        }
        $changes = [];
        if (array_key_exists('document_type_id', $data)) {
            $typeId = (int) $data['document_type_id'];
            if ($typeId !== (int) $item->document_type_id) {
                $type = $this->typesFor($item->loadMissing('employee')->employee)->firstWhere('id', $typeId)
                    ?? throw new RuntimeException('Choose an active document type this employee\'s company uses.');
                $changes += ['document_type_id' => $type->id, 'matched_by' => 'chosen'];
            }
        }
        $issued = array_key_exists('issued_on', $data) ? self::date($data['issued_on']) : $item->issued_on?->toDateString();
        $expires = array_key_exists('expires_on', $data) ? self::date($data['expires_on']) : $item->expires_on?->toDateString();
        if ($issued !== null && $expires !== null && $expires < $issued) {
            throw new RuntimeException('The expiry date cannot be before the issue date.');
        }
        $changes += ['issued_on' => $issued, 'expires_on' => $expires];
        $item->fill($changes);
        if (isset($changes['document_type_id']) && in_array($item->status, ['ready', 'needs_type'], true)
            || isset($changes['document_type_id']) && $item->status === 'skipped' && in_array($item->flag, ['existing', 'duplicate'], true)) {
            $item->fill(['status' => 'ready', 'flag' => null, 'reason' => null]);
            $item->fill($this->onFileCheck($batch, $item));
        }
        $item->withAuditReason('Checked before the bulk upload')->save();
        $this->refreshCounts($batch);

        return $item->refresh();
    }

    /**
     * The same document type for several files. Files that can no longer change, or whose employee's company does not use
     * the type, are counted as refused.
     *
     * @param  iterable<DocumentUploadItem>  $items
     * @return array{done: int, refused: int}
     */
    public function setType(iterable $items, int $typeId, User $actor): array
    {
        $out = ['done' => 0, 'refused' => 0];
        foreach ($items as $item) {
            try {
                $this->edit($item, $actor, ['document_type_id' => $typeId]);
                $out['done']++;
            } catch (RuntimeException) {
                $out['refused']++;
            }
        }

        return $out;
    }

    /**
     * Leave files out of the upload (ready or waiting for a type).
     *
     * @param  iterable<DocumentUploadItem>  $items
     */
    public function skip(iterable $items, User $actor): int
    {
        $done = 0;
        $batch = null;
        foreach ($items as $item) {
            $batch ??= $this->openBatch($item, $actor);
            if ((int) $item->document_upload_batch_id !== (int) $batch->id || ! in_array($item->status, ['ready', 'needs_type'], true)) {
                continue;
            }
            $item->withAuditReason('Skipped before the bulk upload')->update(['status' => 'skipped', 'flag' => 'person', 'reason' => 'Skipped by you.']);
            $done++;
        }
        $batch && $this->refreshCounts($batch);

        return $done;
    }

    /**
     * Take skipped files back into the upload (skipped by the person, or because they are already on file). Files that cannot
     * be uploaded — a code or name that does not match an employee among them — never can: rename the zip and upload it again.
     *
     * @param  iterable<DocumentUploadItem>  $items
     */
    public function include(iterable $items, User $actor): int
    {
        $done = 0;
        $batch = null;
        foreach ($items as $item) {
            $batch ??= $this->openBatch($item, $actor);
            if ((int) $item->document_upload_batch_id !== (int) $batch->id || $item->status !== 'skipped') {
                continue;
            }
            $item->withAuditReason('Included in the bulk upload')->update($item->document_type_id !== null
                ? ['status' => 'ready', 'flag' => null, 'reason' => null]
                : ['status' => 'needs_type', 'flag' => null, 'reason' => 'No document type matches this name. Choose one, or it is left out.']);
            $done++;
        }
        $batch && $this->refreshCounts($batch);

        return $done;
    }

    /** Upload every ready file (queued). Files still waiting for a type are left out and listed. */
    public function start(DocumentUploadBatch $batch, User $actor): DocumentUploadBatch
    {
        if (! self::canChange($actor, $batch)) {
            throw new RuntimeException('Only the person who uploaded the zips can start the upload.');
        }

        return DB::transaction(function () use ($batch) {
            $locked = DocumentUploadBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! $locked->inReview()) {
                throw new RuntimeException('This upload has already started or ended.');
            }
            if (! self::itemsOf($locked->id)->where('status', 'ready')->exists()) {
                throw new RuntimeException('No file is ready to upload: choose a document type for the files that need one, or include skipped files.');
            }
            self::itemsOf($locked->id)->where('status', 'needs_type')
                ->update(['status' => 'skipped', 'flag' => 'no_type', 'reason' => 'Left out: no document type was chosen.', 'updated_at' => now()]);
            $locked->withAuditReason('Bulk upload started')->update(['status' => 'queued']);
            $this->refreshCounts($locked);
            RunDocumentBulkUpload::dispatch($locked->id)->afterCommit();

            return $locked->refresh();
        });
    }

    /** Throw away an upload that has not started: nothing is uploaded, the zips are removed. */
    public function discard(DocumentUploadBatch $batch, User $actor): DocumentUploadBatch
    {
        if (! self::canChange($actor, $batch)) {
            throw new RuntimeException('Only the person who uploaded the zips can discard them.');
        }
        if (! $batch->inReview()) {
            throw new RuntimeException('This upload has already started or ended.');
        }
        $batch->withAuditReason('Discarded before uploading')->update(['status' => 'discarded', 'finished_at' => now()]);
        $this->removeSources($batch);

        return $batch->refresh();
    }

    // ------------------------------------------------------------------ run

    /** The job: upload every ready file as the person who started the upload. */
    public function run(DocumentUploadBatch $batch): DocumentUploadBatch
    {
        $claimed = DocumentUploadBatch::query()->whereKey($batch->id)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return $batch->refresh();
        }
        $batch->refresh();
        $actor = User::query()->whereKey($batch->created_by)->where('tenant_id', $batch->tenant_id)->first();
        if ($actor === null || $actor->status !== UserStatus::Active || ! self::mayUpload($actor)) {
            return $this->stop($batch, 'The person who started the upload can no longer upload documents in bulk.');
        }

        ScreenAccess::as($actor, fn () => $this->audit->operation('documents', 'Upload employee documents in bulk #'.$batch->id, function () use ($batch, $actor) {
            $done = ['uploaded' => 0, 'failed' => 0];
            // Several files of one type for one employee in this upload are named apart ("Aadhaar · front", "Aadhaar · back").
            $sameType = self::itemsOf($batch->id)->whereIn('status', ['ready', 'uploaded'])
                ->selectRaw('employee_id, document_type_id, count(*) as aggregate')->groupBy('employee_id', 'document_type_id')->toBase()->get()
                ->mapWithKeys(fn ($row) => [$row->employee_id.'|'.$row->document_type_id => (int) $row->aggregate])->all();
            $sources = (array) ($batch->sources ?? []);
            $groups = self::itemsOf($batch->id)->where('status', 'ready')
                ->select(['source', 'container_index'])->distinct()->orderBy('source')->orderBy('container_index')->toBase()->get();
            foreach ($groups as $group) {
                $source = $sources[(int) $group->source] ?? null;
                $container = $group->container_index === null ? null : (int) $group->container_index;
                $this->withZip($batch, $source, $container, function (?ZipArchive $zip, ?string $error) use ($batch, $actor, $group, $container, $sameType, &$done) {
                    self::itemsOf($batch->id)->where('status', 'ready')
                        ->where('source', $group->source)->where(fn ($q) => $container === null ? $q->whereNull('container_index') : $q->where('container_index', $container))
                        ->chunkById(100, function ($items) use ($batch, $actor, $zip, $error, $sameType, &$done) {
                            foreach ($items as $item) {
                                $done[$error === null && $this->uploadOne($batch, $item, $actor, $zip, $sameType) ? 'uploaded' : 'failed']++;
                                $error === null || $item->withoutAuditing()->update(['status' => 'failed', 'reason' => mb_substr($error, 0, 500)]);
                            }
                        });
                });
            }

            return ['succeeded' => $done['uploaded'], 'failed' => $done['failed']];
        }, entityType: 'document_upload_batch'));

        return $this->finish($batch, $actor);
    }

    /** The job's last attempt failed: the upload stops with the reason, files still waiting are marked failed. */
    public function markFailed(int $batchId, string $error): void
    {
        $batch = DocumentUploadBatch::query()->whereKey($batchId)->whereIn('status', ['queued', 'running'])->first();
        if ($batch !== null) {
            $this->stop($batch, $error);
        }
    }

    /** Every file and what happened to it (or will), for the results sheet. */
    public function resultsCsv(DocumentUploadBatch $batch): string
    {
        $sources = (array) ($batch->sources ?? []);
        $rows = [['Zip', 'Employee folder', 'File', 'Employee code', 'Employee', 'Document type', 'Expires on', 'Status', 'Note']];
        DocumentUploadItem::query()->with(['employee.person', 'type'])->where('document_upload_batch_id', $batch->id)->orderBy('id')
            ->chunkById(500, function ($items) use ($sources, &$rows) {
                foreach ($items as $item) {
                    $rows[] = [
                        (string) ($sources[$item->source]['name'] ?? ''), (string) $item->folder, (string) $item->file_name, (string) ($item->employee?->employee_code ?? $item->employee_code ?? ''),
                        (string) ($item->employee?->display_name ?? ''), (string) ($item->type?->name ?? ''), (string) ($item->expires_on?->toDateString() ?? ''),
                        DocumentUploadItem::STATUSES[$item->status] ?? $item->status, (string) ($item->reason ?? ''),
                    ];
                }
            });

        return self::csv($rows);
    }

    /** The organisation's active document types and the file names each is recognised by (the naming guide). */
    public function namesGuideCsv(): string
    {
        $categories = (array) config('peopleos.documents.categories', []);
        $rows = [['Document type', 'Code', 'Category', 'Has an expiry date', 'File names it is recognised by (any case, spaces, - or _)']];
        foreach (DocumentType::query()->where('status', 'active')->orderBy('category')->orderBy('name')->get() as $type) {
            $names = array_values(array_unique([...array_keys(DocumentNameMatcher::ownPhrases($type)), ...array_map(fn (string $a) => implode(' ', DocumentNameMatcher::tokens($a)), DocumentNameMatcher::aliasesOf($type))]));
            $rows[] = [(string) $type->name, (string) $type->code, (string) ($categories[$type->category] ?? $type->category), $type->requires_expiry ? 'Yes' : 'No', implode(' | ', $names)];
        }

        return self::csv($rows);
    }

    /**
     * A sample to copy: one zip holding two employees' zips named "code_name", with files named as people usually do.
     * Upload the two zips inside, or this zip as it is.
     */
    public static function sampleZip(): string
    {
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
            ."4 0 obj<</Length 44>>stream\nBT /F1 18 Tf 30 70 Td (Sample document) Tj ET\nendstream endobj\n"
            ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        $employees = [
            'EMP001_Asha Rao' => ['Aadhaar card.pdf', 'PAN card.pdf', '10th marksheet.pdf', '12th marksheet.pdf', 'Graduation degree.pdf', 'Passport valid till 2031-03-31.pdf'],
            'EMP002_Ravi Kumar' => ['Aadhar front.pdf', 'Aadhar back.pdf', 'Pan.pdf', 'Experience letter.pdf', 'Relieving letter.pdf', 'Cancelled cheque.pdf', 'Photo.pdf'],
        ];
        $outer = (string) tempnam(sys_get_temp_dir(), 'peopleos-doc-sample-');
        $zip = new ZipArchive;
        $zip->open($outer, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $inners = [];
        foreach ($employees as $folder => $files) {
            $inners[] = $inner = (string) tempnam(sys_get_temp_dir(), 'peopleos-doc-sample-');
            $employeeZip = new ZipArchive;
            $employeeZip->open($inner, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($files as $file) {
                $employeeZip->addFromString($file, $pdf);
            }
            $employeeZip->close();
            $zip->addFile($inner, $folder.'.zip');
        }
        $zip->close();
        $content = (string) file_get_contents($outer);
        foreach ([$outer, ...$inners] as $path) {
            @unlink($path);
        }

        return $content;
    }

    // ------------------------------------------------------------------ scanning

    /**
     * Read every zip and turn each file in it into an item (as arrays ready to insert).
     *
     * @param  list<UploadedFile>  $zips
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private function scan(array $zips, array $options): array
    {
        $zipMax = 1024 * self::limit('zip_max_kb', 12288);
        $scanned = [];
        $labels = [];
        foreach ($zips as $i => $zip) {
            $name = basename((string) $zip->getClientOriginalName());
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
                throw new RuntimeException("“{$name}” is not a .zip file. Zip each employee's documents and upload the zips.");
            }
            if ((int) $zip->getSize() > $zipMax) {
                throw new RuntimeException("“{$name}” is larger than ".self::mb($zipMax).': split it into smaller zips.');
            }
            $entries = $this->readZip((string) $zip->getRealPath(), $name);
            $stem = trim(pathinfo($name, PATHINFO_FILENAME));
            $scanned[] = ['source' => $i, 'name' => $name, 'stem' => $stem, 'entries' => $entries, 'path' => (string) $zip->getRealPath()];
            $labels[$stem] = true;
            // Folders and zips a zip of many employees may hold (with or without one folder around them all).
            foreach ($entries as $entry) {
                $parts = explode('/', $entry['path']);
                if (count($parts) > 1 || self::isZip($parts[0])) {
                    $labels[self::stemOf($parts[0])] = true;
                }
                if (count($parts) > 2 || (count($parts) === 2 && self::isZip($parts[1]))) {
                    $labels[self::stemOf($parts[1])] = true;
                }
            }
        }
        $people = $this->resolveLabels(array_keys($labels));

        $items = [];
        $seen = [];
        foreach ($scanned as $zip) {
            foreach ($this->groups($zip, $people) as $group) {
                foreach ($group['entries'] as $entry) {
                    $items[] = $this->item($zip['source'], $group, $entry, $seen);
                    if (count($items) > ($most = self::limit('files_max', 5000))) {
                        throw new RuntimeException("The zips hold more than {$most} files: upload them in several goes.");
                    }
                }
            }
        }
        if ($items === []) {
            throw new RuntimeException('The zips hold no files.');
        }

        return $this->checkOnFile($items, $options);
    }

    /**
     * The employee groups of one zip: the whole zip when its name is an employee's ("EMP001_Asha Rao.zip"); otherwise one
     * group per folder or zip inside it named for an employee (a single folder around them all is looked through).
     *
     * @param  array<string, mixed>  $zip
     * @param  array<string, array<string, mixed>>  $people
     * @return list<array<string, mixed>>
     */
    private function groups(array $zip, array $people): array
    {
        $own = $people[$zip['stem']] ?? null;
        // A zip named with an employee's code is that employee's (its files held back when the name does not match).
        if ($own !== null && ($own['employee'] !== null || ($own['found'] ?? false))) {
            return [['container' => null, 'container_index' => null, 'folder' => $zip['stem'], 'person' => $own,
                'entries' => array_map(fn (array $e) => $e + ['nested' => self::isZip($e['name'])], $zip['entries'])]];
        }

        // One folder around everything ("Documents/EMP001_Asha Rao/…") is looked through, unless it is an employee's.
        $entries = $zip['entries'];
        $prefix = '';
        $firsts = array_unique(array_map(fn (array $e) => explode('/', $e['path'])[0], $entries));
        if (count($firsts) === 1 && ! array_filter($entries, fn (array $e) => ! str_contains($e['path'], '/'))
            && ($people[self::stemOf(reset($firsts))]['employee'] ?? null) === null) {
            $prefix = reset($firsts).'/';
        }

        $groups = [];
        foreach ($entries as $entry) {
            $relative = substr($entry['path'], strlen($prefix));
            if (! str_contains($relative, '/')) {
                if (self::isZip($entry['name'])) {
                    $groups['zip:'.$entry['path']] = $this->innerZipGroup($zip, $entry, $people);
                } else {
                    $groups['loose'] ??= ['container' => null, 'container_index' => null, 'folder' => '(not in a folder) '.$zip['name'], 'entries' => [],
                        'person' => ['employee' => null, 'found' => false, 'code' => null, 'name' => null, 'flag' => null,
                            'reason' => 'This file is not in an employee\'s zip or folder. Put each employee\'s files in a zip or folder named employee code_First_Last name, e.g. EMP001_Asha_Rao.']];
                    $groups['loose']['entries'][] = $entry + ['nested' => false];
                }

                continue;
            }
            $folder = explode('/', $relative)[0];
            $groups['folder:'.$folder] ??= ['container' => null, 'container_index' => null, 'folder' => $folder, 'entries' => [], 'person' => $people[self::stemOf($folder)] ?? $this->resolveLabels([self::stemOf($folder)])[self::stemOf($folder)]];
            $groups['folder:'.$folder]['entries'][] = $entry + ['nested' => self::isZip($entry['name'])];
        }

        // No employee folders or zips inside, only files: it is one employee's zip, named for someone not found.
        if (array_keys($groups) === ['loose'] && $own !== null) {
            return [['container' => null, 'container_index' => null, 'folder' => $zip['stem'], 'person' => $own, 'entries' => $groups['loose']['entries']]];
        }

        return array_values($groups);
    }

    /**
     * A zip inside a zip of many employees: read once into a temporary file (never larger than a zip may be).
     *
     * @param  array<string, mixed>  $zip
     * @param  array<string, mixed>  $entry
     * @param  array<string, array<string, mixed>>  $people
     * @return array<string, mixed>
     */
    private function innerZipGroup(array $zip, array $entry, array $people): array
    {
        $folder = self::stemOf($entry['name']);
        $person = $people[$folder] ?? $this->resolveLabels([$folder])[$folder];
        $group = ['container' => $entry['path'], 'container_index' => $entry['index'], 'folder' => $folder, 'person' => $person, 'entries' => []];
        $outer = new ZipArchive;
        if ($outer->open($zip['path']) !== true) {
            throw new RuntimeException("“{$zip['name']}” could not be opened as a zip.");
        }
        $tmp = null;
        try {
            $tmp = $this->extract($outer, (int) $entry['index'], 1024 * self::limit('zip_max_kb', 12288));
            if ($tmp === null) {
                $person = ['employee' => null, 'found' => false, 'code' => $person['code'], 'name' => $person['name'], 'flag' => null, 'reason' => "“{$entry['name']}” could not be read, or is larger than ".self::mb(1024 * self::limit('zip_max_kb', 12288)).'.'];
                $group['person'] = $person;
                $group['entries'][] = ['index' => (int) $entry['index'], 'path' => $entry['path'], 'name' => $entry['name'], 'size' => $entry['size'], 'parent' => null, 'nested' => false];

                return $group;
            }
            foreach ($this->readZip($tmp, $entry['name']) as $inner) {
                $group['entries'][] = $inner + ['nested' => self::isZip($inner['name'])];
            }
        } finally {
            $outer->close();
            $tmp !== null && @unlink($tmp);
        }

        return $group;
    }

    /**
     * One file as an item to insert.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $entry
     * @param  array<string, string>  $seen  employee|name|size => where the same file was first seen
     * @return array<string, mixed>
     */
    private function item(int $source, array $group, array $entry, array &$seen): array
    {
        $person = $group['person'];
        /** @var Employee|null $employee */
        $employee = $person['employee'];
        $extension = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
        $allowed = (array) config('peopleos.documents.mimes', []);
        $max = 1024 * (int) config('peopleos.documents.max_kb', 10240);
        $fileProblem = match (true) {
            $entry['nested'] => 'A zip inside an employee\'s zip is not opened: put its files in the employee\'s zip itself.',
            ! in_array($extension, $allowed, true) => 'PeopleOS keeps '.implode(', ', $allowed).' files; this one is not one of them.',
            $entry['size'] <= 0 => 'The file is empty.',
            $entry['size'] > $max => 'The file is larger than '.self::mb($max).'.',
            default => null,
        };

        $row = [
            'source' => $source, 'container' => $group['container'] !== null ? mb_substr($group['container'], 0, 500) : null, 'container_index' => $group['container_index'],
            'entry' => mb_substr($entry['path'], 0, 1000), 'entry_index' => (int) $entry['index'], 'folder' => mb_substr((string) $group['folder'], 0, 255), 'file_name' => mb_substr($entry['name'], 0, 255),
            'size_bytes' => max(0, (int) $entry['size']), 'employee_code' => $employee?->employee_code ?? ($person['code'] !== null ? mb_substr((string) $person['code'], 0, 64) : null),
            'name_given' => filled($person['name']) ? mb_substr((string) $person['name'], 0, 255) : null, 'employee_id' => $employee?->id,
            'document_type_id' => null, 'matched_by' => null, 'issued_on' => null, 'expires_on' => null, 'status' => 'ready', 'flag' => null, 'reason' => null,
            'employee_document_id' => null, 'uploaded_at' => null,
        ];

        if ($employee !== null && $fileProblem === null) {
            $ignore = [(string) $employee->employee_code, (string) $person['name'], (string) $employee->person?->first_name, (string) $employee->person?->middle_name,
                (string) $employee->person?->last_name, (string) $employee->person?->preferred_name];
            $parent = $entry['parent'] ?? null;
            $match = $this->matcherFor($employee)->match($entry['name'], $ignore, $parent !== null && $parent !== $group['folder'] ? $parent : null);
            $row['document_type_id'] = $match['type']?->id;
            $row['matched_by'] = $match['by'];
            if ($match['type'] !== null) {
                $dates = DocumentNameMatcher::dates($entry['name']);
                $row['expires_on'] = $match['type']->requires_expiry ? $dates['expires_on'] : null;
                $row['issued_on'] = $dates['issued_on'];
            } else {
                $row['status'] = 'needs_type';
                $row['reason'] = $match['candidates'] !== []
                    ? 'The name fits several types: '.implode(' or ', array_slice($match['candidates'], 0, 4)).'. Choose one.'
                    : 'No document type matches this name. Choose one, or it is left out.';
            }
        }

        if ($fileProblem !== null) {
            return ['status' => 'blocked', 'reason' => $fileProblem, 'flag' => null] + $row;
        }
        if ($person['reason'] !== null) {
            return ['status' => 'blocked', 'reason' => mb_substr((string) $person['reason'], 0, 500), 'flag' => $person['flag']] + $row;
        }
        $key = $employee->id.'|'.mb_strtolower($entry['name']).'|'.$entry['size'];
        if (isset($seen[$key])) {
            return ['status' => 'skipped', 'flag' => 'duplicate', 'reason' => mb_substr('The same file is also in '.$seen[$key].'.', 0, 500)] + $row;
        }
        $seen[$key] = $group['folder'];

        return $row;
    }

    /**
     * Files of a type the employee already has: the same file (name and size) is skipped as already on file; any other is
     * skipped too when the person chose to keep what is on file.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private function checkOnFile(array $items, array $options): array
    {
        $ids = array_values(array_unique(array_filter(array_column($items, 'employee_id'))));
        $onFile = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            EmployeeDocument::query()->withoutGlobalScope(AccessScope::class)->with('type:id,name')->whereIn('employee_id', $chunk)->whereIn('status', DocumentRules::CURRENT)
                ->get(['id', 'employee_id', 'document_type_id', 'original_name', 'size_bytes'])
                ->each(function (EmployeeDocument $d) use (&$onFile) {
                    $onFile[$d->employee_id.'|'.$d->document_type_id][] = ['name' => mb_strtolower((string) $d->original_name), 'size' => (int) $d->size_bytes, 'type' => (string) $d->type?->name];
                });
        }
        foreach ($items as $i => $item) {
            if ($item['status'] !== 'ready') {
                continue;
            }
            $items[$i] = array_merge($item, self::onFileOutcome($onFile[$item['employee_id'].'|'.$item['document_type_id']] ?? [], $item['file_name'], (int) $item['size_bytes'], $options));
        }

        return $items;
    }

    /** @return array<string, mixed> the status a ready item takes given what the employee has on file */
    private function onFileCheck(DocumentUploadBatch $batch, DocumentUploadItem $item): array
    {
        $onFile = EmployeeDocument::query()->withoutGlobalScope(AccessScope::class)->with('type:id,name')->where('employee_id', $item->employee_id)
            ->where('document_type_id', $item->document_type_id)->whereIn('status', DocumentRules::CURRENT)->get(['id', 'document_type_id', 'original_name', 'size_bytes'])
            ->map(fn (EmployeeDocument $d) => ['name' => mb_strtolower((string) $d->original_name), 'size' => (int) $d->size_bytes, 'type' => (string) $d->type?->name])->all();

        return self::onFileOutcome($onFile, (string) $item->file_name, (int) $item->size_bytes, (array) ($batch->options ?? []));
    }

    /**
     * @param  list<array{name: string, size: int, type: string}>  $onFile
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function onFileOutcome(array $onFile, string $fileName, int $size, array $options): array
    {
        if ($onFile === []) {
            return [];
        }
        foreach ($onFile as $document) {
            if ($document['name'] === mb_strtolower($fileName) && $document['size'] === $size) {
                return ['status' => 'skipped', 'flag' => 'duplicate', 'reason' => 'Already on file: the employee has this same file as their '.$document['type'].'.'];
            }
        }
        if (($options['existing'] ?? 'version') === 'skip') {
            return ['status' => 'skipped', 'flag' => 'existing', 'reason' => 'The employee already has a '.$onFile[0]['type'].' on file; it is kept, as you chose.'];
        }

        return ['reason' => 'Becomes the new version of the '.$onFile[0]['type'].' on file (the earlier one is kept in its history).'];
    }

    /**
     * Who each zip or folder name is: the longest beginning of the name that is an employee code in the person's access
     * ("EMP-001_Asha Rao" → EMP-001), and whether the rest is the employee's name.
     *
     * @param  list<string>  $labels
     * @return array<string, array{employee: ?Employee, code: ?string, name: ?string, flag: ?string, reason: ?string}>
     */
    private function resolveLabels(array $labels): array
    {
        $labels = array_values(array_unique(array_filter(array_map('trim', $labels), fn (string $l) => $l !== '')));
        $candidates = [];
        foreach ($labels as $label) {
            foreach (self::codeCandidates($label) as $candidate) {
                $candidates[mb_strtolower($candidate)] = true;
            }
        }
        $employees = [];
        foreach (array_chunk(array_keys($candidates), 500) as $chunk) {
            Employee::query()->with('person')->whereIn(DB::raw('LOWER(employee_code)'), $chunk)->get()
                ->each(function (Employee $e) use (&$employees) {
                    $employees[mb_strtolower((string) $e->employee_code)] = $e;
                });
        }

        $out = [];
        foreach ($labels as $label) {
            $best = null;
            foreach (self::codeCandidates($label) as $candidate) {
                if (isset($employees[mb_strtolower($candidate)]) && ($best === null || mb_strlen($candidate) > mb_strlen($best))) {
                    $best = $candidate;
                }
            }
            if ($best === null) {
                $code = trim((string) preg_split('/[_\s]+/', $label, 2)[0]);
                $out[$label] = ['employee' => null, 'found' => false, 'code' => $code !== '' ? mb_substr($code, 0, 64) : null, 'name' => null, 'flag' => null,
                    'reason' => "No employee with the code “{$code}” in your access. Name each zip or folder employee code_First_Last name, e.g. EMP001_Asha_Rao."];

                continue;
            }
            /** @var Employee $employee */
            $employee = $employees[mb_strtolower($best)];
            $name = trim(mb_substr($label, mb_strlen($best)), " \t_-.");
            $found = Gate::allows('view', $employee);
            $expected = self::expectedNames($employee);
            $reason = match (true) {
                ! $found => "No employee with the code “{$best}” in your access.",
                $employee->lifecycle_state === LifecycleState::NotJoined => "{$employee->employee_code} never joined: documents are not added to them.",
                ! self::hasName($name) => "“{$label}” has the code but not the name. Name it {$expected}.",
                ! self::nameMatches($name, $employee) => "“{$name}” is not the name of {$employee->employee_code}, who is ".self::fullName($employee)." in PeopleOS. The code and the name must both match: name it {$expected}.",
                default => null,
            };
            $out[$label] = ['employee' => $reason === null ? $employee : null, 'found' => $found, 'code' => (string) $employee->employee_code, 'name' => $name !== '' ? $name : null, 'flag' => null, 'reason' => $reason];
        }

        return $out;
    }

    /**
     * The whole name, and each beginning of it that ends before an underscore or a space: the employee code comes first,
     * whole (owner, 2026-11-27: "match it with emp code strictly"), so "MK-99_Meera" is never read as the code MK.
     *
     * @return list<string>
     */
    private static function codeCandidates(string $label): array
    {
        $label = trim($label);
        $out = [$label];
        $length = mb_strlen($label);
        for ($i = 1; $i < $length; $i++) {
            if (in_array(mb_substr($label, $i, 1), ['_', ' '], true)) {
                $out[] = rtrim(mb_substr($label, 0, $i));
            }
        }

        return array_values(array_unique(array_filter($out, fn (string $c) => $c !== '' && mb_strlen($c) <= 64)));
    }

    /** The words of a zip's or folder's name after the code that may be a name (download copies' "(1)", "copy", "documents" left out). */
    private static function nameWords(string $given): array
    {
        return array_values(array_filter(DocumentNameMatcher::tokens($given), fn (string $w) => ! ctype_digit($w) && ! in_array($w, self::NAME_FILLERS, true)));
    }

    private static function hasName(string $given): bool
    {
        return self::nameWords($given) !== [];
    }

    /**
     * Whether the name after the code is the employee's name in PeopleOS (owner, 2026-11-27: "must match the emp id and
     * name"; "if there is no last name pick the first name only … match it with emp code strictly"): the first name alone,
     * or first and last name, with the middle name or without it, or the preferred name alone or with the last name. The
     * code before it is matched exactly, so the first name only is enough. Case, accents, spaces, underscores, hyphens
     * and dots do not matter, nor does the order of the names ("Rao_Asha", or "AshaRao" written as one word); a
     * different, partial or misspelt name does.
     */
    private static function nameMatches(string $given, Employee $employee): bool
    {
        $words = self::nameWords($given);
        $person = $employee->person;
        $first = DocumentNameMatcher::tokens((string) $person?->first_name);
        $middle = DocumentNameMatcher::tokens((string) $person?->middle_name);
        $last = DocumentNameMatcher::tokens((string) $person?->last_name);
        $preferred = DocumentNameMatcher::tokens((string) $person?->preferred_name);
        $forms = [$first, [...$first, ...$last]];
        $middle !== [] && $forms[] = [...$first, ...$middle, ...$last];
        $preferred !== [] && array_push($forms, $preferred, [...$preferred, ...$last]);
        $sorted = $words;
        sort($sorted);
        foreach ($forms as $form) {
            if ($form === []) {
                continue;
            }
            $expected = $form;
            sort($expected);
            if ($sorted === $expected || in_array(implode('', $words), [implode('', $form), implode('', array_reverse($form))], true)) {
                return true;
            }
        }

        return false;
    }

    /** How the zip may be named: "ME0001_Vikram_Singh or ME0001_Vikram" (code and first name, with the last name if any). */
    private static function expectedNames(Employee $employee): string
    {
        $person = $employee->person;
        $first = str_replace(' ', '_', trim((string) $person?->first_name));
        $last = str_replace(' ', '_', trim((string) $person?->last_name));
        $code = (string) $employee->employee_code;

        return $last !== '' ? "{$code}_{$first}_{$last} or {$code}_{$first}" : "{$code}_{$first}";
    }

    /** The employee's first, middle and last name as PeopleOS has them. */
    private static function fullName(Employee $employee): string
    {
        $person = $employee->person;

        return trim(implode(' ', array_filter([$person?->first_name, $person?->middle_name, $person?->last_name]))) ?: (string) $employee->display_name;
    }

    /** The active document types the employee's company uses, and a matcher over them (one per company). */
    private function matcherFor(Employee $employee): DocumentNameMatcher
    {
        return $this->matchers[(string) $this->companyOf($employee)] ??= new DocumentNameMatcher($this->typesFor($employee));
    }

    private function companyOf(Employee $employee): ?int
    {
        return array_key_exists($employee->id, $this->companies) ? $this->companies[$employee->id] : ($this->companies[$employee->id] = NumberSeries::companyOf($employee));
    }

    /** @return Collection<int, DocumentType> the active document types the employee's company uses (all, without an employee) */
    public function typesFor(?Employee $employee): Collection
    {
        $company = $employee !== null ? $this->companyOf($employee) : null;

        return DocumentType::query()->where('status', 'active')
            ->when($company, fn ($q, $id) => app(EntityApplicability::class)->constrain($q, $id))
            ->orderBy('name')->get()->toBase();
    }

    /**
     * The files of a zip (folders, hidden and macOS resource files left out), with their position in the zip (what is read),
     * their size and the folder they are in.
     *
     * @return list<array{index: int, path: string, name: string, size: int, parent: ?string}>
     */
    private function readZip(string $path, string $name): array
    {
        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            throw new RuntimeException("“{$name}” could not be opened as a zip.");
        }
        $entries = [];
        $bytes = 0;
        try {
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $stat = $archive->statIndex($i);
                $entryPath = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                if ($entryPath === '' || str_ends_with($entryPath, '/') || str_starts_with($entryPath, '__MACOSX/') || str_contains($entryPath, '/__MACOSX/')) {
                    continue;
                }
                $file = basename($entryPath);
                if ($file === '' || str_starts_with($file, '.') || str_starts_with($file, '~$') || in_array(mb_strtolower($file), self::JUNK, true)) {
                    continue;
                }
                $parent = str_contains($entryPath, '/') ? basename(dirname($entryPath)) : null;
                $entries[] = ['index' => (int) ($stat['index'] ?? $i), 'path' => $entryPath, 'name' => $file, 'size' => (int) ($stat['size'] ?? 0), 'parent' => $parent];
                $bytes += (int) ($stat['size'] ?? 0);
            }
        } finally {
            $archive->close();
        }
        if (count($entries) > ($most = self::limit('zip_max_entries', 2000))) {
            throw new RuntimeException("“{$name}” holds more than {$most} files: split it into smaller zips.");
        }
        if ($bytes > 1024 * self::limit('zip_max_uncompressed_kb', 512000)) {
            throw new RuntimeException("The files in “{$name}” are too large together: split it into smaller zips.");
        }

        return $entries;
    }

    // ------------------------------------------------------------------ running

    /**
     * Opens the zip a group of items is in (the uploaded zip, or a zip inside it) and hands it over; an error when it
     * cannot be read.
     *
     * @param  array<string, mixed>|null  $source
     */
    private function withZip(DocumentUploadBatch $batch, ?array $source, ?int $container, callable $work): void
    {
        $diskName = (string) ($batch->source_disk ?: config('peopleos.documents.disk'));
        $path = $source['path'] ?? null;
        if (! is_string($path) || $path === '' || ! Storage::disk($diskName)->exists($path)) {
            $work(null, 'The zip is no longer available.');

            return;
        }
        [$local, $temporary] = $this->localCopy($diskName, $path);
        $zip = new ZipArchive;
        $inner = null;
        $innerZip = null;
        try {
            if ($zip->open($local) !== true) {
                $work(null, 'The zip could not be opened.');

                return;
            }
            if ($container === null) {
                $work($zip, null);

                return;
            }
            $inner = $this->extract($zip, $container, 1024 * self::limit('zip_max_kb', 12288));
            $innerZip = new ZipArchive;
            if ($inner === null || $innerZip->open($inner) !== true) {
                $innerZip = null;
                $work(null, 'The employee\'s zip could not be read.');

                return;
            }
            $work($innerZip, null);
        } finally {
            $innerZip?->close();
            $zip->close();
            $inner !== null && @unlink($inner);
            $temporary && @unlink($local);
        }
    }

    /** @param  array<string, int>  $sameType */
    private function uploadOne(DocumentUploadBatch $batch, DocumentUploadItem $item, User $actor, ?ZipArchive $zip, array $sameType): bool
    {
        $fail = function (string $reason) use ($item): bool {
            $item->withoutAuditing()->update(['status' => 'failed', 'reason' => mb_substr($reason, 0, 500)]);

            return false;
        };
        if ($zip === null) {
            return false;
        }
        $employee = $item->employee_id !== null ? Employee::query()->with('person')->find($item->employee_id) : null;
        if ($employee === null || Gate::forUser($actor)->denies('view', $employee)) {
            return $fail('This employee is no longer in your access.');
        }
        $type = DocumentType::query()->where('status', 'active')->find($item->document_type_id);
        if ($type === null) {
            return $fail('The document type is no longer active.');
        }
        $max = 1024 * (int) config('peopleos.documents.max_kb', 10240);
        $tmp = $this->extract($zip, (int) $item->entry_index, $max);
        if ($tmp === null) {
            return $fail('The file could not be read from the zip, or is larger than '.self::mb($max).'.');
        }

        try {
            $several = ($sameType[$employee->id.'|'.$type->id] ?? 1) > 1;
            $title = $several ? mb_substr($type->name.' · '.pathinfo((string) $item->file_name, PATHINFO_FILENAME), 0, 255) : $type->name;
            // The first file of a type in this upload replaces what is on file (unless kept); the next ones go beside it.
            $supersede = ! self::itemsOf($batch->id)->where('employee_id', $employee->id)
                ->where('document_type_id', $type->id)->where('status', 'uploaded')->exists();
            $mime = FileSafety::sniffFile($tmp) ?? 'application/octet-stream';
            DB::transaction(function () use ($batch, $item, $employee, $type, $tmp, $mime, $title, $supersede) {
                $document = $this->documents->store($employee, new UploadedFile($tmp, (string) $item->file_name, $mime, null, true), $type, $title,
                    $item->expires_on?->toDateString(), $item->issued_on?->toDateString(), "Bulk upload #{$batch->id}", supersede: $supersede, notify: (bool) $batch->option('notify', false));
                $item->withoutAuditing()->update(['status' => 'uploaded', 'reason' => null, 'employee_document_id' => $document->id, 'uploaded_at' => now()]);
            });

            return true;
        } catch (RuntimeException $e) {
            return $fail($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $fail('The file could not be stored.');
        } finally {
            @unlink($tmp);
        }
    }

    private function finish(DocumentUploadBatch $batch, User $actor): DocumentUploadBatch
    {
        $batch->withoutAuditing()->update(['status' => 'completed', 'finished_at' => now()]);
        $this->refreshCounts($batch);
        $this->removeSources($batch);
        $batch->refresh();
        rescue(fn () => $this->notifier->send([$actor], ['in_app'], 'Employee documents uploaded', $batch->summary()
            .($batch->failed > 0 ? '. Open the upload to see each file that failed and why, or download the results.' : '.'), self::DONE_EVENT, $batch), report: true);

        return $batch;
    }

    private function stop(DocumentUploadBatch $batch, string $error): DocumentUploadBatch
    {
        self::itemsOf($batch->id)->where('status', 'ready')
            ->update(['status' => 'failed', 'reason' => mb_substr('Not uploaded: '.$error, 0, 500), 'updated_at' => now()]);
        $batch->withoutAuditing()->update(['status' => 'failed', 'error' => mb_substr($error, 0, 500), 'finished_at' => now()]);
        $this->refreshCounts($batch);
        $this->removeSources($batch);

        return $batch->refresh();
    }

    // ------------------------------------------------------------------ helpers

    /** Every file of an upload, whatever the signed-in person's access scope (counts and the run; each file is checked when stored). */
    private static function itemsOf(int $batchId): Builder
    {
        return DocumentUploadItem::query()->withoutGlobalScope(AccessScope::class)->where('document_upload_batch_id', $batchId);
    }

    /** Recount the items by status onto the batch. */
    public function refreshCounts(DocumentUploadBatch $batch): DocumentUploadBatch
    {
        $counts = self::itemsOf($batch->id)
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->toBase()->get()->pluck('aggregate', 'status')->map(fn ($n) => (int) $n);
        $batch->withoutAuditing()->update([
            'file_count' => (int) $counts->sum(), 'ready' => (int) ($counts['ready'] ?? 0), 'needs_type' => (int) ($counts['needs_type'] ?? 0), 'skipped' => (int) ($counts['skipped'] ?? 0),
            'blocked' => (int) ($counts['blocked'] ?? 0), 'uploaded' => (int) ($counts['uploaded'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0),
        ]);

        return $batch->refresh();
    }

    /** Uploads left unchecked for REVIEW_DAYS days are discarded, and their zips removed. */
    public function discardStale(): int
    {
        $stale = DocumentUploadBatch::query()->where('status', 'review')->where('created_at', '<', now()->subDays(self::REVIEW_DAYS))->get();
        foreach ($stale as $batch) {
            $batch->withoutAuditing()->update(['status' => 'discarded', 'finished_at' => now(), 'error' => 'Not checked within '.self::REVIEW_DAYS.' days: discarded.']);
            $this->removeSources($batch);
        }

        return $stale->count();
    }

    private function openBatch(DocumentUploadItem $item, User $actor): DocumentUploadBatch
    {
        $batch = DocumentUploadBatch::query()->findOrFail($item->document_upload_batch_id);
        if (! self::canChange($actor, $batch)) {
            throw new RuntimeException('Only the person who uploaded the zips can change what is uploaded.');
        }
        if (! $batch->inReview()) {
            throw new RuntimeException('This upload has already started or ended.');
        }

        return $batch;
    }

    private function removeSources(DocumentUploadBatch $batch): void
    {
        $disk = Storage::disk($batch->source_disk ?: (string) config('peopleos.documents.disk'));
        $sources = (array) ($batch->sources ?? []);
        foreach ($sources as $i => $source) {
            if (filled($source['path'] ?? null)) {
                $disk->delete((string) $source['path']);
            }
            $sources[$i]['path'] = null;
        }
        $batch->withoutAuditing()->forceFill(['sources' => $sources])->save();
    }

    /** @return array{0: string, 1: bool} a local path of the stored zip, and whether it is a temporary copy */
    private function localCopy(string $diskName, string $path): array
    {
        $disk = Storage::disk($diskName);
        $local = rescue(fn () => $disk->path($path), null, false);
        if (is_string($local) && is_file($local)) {
            return [$local, false];
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'peopleos-doc-upload-');
        $in = $disk->readStream($path) ?: throw new RuntimeException('The zip is no longer available.');
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out);
        is_resource($in) && fclose($in);

        return [$tmp, true];
    }

    /**
     * One file of a zip, by its position, copied into a temporary file (never to a path taken from the zip), at most
     * $maxBytes; null when it cannot be read or is larger.
     */
    private function extract(ZipArchive $zip, int $index, int $maxBytes): ?string
    {
        $in = $zip->getStreamIndex($index);
        if (! is_resource($in)) {
            return null;
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'peopleos-doc-upload-');
        $out = fopen($tmp, 'wb');
        $copied = stream_copy_to_stream($in, $out, $maxBytes + 1);
        fclose($out);
        fclose($in);
        if ($copied === false || $copied > $maxBytes) {
            @unlink($tmp);

            return null;
        }

        return $tmp;
    }

    private static function isZip(string $name): bool
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip';
    }

    private static function stemOf(string $name): string
    {
        return trim(self::isZip($name) ? pathinfo($name, PATHINFO_FILENAME) : $name);
    }

    private static function date(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            throw new RuntimeException('Give dates as YYYY-MM-DD.');
        }
    }

    private static function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.').' MB';
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
