<?php

namespace App\Domain\Compliance\Jobs;

use App\Domain\Compliance\Services\Tds\TdsQuarterlyReturns;
use App\Domain\Organisation\Models\LegalEntity;

/** Builds a Form No. 138 statement for one legal entity and FY quarter (unique per tenant, entity and quarter). */
class GenerateTdsReturn extends StatutoryReturnJob
{
    /** @param  list<array<string, mixed>>  $challans */
    public function __construct(public readonly int $legalEntityId, public readonly string $financialYear, public readonly int $quarter, ?int $actorId, public readonly array $challans = [], public readonly ?string $reason = null)
    {
        parent::__construct($actorId);
    }

    public function uniqueId(): string
    {
        return "tds-return-{$this->tenantId}-{$this->legalEntityId}-{$this->financialYear}-Q{$this->quarter}";
    }

    public function handle(TdsQuarterlyReturns $tds): void
    {
        $tds->generate(LegalEntity::query()->findOrFail($this->legalEntityId), $this->financialYear, $this->quarter, $this->actor(), $this->challans, $this->reason, 'job');
    }
}
