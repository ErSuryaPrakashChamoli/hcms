<?php

namespace App\Domain\Compliance\Jobs;

use App\Domain\Compliance\Services\Returns\PayrollLineReturns;
use App\Domain\Organisation\Models\Establishment;

/** Base for the ESI / PT / LWF generation jobs: one unique job per tenant, type, establishment and month. */
abstract class GeneratePayrollLineReturn extends StatutoryReturnJob
{
    public function __construct(public readonly int $establishmentId, public readonly int $year, public readonly int $month, ?int $actorId, public readonly ?string $reason = null)
    {
        parent::__construct($actorId);
    }

    /** @return class-string<PayrollLineReturns> */
    abstract protected function generator(): string;

    public function uniqueId(): string
    {
        return sprintf('%s-return-%d-%d-%04d-%02d', strtolower(app($this->generator())->type()), $this->tenantId, $this->establishmentId, $this->year, $this->month);
    }

    public function handle(): void
    {
        app($this->generator())->generate(Establishment::query()->findOrFail($this->establishmentId), $this->year, $this->month, $this->actor(), $this->reason, 'job');
    }
}
