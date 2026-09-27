<?php

namespace App\Domain\Compliance\Jobs;

use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Organisation\Models\Establishment;

class GenerateEpfReturn extends StatutoryReturnJob
{
    /** @param  list<int>|null  $employeeIds */
    public function __construct(public readonly int $establishmentId, public readonly int $year, public readonly int $month, ?int $actorId, public readonly string $kind = 'regular', public readonly ?array $employeeIds = null, public readonly ?string $reason = null, public readonly bool $paymentNotInitiated = false)
    {
        parent::__construct($actorId);
    }

    public function uniqueId(): string
    {
        return sprintf('epf-return-%d-%d-%04d-%02d-%s', $this->tenantId, $this->establishmentId, $this->year, $this->month, $this->kind);
    }

    public function handle(EpfReturns $epf): void
    {
        $epf->generate(Establishment::query()->findOrFail($this->establishmentId), $this->year, $this->month, $this->actor(), $this->kind, $this->employeeIds, $this->reason, $this->paymentNotInitiated, 'job');
    }
}
