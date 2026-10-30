<?php

namespace App\Domain\Exit\Contracts;

use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\FinalSettlement;

/**
 * Money a system of record settles at exit (HROne parity: outstanding loans, expense advances …). Sources are tagged
 * `peopleos.settlement_sources`; full and final settlement adds their lines when it calculates and tells them when
 * the settlement is paid.
 */
interface SettlementSource
{
    /** @return list<array{type: string, code: string, name: string, amount: float, basis?: array<string, mixed>}> type: earning | deduction */
    public function linesFor(ExitCase $case, FinalSettlement $settlement): array;

    /** The settlement was paid: close what its lines settled. */
    public function settled(ExitCase $case, FinalSettlement $settlement): void;
}
