<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Models\ApiRequestLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * HROne parity CA-11: the call log of API keys (API Calls tab, usage chart, CSV export).
 *
 * What is kept for each call made with a verified key: method, path (with ids, never the query string), route name,
 * status, duration, the caller's IP and user agent. Never a request or response body, a header (so never the key itself
 * or an Authorization header) or a query-string value. Rows are the tenant's (tenant id from the key), purged after
 * retention.api_request_logs_days by Retention::purge().
 */
final class ApiRequestLogs
{
    public const METHODS = ['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE'];

    public const STATUS_CLASSES = ['2xx' => 'Success (2xx)', '4xx' => 'Refused (4xx)', '5xx' => 'Server error (5xx)'];

    /** Writes one row for a finished request. Never throws: a log failure must not affect the API (it is reported). */
    public function record(ApiKey $key, Request $request, Response $response): void
    {
        rescue(function () use ($key, $request, $response) {
            $started = $request->attributes->get('peopleos.api_started_at') ?? $request->server('REQUEST_TIME_FLOAT');
            $duration = is_numeric($started) ? (int) max(0, round((microtime(true) - (float) $started) * 1000)) : 0;
            $agent = (string) $request->userAgent();
            // Written quietly with the key's own tenant: the request may have ended before the key bound its tenant
            // (e.g. refused by the rate limiter), and the row belongs to the key's tenant whatever is bound.
            (new ApiRequestLog)->forceFill([
                'tenant_id' => $key->tenant_id,
                'api_key_id' => $key->id,
                'method' => mb_substr(strtoupper($request->getMethod()), 0, 10),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
                'route_name' => ($name = $request->route()?->getName()) === null ? null : mb_substr($name, 0, 128),
                'status' => $response->getStatusCode(),
                'duration_ms' => min($duration, 4_294_967_295),
                'ip' => ($ip = $request->ip()) === null ? null : mb_substr($ip, 0, 45),
                'user_agent' => $agent === '' ? null : mb_substr($agent, 0, 255),
                'created_at' => now(),
            ])->saveQuietly();
        }, report: true);
    }

    /**
     * Calls per day for the usage chart (oldest first, every day of the window present).
     *
     * @return array{labels: list<string>, calls: list<int>, errors: list<int>}
     */
    public function daily(ApiKey $key, int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = ApiRequestLog::query()->where('api_key_id', $key->id)->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, count(*) as calls, sum(case when status >= 400 then 1 else 0 end) as errors')
            ->groupBy(DB::raw('DATE(created_at)'))->toBase()->get()->keyBy(fn ($row) => Carbon::parse($row->day)->toDateString());
        $labels = $calls = $errors = [];
        for ($day = $from->copy(); $day->lte(now()); $day->addDay()) {
            $date = $day->toDateString();
            $labels[] = $day->format('d M');
            $calls[] = (int) ($rows[$date]->calls ?? 0);
            $errors[] = (int) ($rows[$date]->errors ?? 0);
        }

        return ['labels' => $labels, 'calls' => $calls, 'errors' => $errors];
    }

    /** @return array{calls: int, errors: int, average_ms: int|null, last_at: ?string} totals over the window */
    public function summary(ApiKey $key, int $days = 30): array
    {
        $row = ApiRequestLog::query()->where('api_key_id', $key->id)->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('count(*) as calls, sum(case when status >= 400 then 1 else 0 end) as errors, avg(duration_ms) as average_ms, max(created_at) as last_at')
            ->toBase()->first();

        return ['calls' => (int) ($row->calls ?? 0), 'errors' => (int) ($row->errors ?? 0), 'average_ms' => isset($row->average_ms) ? (int) round((float) $row->average_ms) : null,
            'last_at' => $row->last_at ?? null];
    }
}
