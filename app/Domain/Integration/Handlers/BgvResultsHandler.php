<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Bgv\Models\BgvCheck;
use App\Domain\Bgv\Services\Bgv;
use App\Domain\Integration\Contracts\InboundEventHandler;
use App\Domain\Integration\Exceptions\IntegrationRejected;
use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Models\IntegrationSystem;

/**
 * Production readiness closure: `bgv.results`. A background-verification provider reports check
 * results for a case. It runs only after the Integration Hub has authenticated the request: integration
 * identity, signature, timestamp window, and a stored idempotent event. It runs inside the hub's
 * processing transaction, so the results and the event outcome commit together.
 *
 * data: {case_reference, checks: [{type, status, notes?}]}. The case is found inside the integration's
 * tenant by its external reference (or its PeopleOS id); a case of another tenant does not exist.
 * Only a `bgv` integration may report results.
 */
final class BgvResultsHandler implements InboundEventHandler
{
    public function __construct(private readonly Bgv $bgv) {}

    public function handle(InboundEvent $event, IntegrationSystem $system, array $data): array
    {
        if ($system->kind !== 'bgv') {
            throw new IntegrationRejected('Only a background-verification integration may report check results.', 'forbidden', 403);
        }
        $reference = is_scalar($data['case_reference'] ?? null) ? trim((string) $data['case_reference']) : '';
        $checks = $data['checks'] ?? null;
        if ($reference === '' || ! is_array($checks) || $checks === [] || ! array_is_list($checks)) {
            throw new IntegrationRejected('Results need case_reference and a non-empty checks list.', 'invalid_payload');
        }
        foreach ($checks as $check) {
            if (! is_array($check) || ! array_key_exists((string) ($check['type'] ?? ''), config('peopleos.bgv.check_types', []))
                || ! array_key_exists((string) ($check['status'] ?? ''), BgvCheck::STATUSES)
                || (isset($check['notes']) && (! is_string($check['notes']) || mb_strlen($check['notes']) > 2000))) {
                throw new IntegrationRejected('Each check needs a known type and status (notes: text up to 2000 characters).', 'invalid_payload');
            }
        }

        $case = BgvCase::query()->where('external_reference', $reference)
            ->when(ctype_digit($reference), fn ($q) => $q->orWhere('id', (int) $reference))->first()
            ?? throw new IntegrationRejected('No verification case with that reference.', 'not_found', 404);

        foreach ($checks as $result) {
            $check = $case->checks()->firstOrCreate(['type' => $result['type']]);
            $this->bgv->recordCheck($check, $result['status'], $result['notes'] ?? null);
        }
        $case->refresh();

        return ['case_id' => $case->id, 'status' => $case->status, 'overall_result' => $case->overall_result,
            'checks' => $case->checks()->get()->map(fn (BgvCheck $c) => ['type' => $c->type, 'status' => $c->status])->all()];
    }
}
