<?php

namespace App\Domain\Compliance\Contracts;

use App\Domain\Compliance\Models\StatutoryReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One statutory output type (EPF, ESI, PT, LWF, TDS). The lifecycle (StatutoryReturns) owns
 * status, separation of duties and filing events; a generator owns content: it builds entries and
 * snapshots from finalized payroll only, validates them, reconciles them with payroll and renders
 * the export file. Generators never write payroll.
 */
interface StatutoryReturnGenerator
{
    public function type(): string;

    /** Replace the return's entries, snapshots and totals from finalized payroll. */
    public function build(StatutoryReturn $return): void;

    /** @return list<array{code: string, severity: 'blocking'|'warning', message: string, employee_id?: int|null}> */
    public function validate(StatutoryReturn $return): array;

    /** @return list<array{check: string, expected: float|int, actual: float|int, difference: float|int, blocking: bool}> */
    public function reconcile(StatutoryReturn $return): array;

    /** @return array{filename: string, content: string} */
    public function export(StatutoryReturn $return): array;

    /** Entries of the return (for screens and the read-only API). */
    public function entries(StatutoryReturn $return): Builder;

    /** API / screen representation of one entry; identifiers masked unless $unmasked. */
    public function present(Model $entry, bool $unmasked): array;

    /** Part M: write one immutable snapshot per entry (called once, when the return is approved). */
    public function captureSnapshots(StatutoryReturn $return): int;
}
