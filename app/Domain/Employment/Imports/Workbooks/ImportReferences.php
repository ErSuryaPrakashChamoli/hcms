<?php

namespace App\Domain\Employment\Imports\Workbooks;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Resolution\MasterCatalogue;
use App\Domain\Organisation\Resolution\MasterResolver;
use App\Domain\Organisation\Resolution\NameKey;
use App\Domain\Organisation\Resolution\Resolution;
use App\Domain\Organisation\Resolution\ResolutionSession;
use App\Domain\Organisation\Resolution\ResolutionStatus;
use Illuminate\Container\Attributes\Scoped;

/**
 * Name-based references in uploads: the bridge between the import call sites (MasterImports::lookup,
 * ReadsMasterRows::parentId, the employee and job-change uploads) and the resolver (MasterResolver). It is used only
 * AFTER the code lookup the uploads always did has found nothing, so a file of codes resolves exactly as before;
 * then a name, a "Name (CODE)" pair or a mapping someone confirmed in the review may match.
 *
 * One resolver session per upload check or run, opened by the pipeline (begin/end). A workbook opens one for all of
 * its worksheets, so a name an earlier worksheet adds counts (plan) and the review covers the whole workbook. The
 * session acts as the uploader: records outside their organisation access are never matched, suggested or named;
 * every lookup is bound to the organisation (the resolver fails closed without one). Ambiguous names and near misses
 * are never chosen: the row gets the resolver's message and the reference goes to the review (report()).
 */
#[Scoped]
final class ImportReferences
{
    private ?ResolutionSession $session = null;

    /** The upload whose check or run opened the session (the workbook for its worksheets). */
    private ?int $owner = null;

    private bool $checking = false;

    private int $depth = 0;

    public function begin(EmployeeImport $import, bool $checking): void
    {
        $this->depth++;
        if ($this->session !== null) {
            return; // a workbook's session serves its worksheets
        }
        $root = (int) ($import->getAttributes()['parent_id'] ?? $import->getKey());
        $this->session = app(MasterResolver::class)->session(User::query()->find($import->uploaded_by), 'employee_imports:'.$root);
        $this->owner = (int) $import->getKey();
        $this->checking = $checking;
    }

    /** Close the session; after a check, the references that need a person's decision are kept on the upload. */
    public function end(): void
    {
        $this->depth = max(0, $this->depth - 1);
        if ($this->depth > 0 || $this->session === null) {
            return;
        }
        if ($this->checking && ($import = EmployeeImport::query()->find($this->owner)) !== null) {
            // Kept whenever a name was involved: names that matched are shown for a glance, the rest for a decision. A file
            // of codes leaves the upload's options as they were.
            $review = $this->session->report()->forReview()->toArray();
            $options = $import->options ?? [];
            if (($review['entries'] ?? []) !== []) {
                $import->update(['options' => array_merge($options, ['references' => $review])]);
            } elseif (array_key_exists('references', $options)) {
                unset($options['references']);
                $import->update(['options' => $options]);
            }
        }
        $this->session = null;
        $this->owner = null;
    }

    public function active(): bool
    {
        return $this->session !== null;
    }

    /** Whether references to this model can be resolved by name (it is a registered master). */
    public function resolvable(string $class): bool
    {
        return $this->session !== null && app(MasterCatalogue::class)->has($class);
    }

    /**
     * Resolve a cell that is not a code on file: a name, "Name (CODE)", or a confirmed mapping. Null when name
     * resolution does not apply (no session, or not a registered master): the caller keeps its own message.
     */
    public function resolve(string $class, string $value, ?int $companyId = null, ?string $sheet = null, ?int $row = null, ?string $column = null): ?Resolution
    {
        if (! $this->resolvable($class)) {
            return null;
        }
        $value = trim($value);
        // "Finance (FIN)": the name and the code must be the same record. A name that itself ends in brackets wins.
        if (preg_match('/^(.*\S)\s*\(([^()]+)\)$/u', $value, $pair) === 1
            && $this->session->index($class)->byName(NameKey::key($value), $value) === []) {
            return $this->session->resolve($class, companyId: $companyId, name: trim($pair[1]), code: trim($pair[2]), sheet: $sheet, row: $row, column: $column);
        }

        return $this->session->resolve($class, value: $value, companyId: $companyId, sheet: $sheet, row: $row, column: $column);
    }

    /**
     * Whether a record found by its code may be used for the row's company (the applicability framework: company-owned
     * units, explicit company choices, later companies). True when the master is not company-applicable.
     */
    public function appliesTo(string $class, int $id, int $companyId): bool
    {
        if ($id <= 0 || $this->session === null || ! app(MasterCatalogue::class)->has($class)) {
            return true;
        }
        $spec = app(MasterCatalogue::class)->for($class);
        if (! $spec->applicability) {
            return true;
        }

        return $this->session->index($spec)->appliesTo($id, $companyId);
    }

    public function companyName(int $companyId): string
    {
        return (string) $this->session?->companyName($companyId);
    }

    /** Whether the text is the code of a record of this master the uploader can see (compared as the resolver does). */
    public function isCode(string $master, string $value): bool
    {
        return $this->resolvable($master) && $this->session->index($master)->byCode(NameKey::key($value), $value) !== null;
    }

    /** The code a record of this master is kept under (an employee's employee code), or null when it has none. */
    public function codeOf(string $master, int $id): ?string
    {
        if (! $this->resolvable($master) || $id <= 0) {
            return null;
        }
        $code = $this->session->index($master)->find($id)['code'] ?? null;

        return $code === null || $code === '' ? null : (string) $code;
    }

    /** The company of the position in force of the employee with this code (or work e-mail), when the uploader can see them. */
    public function companyOfEmployee(string $employee): ?int
    {
        if ($this->session === null || trim($employee) === '') {
            return null;
        }
        $index = $this->session->index(MasterCatalogue::EMPLOYEE);
        $id = $index->byCode(NameKey::key($employee), $employee);

        return $id === null ? null : ($index->find($id)['owner'] ?? null);
    }

    /** A record an earlier worksheet adds: its name (and code) resolve as planned in later worksheets. */
    public function plan(string $class, ?string $name, ?string $code, ?int $companyId = null): void
    {
        if ($this->resolvable($class) && (filled($name) || filled($code))) {
            $this->session->plan($class, $name, $code, $companyId);
        }
    }

    /** A rejected resolution's status, for callers that tell "needs a decision" from "does not exist". */
    public static function needsReview(?Resolution $resolution): bool
    {
        return $resolution !== null && in_array($resolution->status, [ResolutionStatus::Ambiguous, ResolutionStatus::Suggested, ResolutionStatus::Conflict], true);
    }
}
