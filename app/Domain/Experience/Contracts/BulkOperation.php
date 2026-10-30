<?php

namespace App\Domain\Experience\Contracts;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-1: one action a register can run on many records at once (tag: `peopleos.bulk_operations`).
 *
 * An operation is glue, never a second rule book: it names the permission it needs, asks its inputs once for the whole
 * run, and acts on each record through the record's owning service (Exits, Onboarding, Bgv, ConfirmationReviews …),
 * whose own checks still apply. BulkRuns decides the rest the same way for every operation: the person must hold
 * `bulk.run` and the operation's permission; each record must be reachable in the person's access scope and pass the
 * record's `view` policy; `refusal()` may refuse a record for the operation's own reason; whatever the service throws
 * (a RuntimeException / LogicException with a message) is reported as refused, never processed.
 */
interface BulkOperation
{
    /** Stable key, `{register}.{action}` (e.g. `employees.initiate_exit`). */
    public function key(): string;

    public function label(): string;

    /** One line shown above the confirmation (what happens to each record). */
    public function description(): string;

    /** @return class-string<Model> the records it acts on */
    public function model(): string;

    /** The permission the person needs (checked when offered, when started and again when the run executes). */
    public function permission(): string;

    public function icon(): string;

    public function color(): string;

    /**
     * Form components asked once for the whole run (Filament schema components).
     *
     * @return list<mixed>
     */
    public function schema(): array;

    /**
     * Normalise the run's input when it starts (throw a RuntimeException with a message when it cannot be used).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(array $data, User $actor): array;

    /** @return list<string> relations loaded with the records */
    public function with(): array;

    /** The operation's own reason this person may not act on this record (null = may). */
    public function refusal(Model $record, User $actor): ?string;

    /**
     * Act on one record through its owning service; return a short outcome line ("EXIT-0004 initiated").
     * Throw a RuntimeException / LogicException with a message to refuse.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(Model $record, array $input, User $actor): string;

    /** How the record is named in the run's results ("E001 · Asha Rao"). */
    public function recordLabel(Model $record): string;
}
