<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Support\Api\OpenApiGenerator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * HROne parity CA-11 ("Explore API"): the integration API's OpenAPI description, read in the app and downloadable as JSON.
 * Built from the route table by OpenApiGenerator (the same description `peopleos:openapi` writes to docs/api/openapi.json),
 * so it always matches the running code. Read-only, for holders of api_key.view or api_key.manage.
 */
class ApiReference extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'API reference';

    protected static ?string $title = 'API reference';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.api-reference';

    public string $search = '';

    /** @var array<string, mixed>|null */
    private ?array $spec = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('api_key.view') || $user->can('api_key.manage'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    public function openApi(): array
    {
        return $this->spec ??= app(OpenApiGenerator::class)->generate();
    }

    /** @return list<string> the description's paragraphs */
    public function introduction(): array
    {
        return array_values(array_filter(explode("\n\n", (string) ($this->openApi()['info']['description'] ?? ''))));
    }

    /**
     * Operations grouped by area, filtered by the search box (path, summary or scope).
     *
     * @return array<string, list<array{method: string, path: string, summary: string, scope: ?string, sensitive: bool, parameters: list<string>}>>
     */
    public function operationGroups(): array
    {
        $needle = mb_strtolower(trim($this->search));
        $groups = [];
        foreach ($this->openApi()['paths'] ?? [] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $row = [
                    'method' => strtoupper($method), 'path' => $path, 'summary' => (string) ($operation['summary'] ?? ''), 'scope' => $operation['x-scope'] ?? null,
                    'sensitive' => ($operation['x-data-classification'] ?? null) === 'sensitive',
                    'parameters' => array_values(array_filter(array_map(fn ($p) => isset($p['name']) ? $p['name'].' ('.($p['in'] ?? 'query').')' : null, $operation['parameters'] ?? []))),
                ];
                if ($needle !== '' && ! str_contains(mb_strtolower($row['path'].' '.$row['summary'].' '.$row['scope']), $needle)) {
                    continue;
                }
                $groups[(string) ($operation['tags'][0] ?? 'api')][] = $row;
            }
        }
        ksort($groups);

        return $groups;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('keys')->label('API keys')->icon('heroicon-m-key')->color('gray')->url(fn () => ApiKeyResource::getUrl('index'))
                ->visible(fn () => ApiKeyResource::canAccess()),
            Action::make('download')->label('Download OpenAPI (JSON)')->icon('heroicon-m-arrow-down-tray')
                ->action(function () {
                    abort_unless(static::canAccess(), 403);
                    $json = json_encode($this->openApi(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    return response()->streamDownload(fn () => print ($json), 'peopleos-openapi.json', ['Content-Type' => 'application/json']);
                }),
        ];
    }
}
