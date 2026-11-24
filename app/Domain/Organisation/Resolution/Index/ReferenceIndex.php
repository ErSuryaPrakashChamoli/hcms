<?php

namespace App\Domain\Organisation\Resolution\Index;

/**
 * One master's records as one resolution session sees them: looked up by code and by exact name key, narrowed by the
 * actor's organisation access and by the companies each record applies to. Rows are plain arrays:
 * {id: int, name: string, code: ?string, owner: ?int, live: bool, hints: array<string, ?int>}.
 */
interface ReferenceIndex
{
    /** The record whose code has this key (codes are unique in an organisation), if the actor may see it. */
    public function byCode(string $key, string $typed): ?int;

    /** @return list<int> the records the actor may see that have this exact name key */
    public function byName(string $key, string $typed): array;

    /** @return array{id: int, name: string, code: ?string, owner: ?int, live: bool, hints: array<string, ?int>} */
    public function row(int $id): array;

    /** The record if the actor may see it (loaded on demand), else null. Used to re-check a saved mapping's target. @return array{id: int, name: string, code: ?string, owner: ?int, live: bool, hints: array<string, ?int>}|null */
    public function find(int $id): ?array;

    /** Whether the record applies to the company on the session's date (an employee: works there). */
    public function appliesTo(int $id, int $companyId): bool;

    /** @return iterable<array{id: int, name: string, code: ?string, owner: ?int, live: bool, hints: array<string, ?int>}> every visible record, for suggestions; empty when the master is too large to scan */
    public function suggestible(): iterable;
}
