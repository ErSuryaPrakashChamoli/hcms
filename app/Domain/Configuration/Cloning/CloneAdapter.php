<?php

namespace App\Domain\Configuration\Cloning;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What makes one configuration type cloneable (configuration cloning, Deliverable 4). The engine (ConfigurationCloner)
 * owns what is the same for every type: planning, applicability rewriting, scope and permissions, conflicts, the
 * transaction, idempotency, lineage and audit. The adapter owns what is specific: reading a snapshot, the references it
 * holds, the type's own conflicts, its impact, and creating the copy INACTIVE through the module's own service.
 *
 * A type becomes cloneable by registering its adapter in config('peopleos.cloning.adapters'); no engine change.
 */
interface CloneAdapter
{
    /** The registry key, e.g. 'policy'. */
    public function key(): string;

    /** What the type is called, e.g. 'Policy'. */
    public function label(): string;

    /** @return class-string<Model> */
    public function model(): string;

    /** The type's own create permission, checked for every destination company. */
    public function createPermission(): string;

    public function applicability(): ApplicabilityKind;

    /** The source in the current tenant (a record of another tenant is never found). */
    public function find(int $id): ?Model;

    /**
     * Choices about what to copy (e.g. which policy version), each with its options and default.
     *
     * @return array<string, array{label: string, options: array<int|string, string>, default: int|string|null, help?: string}>
     */
    public function sourceOptions(Model $source): array;

    /** @param  array<string, mixed>  $options */
    public function snapshot(Model $source, array $options = []): Snapshot;

    /** @return list<Reference> every record the snapshot points at, classified */
    public function references(Snapshot $snapshot): array;

    /**
     * Who the copy would reach in the destination today, and how many of them it would win (rule-based types).
     *
     * @return array{employees: int, matched: int, winning: int, capped: bool}|null
     */
    public function impact(Snapshot $snapshot, Destination $destination, ClonePlan $plan): ?array;

    /** @return list<Conflict> the type's own checks for one destination */
    public function conflicts(Snapshot $snapshot, Destination $destination, ClonePlan $plan): array;

    /** Whether a record of this type already uses the code (as the database's unique index would see it). */
    public function codeTaken(string $code): bool;

    /** @return list<string> names of existing records that look like the copy (same type and name) */
    public function lookAlikes(Snapshot $snapshot, Destination $destination): array;

    /** Create the copy INACTIVE through the module's own service. */
    public function materialise(Snapshot $snapshot, Destination $destination, ClonePlan $plan, User $actor): Materialised;

    /** @return list<string> tables holding transactional or historical rows of this type: a clone never writes to them */
    public function excluded(): array;
}
