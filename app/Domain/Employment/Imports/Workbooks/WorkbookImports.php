<?php

namespace App\Domain\Employment\Imports\Workbooks;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Jobs\RunWorkbookImport;
use App\Domain\Experience\Services\ScreenAccess;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Support\Storage\ScratchSpace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Master uploads (workbooks): one Excel workbook whose worksheets are existing uploads, checked and run together in
 * the order their dependencies need. Built on the staged upload pipeline rather than beside it:
 *
 *  - the workbook is an upload of a workbook_* type (employee_imports), so approval rules (self, maker-checker,
 *    workflow), events, Recent uploads and the audit trail apply to it as to any upload;
 *  - each worksheet with rows becomes an ordinary upload of its own type pointing at the workbook (parent_id, sheet,
 *    stage), staged, matched and checked by that type's own service, with its own permissions, rules and write path;
 *  - the workbook adds only what one file of many kinds needs: reading Excel (WorkbookReader), the run order
 *    (DependencyPlanner), references to records an earlier worksheet adds (PlannedReferences), one approval for all of
 *    it (the strictest rule of its worksheets: ImportGovernance::modeFor) and a queued, resumable run (RunWorkbookImport).
 *
 * Status: uploaded → inspected (worksheets staged) → validated (every row checked) → approved → importing → imported.
 */
final class WorkbookImports
{
    /** The column the reader adds to every worksheet's rows: the row's number in the worksheet the person sees. */
    public const ROW_COLUMN = 'Sheet row';

    /** What a workbook can be for: every worksheet whose upload asks (new or existing records) takes this choice. */
    public const MODES = [
        'both' => ['Add and update', 'Adds what is not in PeopleOS yet and updates what is. Rows already true are skipped.'],
        'new' => ['Add new records only', 'Rows for records already in PeopleOS are skipped and listed.'],
        'update' => ['Update existing records only', 'Rows for records not in PeopleOS yet are skipped and listed.'],
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    /** The workbook key of a workbook upload. */
    public static function key(EmployeeImport $import): string
    {
        return WorkbookCatalogue::keyOf((string) $import->type) ?? throw new RuntimeException('This upload is not a master upload.');
    }

    /** Whether this person may start a master upload of this type. */
    public static function allowed(?User $user, string $type): bool
    {
        $key = WorkbookCatalogue::keyOf($type);

        return $key !== null && WorkbookCatalogue::allowed($user, $key);
    }

    /**
     * Who sees a master upload: its uploader, or (under maker-checker or workflow approval) a checker once it is
     * checked — and only while they may make every kind of upload in it, since a workbook can hold bank details or pay.
     */
    public static function maySee(User $user, EmployeeImport $import): bool
    {
        if (! $user->hasPermission('employee.import')) {
            return false;
        }
        $own = (int) $import->uploaded_by === (int) $user->getKey();
        if (! $own && ! ImportGovernance::checkerMaySee($user, $import)) {
            return false;
        }
        foreach (self::types($import) as $type) {
            if (! ImportTypes::allowed($user, $type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The worksheets of a workbook upload, title => state, in run order. They are stored as a JSON object keyed by
     * title, and MySQL keeps JSON object keys in its own order (shortest first, so "Holidays" before "Holiday
     * calendars"): the order comes from each worksheet's stage and position, not from the stored order. Uploads checked
     * before position was kept fall back to the catalogue's order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sheetStates(EmployeeImport $master): array
    {
        $sheets = (array) ($master->options['sheets'] ?? []);
        $catalogue = array_flip(array_keys(WorkbookCatalogue::sheets(self::key($master))));
        $rank = fn (string $title) => [(int) ($sheets[$title]['stage'] ?? 0), $sheets[$title]['position'] ?? $catalogue[$title] ?? PHP_INT_MAX];
        uksort($sheets, fn (string $a, string $b) => $rank($a) <=> $rank($b));

        return $sheets;
    }

    /** @return list<string> the upload types of the worksheets a workbook upload holds */
    public static function types(EmployeeImport $import): array
    {
        return array_values(array_unique(array_filter(array_map(fn (array $sheet) => $sheet['type'] ?? null, (array) ($import->options['sheets'] ?? [])))));
    }

    // ---- the pipeline -------------------------------------------------------------------------------------------

    /** Keep an uploaded workbook (already on the private disk) as a master upload of this workbook. */
    public function register(string $key, string $path, string $originalName, User $user, ?string $reason = null, array $options = []): EmployeeImport
    {
        if (! WorkbookCatalogue::allowed($user, $key)) {
            throw new RuntimeException('You may not use this master upload.');
        }
        if (mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('A master upload is an Excel workbook (.xlsx). Download the template, fill it in and upload it.');
        }
        $disk = EmployeeImports::disk();
        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('The uploaded file could not be found on the private disk.');
        }
        $mode = ImportMode::tryFrom((string) ($options['mode'] ?? '')) ?? ImportMode::Both;
        $import = new EmployeeImport(['type' => WorkbookCatalogue::type($key), 'original_name' => $originalName, 'disk' => $disk, 'path' => $path,
            'size_bytes' => (int) Storage::disk($disk)->size($path), 'status' => 'uploaded', 'uploaded_by' => $user->getKey(),
            'options' => ['workbook' => $key, 'mode' => $mode->value]]);
        $import->withAuditReason($reason)->save();

        return $import;
    }

    /**
     * Read the workbook: every worksheet with rows becomes an upload of its own type (staged, columns matched by name),
     * in run order. A worksheet that cannot be taken (columns missing, an upload the person may not make) is marked
     * with the reason; it blocks approval until the workbook is fixed and uploaded again.
     */
    public function inspect(EmployeeImport $master): EmployeeImport
    {
        $this->assert($master, ['uploaded', 'inspected', 'validated']);
        $key = self::key($master);
        $user = User::query()->find($master->uploaded_by) ?? throw new RuntimeException('The uploader of this workbook no longer exists.');
        $this->discardChildren($master, 'The workbook was read again.');

        $temp = $this->tempDirectory();
        try {
            $local = $temp.'/workbook.xlsx';
            $source = Storage::disk($master->disk)->readStream($master->path) ?? throw new RuntimeException('The uploaded workbook could not be read.');
            $target = fopen($local, 'wb');
            stream_copy_to_stream($source, $target);
            fclose($target);
            is_resource($source) && fclose($source);

            $read = (new WorkbookReader)->read($local, $temp, (int) config('peopleos.imports.workbooks.max_sheet_rows', 20000));
            $definitions = WorkbookCatalogue::sheets($key);
            $titles = [];
            foreach (array_keys($definitions) as $title) {
                $titles[mb_strtolower($title)] = $title;
            }
            $guides = array_map('mb_strtolower', WorkbookCatalogue::GUIDE_SHEETS);
            $warnings = [];
            $found = [];
            foreach ($read as $name => $info) {
                $title = $titles[mb_strtolower(trim($name))] ?? null;
                if ($title === null) {
                    if ($info['rows'] > 0 && ! in_array(mb_strtolower(trim($name)), $guides, true)) {
                        $warnings[] = "Worksheet '{$name}' is not part of this master upload, so its {$info['rows']} row(s) were not read.";
                    }

                    continue;
                }
                if ($info['rows'] > 0) {
                    $found[$title] = $info;
                }
            }
            if ($found === []) {
                throw new RuntimeException('The workbook has no rows to import: fill in at least one worksheet under its header row.');
            }
            $total = array_sum(array_column($found, 'rows'));
            $max = (int) config('peopleos.imports.workbooks.max_rows', 50000);
            if ($total > $max) {
                throw new RuntimeException("The workbook has {$total} rows; one master upload takes up to {$max}. Split it into two workbooks.");
            }

            $populated = array_intersect_key($definitions, $found);
            $plan = DependencyPlanner::plan($populated);
            $sheets = [];
            foreach (array_values($plan['order']) as $position => $title) {
                $definition = $definitions[$title];
                $info = $found[$title];
                // position: the run order, kept with each worksheet (see sheetStates()).
                $state = ['title' => $title, 'process' => $definition['process']['key'], 'type' => $definition['type'], 'label' => $definition['process']['label'],
                    'stage' => $plan['stages'][$title], 'position' => $position, 'after' => $plan['after'][$title], 'rows' => $info['rows'], 'child' => null, 'problem' => null, 'warnings' => []];
                if ($info['duplicate_headers'] !== []) {
                    $state['problem'] = 'These columns appear more than once in the header row: '.implode(', ', $info['duplicate_headers']).'.';
                } elseif (! ImportCatalogue::allowed($user, $definition['process'])) {
                    $state['problem'] = "You may not make {$definition['process']['label']} uploads, so this worksheet cannot be imported. Remove its rows, or ask someone who may.";
                } else {
                    try {
                        [$child, $state['warnings']] = $this->stage($master, $title, $definition, (string) $info['csv'], (int) $plan['stages'][$title], $user);
                        $state['child'] = (int) $child->getKey();
                    } catch (RuntimeException $e) {
                        $state['problem'] = $e->getMessage();
                    }
                }
                $sheets[$title] = $state;
            }
        } finally {
            File::deleteDirectory($temp);
        }

        $master->update(['status' => 'inspected', 'row_count' => $total, 'valid_count' => 0, 'error_count' => 0, 'review_count' => 0, 'create_count' => 0, 'update_count' => 0, 'skip_count' => 0,
            'options' => array_merge($master->options ?? [], ['sheets' => $sheets, 'warnings' => $warnings, 'waiting' => 0])]);

        return $master->refresh();
    }

    /**
     * Check every row of every worksheet, in run order. A code that an earlier worksheet adds counts as there
     * (code-keyed masters are checked in full; other rows naming it wait, and are checked again before they run).
     */
    public function validate(EmployeeImport $master): EmployeeImport
    {
        $this->assert($master, ['inspected', 'validated']);
        $definitions = WorkbookCatalogue::sheets(self::key($master));
        $sheets = (array) ($master->options['sheets'] ?? []);
        $planned = app(PlannedReferences::class);
        $planned->begin();
        // One name session for the whole workbook: names an earlier worksheet adds count, one review covers it all.
        $references = app(ImportReferences::class);
        $references->begin($master, checking: true);
        try {
            foreach ($this->children($master) as $child) {
                $title = (string) $child->sheet;
                if (! isset($definitions[$title], $sheets[$title]) || $sheets[$title]['problem'] !== null) {
                    continue;
                }
                $planned->forSheet($definitions[$title]['needs']);
                try {
                    ImportTypes::service((string) $child->type)->validate($child);
                } catch (RuntimeException $e) {
                    $sheets[$title]['problem'] = $e->getMessage();

                    continue;
                }
                $this->publish($planned, $child->refresh(), $definitions[$title], $references);
            }
        } finally {
            $planned->end();
            $references->end();
        }

        $master->refresh();
        $totals = $this->totals($master);
        $master->update(['status' => 'validated', 'valid_count' => $totals['valid'], 'error_count' => $totals['error'], 'review_count' => $totals['review'],
            'create_count' => $totals['create'], 'update_count' => $totals['update'], 'skip_count' => $totals['skip'],
            'options' => array_merge($master->options ?? [], ['sheets' => $sheets, 'waiting' => $totals['waiting']])]);

        return $master->refresh();
    }

    /**
     * Read and check a just-uploaded workbook in the background (RunWorkbookImport, phase "check"): a large workbook is
     * never read inside a web request. The screen shows "checking" until the workbook is validated or failed.
     */
    public function queueCheck(EmployeeImport $master): EmployeeImport
    {
        $this->assert($master, ['uploaded', 'inspected', 'validated']);
        $master->update(['error' => null, 'options' => array_merge($master->options ?? [], ['checking' => true])]);
        if (config('peopleos.imports.workbooks.queue', true)) {
            RunWorkbookImport::dispatch((int) $master->getKey(), RunWorkbookImport::CHECK);
        } else {
            RunWorkbookImport::dispatchSync((int) $master->getKey(), RunWorkbookImport::CHECK);
        }

        return $master->refresh();
    }

    /** The check itself: read the worksheets, then check every row. A workbook that cannot be read says why. */
    public function check(EmployeeImport $master): EmployeeImport
    {
        if (! in_array($master->status, ['uploaded', 'inspected', 'validated'], true)) {
            return $master;
        }
        try {
            $this->validate($this->inspect($master));
        } catch (RuntimeException $e) {
            $master->refresh()->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);
        } finally {
            $options = $master->refresh()->options ?? [];
            unset($options['checking']);
            $master->update(['options' => $options]);
        }

        return $master->refresh();
    }

    /** Whether the workbook is being read and checked in the background. */
    public static function checking(EmployeeImport $master): bool
    {
        return (bool) ($master->options['checking'] ?? false) && in_array($master->status, ['uploaded', 'inspected', 'validated'], true);
    }

    /** One approval for the whole workbook, under the strictest approval rule of its worksheets. */
    public function approve(EmployeeImport $master, User $user, ?string $reason = null): EmployeeImport
    {
        $this->assert($master, ['validated']);
        if (($blocked = self::blockedSheets($master)) !== []) {
            throw new RuntimeException('Some worksheets cannot be imported ('.implode(', ', $blocked).'): fix them and upload the workbook again.');
        }
        foreach ($this->children($master) as $child) {
            if ((int) $child->review_count > 0) {
                throw new RuntimeException("{$child->sheet}: {$child->review_count} row(s) may be people already on file. Decide them on the worksheet's upload first.");
            }
            if (! ImportTypes::allowed($user, (string) $child->type)) {
                throw new RuntimeException('Approving this workbook needs the right to make '.ImportTypes::label((string) $child->type).'s.');
            }
        }
        if ((int) $master->create_count + (int) $master->update_count + (int) ($master->options['waiting'] ?? 0) === 0) {
            throw new RuntimeException('Nothing to import: no row would add or change anything.');
        }
        $master->withAuditReason($reason)->update(['status' => 'approved', 'approved_by' => $user->getKey(), 'approved_at' => now()]);

        return $master->refresh();
    }

    /** Start the approved workbook's run in the background (the uploader or its approver). */
    public function start(EmployeeImport $master, User $user): EmployeeImport
    {
        $this->assert($master, ['approved']);
        if (! in_array((int) $user->getKey(), [(int) $master->uploaded_by, (int) $master->approved_by], true)) {
            throw new RuntimeException('Only the person who uploaded the workbook or approved it starts its run.');
        }
        $master->update(['status' => 'importing', 'options' => array_merge($master->options ?? [], ['progress' => ['sheet' => null, 'done' => []], 'started_by' => (int) $user->getKey()])]);
        if (config('peopleos.imports.workbooks.queue', true)) {
            RunWorkbookImport::dispatch((int) $master->getKey());
        } else {
            RunWorkbookImport::dispatchSync((int) $master->getKey());
        }

        return $master->refresh();
    }

    /** The pipeline's "run" step for callers that treat every upload alike: a workbook runs in the background. */
    public function run(EmployeeImport $master, User $user): EmployeeImport
    {
        return $this->start($master, $user);
    }

    /**
     * The run (RunWorkbookImport): each worksheet in order, as the uploader. A worksheet that ran after another one of
     * this workbook is checked again first, now that what it refers to exists; rows that had errors in the preview stay
     * out. A retry carries on where the last attempt stopped: finished worksheets and rows are not run twice.
     */
    public function execute(EmployeeImport $master): EmployeeImport
    {
        if ($master->status !== 'importing') {
            return $master;
        }
        $lock = Cache::lock('workbook-import:'.$master->getKey(), 3900);
        if (! $lock->get()) {
            return $master;
        }
        try {
            $actor = User::query()->find($master->uploaded_by);
            $refusal = match (true) {
                $actor === null || (int) $actor->tenant_id !== (int) $master->tenant_id || $actor->status !== UserStatus::Active => 'The person who uploaded this workbook can no longer act.',
                default => collect($this->children($master))->map(fn (EmployeeImport $c) => ImportTypes::allowed($actor, (string) $c->type) ? null : ImportTypes::label((string) $c->type))->filter()->first(),
            };
            if ($refusal !== null) {
                $refusal = str_starts_with($refusal, 'The person') ? $refusal : "The uploader may no longer make {$refusal}s.";
                $master->update(['status' => 'failed', 'error' => $refusal, 'imported_at' => now()]);

                return $master->refresh();
            }

            $operationId = ScreenAccess::as($actor, fn () => $this->audit->operation('employment', "Master upload #{$master->getKey()} ({$master->original_name})", function () use ($master, $actor) {
                foreach ($this->children($master) as $child) {
                    $this->runSheet($master, $child, $actor);
                }
                $totals = $this->totals($master);

                return ['succeeded' => $totals['imported_create'] + $totals['imported_update'] + $totals['imported_skip'], 'failed' => $totals['failed']];
            }, reason: 'Master upload '.$master->original_name, entityType: EmployeeImport::class));

            $totals = $this->totals($master);
            $nothing = $totals['imported_create'] + $totals['imported_update'] === 0 && $totals['failed'] > 0;
            $master->update(['status' => $nothing ? 'failed' : 'imported', 'imported_at' => now(), 'operation_id' => $operationId,
                'create_count' => $totals['imported_create'], 'update_count' => $totals['imported_update'], 'skip_count' => $totals['imported_skip'], 'failure_count' => $totals['failed'],
                'error' => $nothing ? 'Nothing could be imported: see the error workbook.' : null,
                'options' => array_merge($master->refresh()->options ?? [], ['progress' => ['sheet' => null, 'done' => array_keys(self::sheetStates($master))]])]);
        } finally {
            $lock->release();
        }

        return $master->refresh();
    }

    /** Drop a workbook that has not run (and its worksheet uploads). */
    public function discard(EmployeeImport $master, ?string $reason = null): EmployeeImport
    {
        if (in_array($master->status, ['importing', 'imported', 'failed'], true)) {
            throw new RuntimeException('A workbook that has run cannot be discarded; it is part of the audit trail.');
        }
        $master->withAuditReason($reason)->update(['status' => 'discarded']);
        $this->discardChildren($master, $reason ?? 'The workbook was discarded.');

        return $master->refresh();
    }

    // ---- what the screens show ------------------------------------------------------------------------------------

    /**
     * Each worksheet of a workbook upload with where it stands, in run order.
     *
     * @return list<array<string, mixed>>
     */
    public static function sheetSummaries(EmployeeImport $master): array
    {
        $children = EmployeeImport::query()->where('parent_id', $master->getKey())->where('status', '!=', 'discarded')->get()->keyBy('sheet');
        $waiting = EmployeeImportRow::query()->whereIn('employee_import_id', $children->pluck('id'))->where('action', 'waiting')->where('status', 'pending')
            ->selectRaw('employee_import_id, count(*) as n')->groupBy('employee_import_id')->pluck('n', 'employee_import_id');
        $done = EmployeeImportRow::query()->whereIn('employee_import_id', $children->pluck('id'))->where('status', '!=', 'pending')
            ->selectRaw('employee_import_id, count(*) as n')->groupBy('employee_import_id')->pluck('n', 'employee_import_id');
        $ids = $children->pluck('id');
        // MySQL keeps JSON as `"state": "blocked"`, SQLite as written (`"state":"blocked"`).
        $blocked = EmployeeImportRow::query()->whereIn('employee_import_id', $ids)->where('action', 'error')
            ->where(fn ($q) => $q->where('match', 'like', '%"state":"blocked"%')->orWhere('match', 'like', '%"state": "blocked"%'))
            ->selectRaw('employee_import_id, count(*) as n')->groupBy('employee_import_id')->pluck('n', 'employee_import_id');
        $imported = EmployeeImportRow::query()->whereIn('employee_import_id', $ids)->where('status', 'imported')
            ->selectRaw('employee_import_id, count(*) as n')->groupBy('employee_import_id')->pluck('n', 'employee_import_id');
        $out = [];
        foreach (self::sheetStates($master) as $title => $sheet) {
            $child = $children->get($title);
            $id = $child?->id;
            $errors = (int) ($child?->error_count ?? 0);
            $blockedRows = (int) ($id === null ? 0 : ($blocked[$id] ?? 0));
            $out[] = $sheet + [
                'checked' => (int) ($child?->create_count ?? 0) + (int) ($child?->update_count ?? 0) + (int) ($child?->skip_count ?? 0),
                'blocked' => $blockedRows, 'other_errors' => max(0, $errors - $blockedRows),
                'waits_for' => $child === null ? [] : self::waitsFor($child),
                'imported' => (int) ($id === null ? 0 : ($imported[$id] ?? 0)),
                'outcome' => self::outcome($child, (int) ($id === null ? 0 : ($imported[$id] ?? 0))),
                'child_status' => $child?->status,
                'create' => (int) ($child?->create_count ?? 0), 'update' => (int) ($child?->update_count ?? 0), 'skip' => (int) ($child?->skip_count ?? 0),
                'errors' => (int) ($child?->error_count ?? 0), 'review' => (int) ($child?->review_count ?? 0), 'failed' => (int) ($child?->failure_count ?? 0),
                'waiting' => (int) ($child === null ? 0 : ($waiting[$child->id] ?? 0)), 'done' => (int) ($child === null ? 0 : ($done[$child->id] ?? 0)),
                'error' => $child?->getAttributes()['error'] ?? null, 'child_model' => $child,
            ];
        }

        return $out;
    }

    /**
     * The earlier worksheets this one's rows wait for or are blocked by, with how many rows each: what the preview
     * shows as "waits for Employees (12 rows)".
     *
     * @return array<string, array{waiting: int, blocked: int}>
     */
    public static function waitsFor(EmployeeImport $child): array
    {
        $out = [];
        foreach ($child->rows()->where('match', 'like', '%depends_on%')->select(['id', 'match'])->lazy(500) as $row) {
            foreach ((array) ($row->match['depends_on'] ?? []) as $hit) {
                $sheet = (string) ($hit['sheet'] ?? '');
                $state = ($hit['state'] ?? '') === 'blocked' ? 'blocked' : 'waiting';
                $out[$sheet] ??= ['waiting' => 0, 'blocked' => 0];
                $out[$sheet][$state]++;
            }
        }

        return $out;
    }

    /**
     * How a worksheet ended: completed (every row went in or was already true), partially completed (some rows failed
     * or had errors and stayed out), failed (it did not run, or nothing it had to do went in), or not run yet.
     */
    public static function outcome(?EmployeeImport $child, int $imported): string
    {
        if ($child === null) {
            return 'not_run';
        }
        if ($child->status === 'failed') {
            return $imported > 0 ? 'partial' : 'failed';
        }
        if ($child->status !== 'imported') {
            return 'not_run';
        }
        $left = (int) $child->failure_count + (int) $child->error_count + $child->rows()->where('action', 'waiting')->where('status', 'pending')->count();

        return match (true) {
            $left === 0 => 'completed',
            $imported + (int) $child->skip_count > 0 => 'partial',
            default => 'failed',
        };
    }

    public const OUTCOMES = ['completed' => 'Completed', 'partial' => 'Partially completed', 'failed' => 'Failed', 'not_run' => 'Not run'];

    /** @return list<string> worksheets that cannot be imported as uploaded */
    public static function blockedSheets(EmployeeImport $master): array
    {
        return array_values(array_map(fn (array $s) => $s['title'], array_filter(self::sheetStates($master), fn (array $s) => ($s['problem'] ?? null) !== null)));
    }

    /** @return list<array{0: string, 1: string}> worksheet => problem, for the error workbook's Summary tab */
    public static function problems(EmployeeImport $master): array
    {
        $out = [];
        foreach ((array) ($master->options['warnings'] ?? []) as $warning) {
            $out[] = ['Workbook', (string) $warning];
        }
        foreach (self::sheetSummaries($master) as $sheet) {
            if (($sheet['problem'] ?? null) !== null) {
                $out[] = [$sheet['title'], (string) $sheet['problem']];
            }
            if (($sheet['error'] ?? null) !== null) {
                $out[] = [$sheet['title'], (string) $sheet['error']];
            }
            foreach ((array) ($sheet['warnings'] ?? []) as $warning) {
                $out[] = [$sheet['title'], (string) $warning];
            }
        }

        return $out;
    }

    public static function rowStatus(EmployeeImportRow $row): string
    {
        return match (true) {
            $row->status === 'failed' => 'Failed when run',
            $row->action === 'error' && collect((array) ($row->match['depends_on'] ?? []))->contains(fn ($h) => ($h['state'] ?? '') === 'blocked') => 'Blocked by an earlier worksheet',
            $row->action === 'error' => 'Has errors',
            $row->action === 'waiting' => 'Waits for an earlier worksheet',
            $row->status === 'imported' => 'Imported',
            default => ucfirst((string) $row->status),
        };
    }

    public static function rowProblem(EmployeeImportRow $row): string
    {
        $errors = (array) ($row->errors ?? []);
        if ($errors !== []) {
            return implode(' ', $errors);
        }
        if ($row->status === 'failed') {
            return (string) $row->result;
        }

        return (string) ($row->match['reason'] ?? $row->result ?? '');
    }

    // ---- internals -------------------------------------------------------------------------------------------------

    /**
     * Stage one worksheet as an upload of its own type and match its columns by name.
     *
     * @return array{0: EmployeeImport, 1: list<string>} the worksheet upload and warnings about columns it did not read
     */
    private function stage(EmployeeImport $master, string $title, array $definition, string $csv, int $stage, User $user): array
    {
        $type = $definition['type'];
        $process = $definition['process'];
        $path = dirname((string) $master->path).'/workbook-'.$master->getKey().'/'.Str::slug($title).'-'.Str::lower(Str::random(6)).'.csv';
        $stream = fopen($csv, 'rb');
        Storage::disk($master->disk)->put($path, $stream);
        is_resource($stream) && fclose($stream);

        $options = array_filter([
            'mode' => ImportTypes::modes($type) !== null ? (string) ($master->options['mode'] ?? 'both') : null,
            'defaults' => $process['defaults'] ?? null,
            'process' => $process['key'],
        ], fn ($v) => $v !== null && $v !== []);
        $child = ImportTypes::register($type, $path, "{$master->original_name} › {$title}", $user, $options);
        $child->update(['parent_id' => $master->getKey(), 'sheet' => $title, 'stage' => $stage, 'options' => array_merge($child->options ?? [], ['process' => $process['key'], 'workbook' => (int) $master->getKey()])]);

        $service = ImportTypes::service($type);
        $service->inspect($child);
        $child->refresh();
        $mapping = $service->suggestMapping(array_values(array_filter($child->headers ?? [], fn ($h) => $h !== self::ROW_COLUMN)));
        try {
            $service->map($child, $mapping);
        } catch (RuntimeException $e) {
            throw new RuntimeException('The columns do not match: '.$e->getMessage().' Use the header row of the template.');
        }
        $unread = array_values(array_diff($child->headers ?? [], array_keys($mapping), [self::ROW_COLUMN]));

        return [$child->refresh(), array_map(fn ($h) => "Column '{$h}' is not a column of this worksheet, so it was not read.", $unread)];
    }

    /** After a worksheet is checked: the keys it adds become usable by the worksheets after it (or blocked by errors). */
    private function publish(PlannedReferences $planned, EmployeeImport $child, array $definition, ImportReferences $references): void
    {
        if (($definition['provides'] ?? []) === []) {
            return;
        }
        $mapping = (array) ($child->mapping ?? []);
        $nameHeader = array_search('name', $mapping, true);
        foreach ($definition['provides'] as $kind => $field) {
            $header = array_search($field, $mapping, true);
            $class = WorkbookCatalogue::KINDS[$kind][1] ?? null;
            // Names first: a master row may leave its code blank (a code is made from the name), so its name is what later
            // worksheets type. Rows are planned (or blocked) under their code and under their name.
            $byName = $class !== null && $nameHeader !== false && $field === 'code';
            if ($header === false && ! $byName) {
                continue;
            }
            foreach ($child->rows()->select(['id', 'row_number', 'data', 'action'])->lazy(500) as $row) {
                $key = $header === false ? '' : trim((string) ($row->data[$header] ?? ''));
                $name = $byName ? trim((string) ($row->data[$nameHeader] ?? '')) : '';
                if ($key === '' && $name === '') {
                    continue;
                }
                $sheetRow = (int) ($row->data[self::ROW_COLUMN] ?? $row->row_number + 1);
                $keys = array_values(array_unique(array_filter([$key, $name], fn (string $k) => $k !== '')));
                if ($row->action === 'error') {
                    foreach ($keys as $k) {
                        $planned->block($kind, $k, (string) $child->sheet, $sheetRow);
                    }
                } elseif (in_array($row->action, $class !== null ? ['create', 'waiting', 'review'] : ['create', 'update', 'waiting', 'review'], true)) {
                    foreach ($keys as $k) {
                        $planned->plan($kind, $k, (string) $child->sheet, $sheetRow);
                    }
                    // Later worksheets may name it rather than code it.
                    if ($class !== null && $row->action !== 'review') {
                        $references->plan($class, $nameHeader === false ? null : (string) ($row->data[$nameHeader] ?? ''), $key !== '' ? $key : null);
                    }
                }
            }
        }
    }

    /** Run one worksheet of a running workbook. */
    private function runSheet(EmployeeImport $master, EmployeeImport $child, User $actor): void
    {
        $child->refresh();
        $title = (string) $child->sheet;
        $state = $master->options['sheets'][$title] ?? null;
        if ($state === null || ($state['problem'] ?? null) !== null || in_array($child->status, ['imported', 'failed', 'discarded'], true)) {
            return;
        }
        $this->progress($master, $title);
        $service = ImportTypes::service((string) $child->type);
        try {
            if ($child->status === 'importing') {
                if (! self::resumable((string) $child->type)) {
                    $this->failSheet($child, 'The run stopped part-way through this worksheet. It was not run again, so nothing is done twice: open the worksheet\'s upload to see which rows went in.');

                    return;
                }
                $service->run($child, $actor);

                return;
            }
            if ($child->status === 'approved') {
                $service->run($child, $actor);

                return;
            }
            if (($state['after'] ?? []) !== [] || $child->rows()->where('action', 'waiting')->exists()) {
                $this->recheck($child);
            }
            if ((int) $child->review_count > 0) {
                $this->failSheet($child, "{$child->review_count} row(s) may be people already on file and need a decision on the worksheet's upload; this worksheet did not run.");

                return;
            }
            if ((int) $child->create_count + (int) $child->update_count === 0) {
                $this->closeWithoutChanges($child);

                return;
            }
            $approver = User::query()->find($master->approved_by);
            $service->approve($child, $actor, "Approved with master upload #{$master->getKey()}".($approver ? ' by '.$approver->name : ''));
            if ($approver !== null && (int) $approver->getKey() !== (int) $actor->getKey()) {
                $child->refresh()->update(['approved_by' => $approver->getKey()]);
            }
            $service->run($child->refresh(), $actor);
        } catch (Throwable $e) {
            if (! $e instanceof RuntimeException) {
                report($e);
            }
            $this->failSheet($child->refresh(), Str::limit($e->getMessage(), 480));
        } finally {
            $this->progress($master, null, $title);
        }
    }

    /** Check a worksheet again before it runs; rows that had errors in the preview stay out. */
    private function recheck(EmployeeImport $child): void
    {
        $previous = $child->rows()->where('action', 'error')->get(['id', 'errors', 'match'])->keyBy('id');
        ImportTypes::service((string) $child->type)->validate($child);
        if ($previous->isNotEmpty()) {
            // Rows with errors in the preview stay out, with the reason the person saw (e.g. which earlier row blocked them).
            foreach ($previous->keys()->chunk(500) as $ids) {
                foreach (EmployeeImportRow::query()->whereIn('id', $ids->all())->get() as $row) {
                    $before = $previous[$row->id];
                    $row->update(['action' => 'error', 'errors' => $before->errors ?: ['This row had errors when the workbook was checked.'], 'match' => $before->match]);
                }
            }
            $counts = $child->rows()->reorder()->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action');
            $child->update(['error_count' => (int) ($counts['error'] ?? 0), 'create_count' => (int) ($counts['create'] ?? 0), 'update_count' => (int) ($counts['update'] ?? 0),
                'skip_count' => (int) ($counts['skip'] ?? 0), 'valid_count' => (int) (($counts['create'] ?? 0) + ($counts['update'] ?? 0) + ($counts['skip'] ?? 0))]);
        }
        $child->refresh();
    }

    /** A worksheet whose rows are all already true: close it without running anything. */
    private function closeWithoutChanges(EmployeeImport $child): void
    {
        $child->rows()->where('action', 'skip')->where('status', 'pending')->update(['status' => 'skipped', 'result' => 'Skipped: already on file with these values, or outside this workbook\'s choice.']);
        $child->update(['status' => 'imported', 'imported_at' => now()]);
    }

    private function failSheet(EmployeeImport $child, string $message): void
    {
        $child->rows()->whereIn('action', ['create', 'update', 'waiting'])->where('status', 'pending')->update(['status' => 'failed', 'result' => Str::limit($message, 500)]);
        $failed = $child->rows()->where('status', 'failed')->count();
        $child->update(['status' => 'failed', 'error' => Str::limit($message, 500), 'failure_count' => $failed, 'imported_at' => now()]);
    }

    /** Whether an upload type's run can carry on after an interruption without doing a row twice. */
    public static function resumable(string $type): bool
    {
        $class = ImportTypes::serviceClass($type);

        return method_exists($class, 'resumable') && $class::resumable();
    }

    private function progress(EmployeeImport $master, ?string $sheet, ?string $done = null): void
    {
        $master->refresh();
        $progress = (array) ($master->options['progress'] ?? ['sheet' => null, 'done' => []]);
        $progress['sheet'] = $sheet;
        if ($done !== null) {
            $progress['done'] = array_values(array_unique([...(array) ($progress['done'] ?? []), $done]));
        }
        $master->update(['options' => array_merge($master->options ?? [], ['progress' => $progress])]);
    }

    /** @return list<EmployeeImport> the workbook's worksheet uploads, in run order */
    private function children(EmployeeImport $master): array
    {
        return EmployeeImport::query()->where('parent_id', $master->getKey())->where('status', '!=', 'discarded')->orderBy('stage')->orderBy('id')->get()->all();
    }

    /** @return array<string, int> */
    private function totals(EmployeeImport $master): array
    {
        $children = $this->children($master);
        $ids = array_map(fn (EmployeeImport $c) => (int) $c->getKey(), $children);
        $byStatus = $ids === [] ? collect() : EmployeeImportRow::query()->whereIn('employee_import_id', $ids)->selectRaw('action, status, count(*) as n')->groupBy('action', 'status')->get();
        $count = fn (callable $where) => (int) $byStatus->filter($where)->sum('n');

        return [
            'valid' => array_sum(array_map(fn ($c) => (int) $c->valid_count, $children)),
            'error' => array_sum(array_map(fn ($c) => (int) $c->error_count, $children)),
            'review' => array_sum(array_map(fn ($c) => (int) $c->review_count, $children)),
            'create' => array_sum(array_map(fn ($c) => (int) $c->create_count, $children)),
            'update' => array_sum(array_map(fn ($c) => (int) $c->update_count, $children)),
            'skip' => array_sum(array_map(fn ($c) => (int) $c->skip_count, $children)),
            'waiting' => $count(fn ($r) => $r->action === 'waiting' && $r->status === 'pending'),
            'imported_create' => $count(fn ($r) => $r->status === 'imported' && $r->action === 'create'),
            'imported_update' => $count(fn ($r) => $r->status === 'imported' && $r->action === 'update'),
            'imported_skip' => $count(fn ($r) => $r->status === 'skipped'),
            'failed' => $count(fn ($r) => $r->status === 'failed'),
        ];
    }

    private function discardChildren(EmployeeImport $master, ?string $reason): void
    {
        foreach (EmployeeImport::query()->where('parent_id', $master->getKey())->whereNotIn('status', ['discarded', 'importing', 'imported', 'failed'])->get() as $child) {
            try {
                ImportTypes::service((string) $child->type)->discard($child, $reason);
            } catch (RuntimeException) {
                $child->update(['status' => 'discarded']);
            }
        }
    }

    private function tempDirectory(): string
    {
        $dir = ScratchSpace::path('workbook-tmp/'.Str::ulid());
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }

    private function assert(EmployeeImport $import, array $allowed): void
    {
        if (! $import->isWorkbook()) {
            throw new RuntimeException('This upload is not a master upload.');
        }
        if (! in_array($import->status, $allowed, true)) {
            throw new RuntimeException("This master upload is {$import->status}; expected ".implode(' or ', $allowed).'.');
        }
    }

    /** Re-exported so callers need not know the base classes: is this upload type a code-keyed master? */
    public static function codeKeyed(string $type): bool
    {
        return is_subclass_of(ImportTypes::serviceClass($type), MasterImports::class);
    }

    /** Whether rows of this upload type wait for earlier worksheets (uploads that do their own lookups). */
    public static function defers(string $type): bool
    {
        return is_subclass_of(ImportTypes::serviceClass($type), RowImports::class) && ! self::codeKeyed($type);
    }
}
