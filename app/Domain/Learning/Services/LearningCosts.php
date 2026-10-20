<?php

namespace App\Domain\Learning\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LearningCost;
use RuntimeException;

/**
 * Phase 8 learning costs. Recorded and read only with learning.costs. There is no link to payroll,
 * salary or reimbursement: a future expense module may read these rows through a contract.
 */
final class LearningCosts
{
    /** @param  array<string, mixed>  $data */
    public function record(array $data, User $actor): LearningCost
    {
        if (! $actor->hasPermission('learning.costs')) {
            throw new RuntimeException('Recording learning costs needs learning.costs.');
        }
        if (empty($data['currency']) || strlen((string) $data['currency']) !== 3) {
            throw new RuntimeException('A cost needs a three-letter currency.');
        }

        return LearningCost::query()->create([...$data, 'created_by' => $actor->id]);
    }

    /** @return array<string, float> total by currency */
    public function totals(?string $from = null, ?string $to = null): array
    {
        return LearningCost::query()
            ->when($from, fn ($q) => $q->whereDate('incurred_on', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('incurred_on', '<=', $to))
            ->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency')
            ->map(fn ($v) => round((float) $v, 2))->all();
    }
}
