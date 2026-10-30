<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Jobs\ExportIntegrationLog;
use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Models\ApiRequestLog;
use App\Domain\Integration\Models\IntegrationLogExport;
use App\Filament\Resources\IntegrationLogExports\IntegrationLogExportResource;
use App\Support\Http\SafeHttp;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use RuntimeException;
use Throwable;

/**
 * HROne parity SV-26: queued CSV exports of the webhook delivery log ("Export webhook log") and of an API key's call log
 * ("Export API call logs").
 *
 * - request(): checks the permission, keeps only known filters, records the export and an EXPORT audit event, and queues
 *   ExportIntegrationLog (after commit).
 * - build(): writes the CSV in chunks to the documents disk (tenants/{id}/integrations/…), formula-escaped, and tells the
 *   person in-app. No payloads, bodies or secrets; destinations are shown as scheme and host only.
 * - download(): only the person who asked, while they still hold the permission (audited DOWNLOAD).
 * - purge(): files and rows past retention.report_runs_days (Retention::purge()).
 */
final class IntegrationLogExports
{
    /** Who may export each log. */
    public const PERMISSIONS = ['webhook_deliveries' => ['webhook.manage'], 'api_request_logs' => ['api_key.view', 'api_key.manage']];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function mayExport(string $kind, User $user): bool
    {
        foreach (self::PERMISSIONS[$kind] ?? [] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $filters */
    public function request(string $kind, array $filters, User $actor): IntegrationLogExport
    {
        if (! isset(IntegrationLogExport::KINDS[$kind])) {
            throw new RuntimeException("Unknown log [{$kind}].");
        }
        if (! $this->mayExport($kind, $actor)) {
            throw new RuntimeException('You may not export this log.');
        }
        $filters = $this->filters($kind, $filters);
        $key = null;
        if ($kind === 'api_request_logs') {
            $key = ApiKey::query()->find($filters['api_key_id'] ?? 0);
            if ($key === null) {
                throw new RuntimeException('Choose the API key whose calls to export.');
            }
        }
        $export = IntegrationLogExport::create(['requested_by' => $actor->id, 'kind' => $kind, 'filters' => $filters, 'status' => 'queued']);
        $this->audit->record(AuditAction::Export, 'integration', $key, [], null, actor: $actor, entityLabel: $key === null ? 'Webhook deliveries' : null,
            metadata: ['log' => $kind, 'export_id' => $export->id, 'filters' => $filters, 'queued' => true]);
        ExportIntegrationLog::dispatch($export->id)->afterCommit();

        return $export;
    }

    /** Builds the file of a queued export (safe to retry: a completed export is left alone). */
    public function build(IntegrationLogExport $export): IntegrationLogExport
    {
        if (! in_array($export->status, ['queued', 'running'], true)) {
            return $export;
        }
        $export->update(['status' => 'running', 'started_at' => now(), 'error' => null]);
        try {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, Writer::BOM_UTF8); // the writer adds a BOM only when it outputs a string, not to a stream
            $writer = Writer::createFromStream($stream);
            $writer->addFormatter((new EscapeFormula)->escapeRecord(...));
            $rows = $export->kind === 'webhook_deliveries' ? $this->writeDeliveries($writer, $export->filters ?? []) : $this->writeApiCalls($writer, $export->filters ?? []);
            rewind($stream);
            $disk = config('peopleos.documents.disk', 'local');
            $path = "tenants/{$export->tenant_id}/integrations/{$export->kind}-".now()->format('Ymd-His')."-{$export->id}.csv";
            Storage::disk($disk)->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $export->update(['status' => 'completed', 'row_count' => $rows, 'disk' => $disk, 'path' => $path, 'finished_at' => now()]);
        } catch (Throwable $e) {
            report($e);
            $export->update(['status' => 'failed', 'error' => mb_substr('The export stopped: '.$e->getMessage(), 0, 500), 'finished_at' => now()]);
        }
        $this->notify($export);

        return $export;
    }

    public function canDownload(IntegrationLogExport $export, ?User $user): bool
    {
        return $user !== null && $export->hasFile() && (int) $export->requested_by === (int) $user->id && $this->mayExport($export->kind, $user);
    }

    /** @return array{name: string, content: string} */
    public function download(IntegrationLogExport $export, User $user): array
    {
        if (! $this->canDownload($export, $user)) {
            throw new RuntimeException('Only the person who asked for this export may download it.');
        }
        $content = Storage::disk($export->disk)->get($export->path);
        if ($content === null) {
            throw new RuntimeException('The file is no longer kept; export again.');
        }
        $this->audit->record(AuditAction::Download, 'integration', null, [], null, actor: $user, entityLabel: IntegrationLogExport::KINDS[$export->kind] ?? $export->kind,
            metadata: ['log' => $export->kind, 'export_id' => $export->id, 'rows' => $export->row_count]);

        return ['name' => basename($export->path), 'content' => $content];
    }

    /** Deletes exports (and their files) older than the window; returns how many. */
    public function purge(int $days): int
    {
        $deleted = 0;
        IntegrationLogExport::query()->where('created_at', '<', now()->subDays($days))->lazyById(200)->each(function (IntegrationLogExport $export) use (&$deleted) {
            if (filled($export->getAttributes()['path'] ?? null) && filled($export->disk)) {
                Storage::disk($export->disk)->delete($export->path);
            }
            $deleted += (int) $export->delete();
        });

        return $deleted;
    }

    /**
     * Keeps only the filters each log knows (anything else is dropped).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function filters(string $kind, array $filters): array
    {
        $date = fn ($value) => filled($value) ? Carbon::parse((string) $value)->toDateString() : null;
        $clean = $kind === 'webhook_deliveries'
            ? [
                'webhook_endpoint_id' => filled($filters['webhook_endpoint_id'] ?? null) ? (int) $filters['webhook_endpoint_id'] : null,
                'event' => filled($filters['event'] ?? null) ? mb_substr((string) $filters['event'], 0, 64) : null,
                'status' => isset(WebhookDelivery::STATUSES[$filters['status'] ?? '']) ? $filters['status'] : null,
                'from' => $date($filters['from'] ?? null), 'until' => $date($filters['until'] ?? null),
            ]
            : [
                'api_key_id' => filled($filters['api_key_id'] ?? null) ? (int) $filters['api_key_id'] : null,
                'method' => isset(ApiRequestLogs::METHODS[strtoupper((string) ($filters['method'] ?? ''))]) ? strtoupper((string) $filters['method']) : null,
                'status_class' => isset(ApiRequestLogs::STATUS_CLASSES[$filters['status_class'] ?? '']) ? $filters['status_class'] : null,
                'path' => filled($filters['path'] ?? null) ? mb_substr((string) $filters['path'], 0, 120) : null,
                'from' => $date($filters['from'] ?? null), 'until' => $date($filters['until'] ?? null),
            ];

        return array_filter($clean, fn ($value) => $value !== null);
    }

    /** @param  array<string, mixed>  $filters */
    public function deliveriesQuery(array $filters): Builder
    {
        return WebhookDelivery::query()
            ->when($filters['webhook_endpoint_id'] ?? null, fn (Builder $q, $id) => $q->where('webhook_endpoint_id', $id))
            ->when($filters['event'] ?? null, fn (Builder $q, $event) => $q->where('event', $event))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['until'] ?? null, fn (Builder $q, $until) => $q->where('created_at', '<=', Carbon::parse($until)->endOfDay()));
    }

    /** @param  array<string, mixed>  $filters */
    public function apiCallsQuery(array $filters): Builder
    {
        return ApiRequestLog::query()->where('api_key_id', (int) ($filters['api_key_id'] ?? 0))
            ->when($filters['method'] ?? null, fn (Builder $q, $method) => $q->where('method', $method))
            ->when($filters['status_class'] ?? null, fn (Builder $q, $class) => $q->whereBetween('status', [(int) $class[0] * 100, (int) $class[0] * 100 + 99]))
            ->when($filters['path'] ?? null, fn (Builder $q, $path) => $q->where('path', 'like', '%'.addcslashes($path, '%_\\').'%'))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['until'] ?? null, fn (Builder $q, $until) => $q->where('created_at', '<=', Carbon::parse($until)->endOfDay()));
    }

    /** @param  array<string, mixed>  $filters */
    private function writeDeliveries(Writer $writer, array $filters): int
    {
        $writer->insertOne(['Created at', 'Webhook', 'Destination', 'Event', 'Event id', 'Status', 'Attempts', 'HTTP status', 'Duration (ms)', 'Delivered at', 'Dead-lettered at', 'Acknowledged at', 'Replays']);
        $rows = 0;
        $this->deliveriesQuery($filters)->with('endpoint')->lazyById(500)->each(function (WebhookDelivery $d) use ($writer, &$rows) {
            $writer->insertOne([
                $d->created_at?->toDateTimeString(), $d->endpoint?->name, $d->endpoint ? SafeHttp::redact((string) $d->endpoint->url) : null, $d->event, $d->event_id,
                WebhookDelivery::STATUSES[$d->status] ?? $d->status, $d->attempts, $d->response_code, $d->duration_ms, $d->delivered_at?->toDateTimeString(),
                $d->dead_lettered_at?->toDateTimeString(), $d->acknowledged_at?->toDateTimeString(), $d->replay_count,
            ]);
            $rows++;
        });

        return $rows;
    }

    /** @param  array<string, mixed>  $filters */
    private function writeApiCalls(Writer $writer, array $filters): int
    {
        $writer->insertOne(['Date', 'API key', 'Method', 'Path', 'Route', 'HTTP status', 'Duration (ms)', 'IP address', 'User agent']);
        $key = ApiKey::query()->find($filters['api_key_id'] ?? 0);
        $label = $key === null ? null : "{$key->name} ({$key->prefix})";
        $rows = 0;
        $this->apiCallsQuery($filters)->lazyById(1000)->each(function (ApiRequestLog $log) use ($writer, $label, &$rows) {
            $writer->insertOne([$log->created_at?->toDateTimeString(), $label, $log->method, $log->path, $log->route_name, $log->status, $log->duration_ms, $log->ip, $log->user_agent]);
            $rows++;
        });

        return $rows;
    }

    private function notify(IntegrationLogExport $export): void
    {
        rescue(function () use ($export) {
            $user = User::query()->find($export->requested_by);
            if ($user === null) {
                return;
            }
            $label = IntegrationLogExport::KINDS[$export->kind] ?? $export->kind;
            $notification = Notification::make()
                ->title($export->status === 'completed' ? "Export ready: {$label}" : "Export not done: {$label}")
                ->body($export->status === 'completed' ? "{$export->row_count} row(s). Download it from Integrations → Log exports." : (string) $export->error)
                ->icon($export->status === 'completed' ? 'heroicon-o-arrow-down-tray' : 'heroicon-o-exclamation-triangle')
                ->iconColor($export->status === 'completed' ? 'success' : 'danger')
                ->actions([Action::make('open')->label('Log exports')->url(IntegrationLogExportResource::getUrl('index'))]);
            $user->notifyNow($notification->toDatabase());
        }, report: true);
    }
}
