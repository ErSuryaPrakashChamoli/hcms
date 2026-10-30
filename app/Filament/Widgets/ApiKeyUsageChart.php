<?php

namespace App\Filament\Widgets;

use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Services\ApiRequestLogs;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Locked;

/** HROne parity CA-11 ("Usage"): an API key's calls per day over the last 30 days, with the refused / failed ones. */
class ApiKeyUsageChart extends ChartWidget
{
    #[Locked]
    public ?int $apiKeyId = null;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'API calls, last 30 days';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $key = $this->apiKeyId === null ? null : ApiKey::query()->find($this->apiKeyId);
        if ($key === null || ! (auth()->user()?->can('view', $key) ?? false)) {
            return ['datasets' => [], 'labels' => []];
        }
        $daily = app(ApiRequestLogs::class)->daily($key);

        return [
            'labels' => $daily['labels'],
            'datasets' => [
                ['label' => 'Calls', 'data' => $daily['calls'], 'backgroundColor' => '#2563eb', 'borderColor' => '#2563eb'],
                ['label' => 'Refused or failed', 'data' => $daily['errors'], 'backgroundColor' => '#dc2626', 'borderColor' => '#dc2626'],
            ],
        ];
    }
}
