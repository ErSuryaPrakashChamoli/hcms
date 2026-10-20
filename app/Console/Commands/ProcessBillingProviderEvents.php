<?php

namespace App\Console\Commands;

use App\Domain\Payments\Services\ProviderEvents;
use Illuminate\Console\Command;

/**
 * SaaS.7: retries payment-provider events that could not be resolved or applied yet (an event that arrived before
 * its payment's reference was stored, a crashed worker, a failed attempt). Idempotent: each event is claimed with
 * a lease and the reconciler ignores outcomes it has already recorded.
 */
class ProcessBillingProviderEvents extends Command
{
    protected $signature = 'peopleos:billing:provider-events';

    protected $description = 'Retry payment-provider events that are unresolved, failed or stuck (SaaS.7).';

    public function handle(ProviderEvents $events): int
    {
        ['routed' => $routed, 'dispatched' => $dispatched] = $events->sweep();
        $this->info("Provider events: {$routed} resolved, {$dispatched} dispatched.");

        return self::SUCCESS;
    }
}
