<?php

namespace App\Domain\Performance\Contracts;

/** Phase 7 read contract for a future Learning module. Read-only; never exposes review text. */
interface DevelopmentNeedsReader
{
    /** @return list<array{id: int, title: string, competency_code: ?string, skill_id: ?int, current_level: ?float, target_level: ?float, target_date: ?string, priority: string, status: string, source_type: string}> */
    public function openNeedsFor(int $employeeId): array;
}
