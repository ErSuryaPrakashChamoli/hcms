<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Payments\Services\ProviderEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * SaaS.7: POST /webhooks/billing/{provider}. No session, no CSRF, no API key: the provider's signature over the raw
 * body is the authentication. Rate-limited per IP. Answers with a status only, never echoing the payload.
 */
final class ProviderWebhookController
{
    public function __invoke(Request $request, string $provider, ProviderEvents $events): JsonResponse
    {
        [$status, $outcome] = $events->receive($provider, $request->getContent(), $request->headers->all(), Context::get('request_id'));

        return response()->json(['status' => $outcome], $status);
    }
}
