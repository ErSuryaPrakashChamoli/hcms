<?php

namespace App\Filament\Support;

use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookDeliveryAttempt;
use App\Domain\Enterprise\Services\Webhooks;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Collection;

/**
 * HROne parity SV-26: the webhook delivery columns and actions shared by an endpoint's Deliveries tab and the
 * "Webhook deliveries" page across endpoints. Every action goes through Webhooks, which checks webhook.manage again.
 */
final class WebhookDeliveryActions
{
    public static function statusColor(string $state): string
    {
        return match ($state) {
            'delivered' => 'success', 'failed', 'dead_letter' => 'danger', default => 'warning'
        };
    }

    /** @return list<TextColumn> */
    public static function outcomeColumns(): array
    {
        return [
            TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => WebhookDelivery::STATUSES[$state] ?? $state),
            TextColumn::make('attempts'),
            TextColumn::make('response_code')->label('HTTP')->placeholder('—'),
            TextColumn::make('duration_ms')->label('Latency')->suffix(' ms')->placeholder('—')->sortable(),
        ];
    }

    public static function acknowledgedColumn(): TextColumn
    {
        return TextColumn::make('acknowledged_at')->label('Acknowledged')->dateTime()->placeholder('—')->toggleable();
    }

    /** Payload, the last response and every attempt of one delivery. */
    public static function details(): Action
    {
        return Action::make('payload')->label('Details')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalHeading(fn (WebhookDelivery $record) => "{$record->event} · {$record->event_id}")
            ->schema([
                TextEntry::make('history')->label('Delivery history')->listWithLineBreaks()->placeholder('Not attempted yet')
                    ->state(fn (WebhookDelivery $record) => self::history($record)),
                TextEntry::make('payload')->label('Payload')->state(fn (WebhookDelivery $record) => json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->prose(),
                TextEntry::make('payload_size')->label('Payload size')->state(fn (WebhookDelivery $record) => strlen((string) json_encode($record->payload, JSON_UNESCAPED_UNICODE)).' bytes'),
                TextEntry::make('response_excerpt')->label('Last response')->placeholder('—'),
            ]);
    }

    /** @return list<string> one line per attempt, oldest first */
    public static function history(WebhookDelivery $record): array
    {
        return WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $record->id)->orderBy('attempt')->orderBy('id')->get()
            ->map(fn (WebhookDeliveryAttempt $a) => '#'.$a->attempt.' · '.$a->attempted_at?->format('d M Y H:i:s').' · '.($a->response_code ? 'HTTP '.$a->response_code : 'no response')
                .' · '.$a->duration_ms.' ms'.($a->signed_with_previous ? ' · also signed with the previous secret' : '')
                .(filled($a->response_excerpt) ? ' · '.mb_substr((string) $a->response_excerpt, 0, 160) : ''))
            ->all();
    }

    /** Phase 14: a pending delivery is attempted now through the same claim as the scheduler (never twice at once). */
    public static function retry(): Action
    {
        return Action::make('retry')->label('Retry now')->icon('heroicon-m-arrow-path')->visible(fn (WebhookDelivery $record) => $record->status === 'pending')
            ->action(fn (WebhookDelivery $record) => ServiceDeskActions::run(fn () => app(Webhooks::class)->deliver($record->id, force: true), fn ($o) => match ($o) {
                'delivered' => 'Delivered', 'skipped' => 'Another attempt is in progress', 'dead_letter' => 'Moved to dead letter', default => 'Still failing'
            }));
    }

    /** Phase 14: a dead letter is replayed with a fresh attempt budget (audited). */
    public static function replay(): Action
    {
        return Action::make('replay')->label('Replay')->icon('heroicon-m-arrow-uturn-right')->requiresConfirmation()
            ->visible(fn (WebhookDelivery $record) => $record->isFailed())
            ->action(fn (WebhookDelivery $record) => ServiceDeskActions::run(fn () => app(Webhooks::class)->replay($record, auth()->user()), 'Queued for delivery again'));
    }

    /** SV-26: mark a dead letter as seen; it leaves the endpoint's failure count (audited). */
    public static function acknowledge(): Action
    {
        return Action::make('acknowledge')->label('Acknowledge')->icon('heroicon-m-check')->color('gray')
            ->visible(fn (WebhookDelivery $record) => $record->isFailed() && ! $record->isAcknowledged())
            ->schema([Textarea::make('note')->label('Note (optional)')->maxLength(255)])
            ->action(fn (WebhookDelivery $record, array $data) => ServiceDeskActions::run(fn () => app(Webhooks::class)->acknowledge([$record], auth()->user(), $data['note'] ?? null),
                fn (array $counts) => $counts['acknowledged'] > 0 ? 'Acknowledged' : 'Nothing to acknowledge'));
    }

    public static function bulkReplay(): BulkAction
    {
        return BulkAction::make('replaySelected')->label('Replay selected')->icon('heroicon-m-arrow-uturn-right')->requiresConfirmation()
            ->modalDescription('Dead letters among the selected deliveries go back to the queue with a fresh attempt budget; the others are left as they are.')
            ->action(fn (Collection $records) => ServiceDeskActions::run(fn () => app(Webhooks::class)->replayMany($records, auth()->user()),
                fn (array $counts) => "{$counts['replayed']} replayed".($counts['skipped'] > 0 ? ", {$counts['skipped']} skipped (not dead letters)" : '')))
            ->deselectRecordsAfterCompletion();
    }

    public static function bulkAcknowledge(): BulkAction
    {
        return BulkAction::make('acknowledgeSelected')->label('Acknowledge selected')->icon('heroicon-m-check')->color('gray')
            ->schema([Textarea::make('note')->label('Note (optional)')->maxLength(255)])
            ->action(fn (Collection $records, array $data) => ServiceDeskActions::run(fn () => app(Webhooks::class)->acknowledge($records, auth()->user(), $data['note'] ?? null),
                fn (array $counts) => "{$counts['acknowledged']} acknowledged".($counts['skipped'] > 0 ? ", {$counts['skipped']} skipped" : '')))
            ->deselectRecordsAfterCompletion();
    }

    /** SV-26 ("Error breakdown"): unacknowledged dead letters by HTTP status. */
    public static function errorBreakdown(?int $endpointId = null): Action
    {
        return Action::make('errorBreakdown')->label('Error breakdown')->icon('heroicon-m-chart-bar')->color('gray')
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalHeading('Unacknowledged failures by HTTP status')
            ->schema([TextEntry::make('breakdown')->hiddenLabel()->listWithLineBreaks()->placeholder('No unacknowledged failures.')
                ->state(fn () => collect(app(Webhooks::class)->errorBreakdown($endpointId))->map(fn (int $n, string $status) => "{$status}: {$n}")->values()->all())]);
    }
}
