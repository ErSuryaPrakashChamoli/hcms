<?php

namespace App\Http\Controllers;

use App\Support\Observability\HealthChecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 14 health endpoints.
 *
 * - LIVE (/health/live): the PHP process answers. It touches no dependency, so an orchestrator
 *   restarts only a dead process, never one waiting on its database.
 * - READY (/health/ready): this node can serve traffic. Database, cache and document storage are
 *   critical (503 when any fails). Queue backlog, failed jobs, the scheduler heartbeat, integration
 *   dead letters and Redis (when configured) are reported as warnings (200, status "degraded").
 *
 * Neither returns tenant data or configuration values. Counts and timings appear only with the
 * configured X-Health-Token (peopleos.health.token).
 */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'live', 'time' => now()->toIso8601String()]);
    }

    public function ready(Request $request, HealthChecks $checks): JsonResponse
    {
        $result = $checks->run();
        $token = (string) config('peopleos.health.token', '');
        $detailed = $token !== '' && hash_equals($token, (string) $request->header('X-Health-Token', ''));

        $body = ['status' => $result['status'], 'time' => now()->toIso8601String(), 'checks' => collect($result['checks'])
            ->map(fn (array $c) => $detailed ? $c : ['status' => $c['status'], 'critical' => $c['critical']])->all()];

        return response()->json($body, $result['status'] === 'down' ? 503 : 200);
    }
}
