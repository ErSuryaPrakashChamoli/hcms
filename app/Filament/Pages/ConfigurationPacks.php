<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Services\Blueprints;
use App\Filament\Support\AuditReasonField;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/** Configuration packs (§76) and blueprints (§77): starting templates, export, import. */
class ConfigurationPacks extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Packs & Blueprints';

    protected static ?string $title = 'Configuration packs & blueprints';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.configuration-packs';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->can('blueprint.export') || $user?->can('blueprint.import');
    }

    /** @return array<string, array{name: string, description: string}> */
    public function getPacksProperty(): array
    {
        return app(Blueprints::class)->packs();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('applyPack')
                ->label('Apply a pack')
                ->icon(Heroicon::OutlinedSparkles)
                ->authorize(fn () => auth()->user()->can('blueprint.import'))
                ->modalDescription('Packs are starting configurations: records are matched by code, nothing is deleted, and you can edit everything afterwards.')
                ->schema([
                    Select::make('pack')->options(collect($this->packs)->map(fn ($p) => $p['name'])->all())->required(),
                    AuditReasonField::make(),
                ])
                ->action(function (array $data) {
                    try {
                        $counts = app(Blueprints::class)->applyPack($data['pack'], $data[AuditReasonField::NAME] ?? null);
                        Notification::make()->success()->title('Pack applied')->body(collect($counts)->map(fn ($n, $s) => "{$s}: {$n}")->implode(', '))->send();
                    } catch (ConfigurationException $e) {
                        Notification::make()->danger()->title('Could not apply pack')->body($e->getMessage())->send();
                    }
                }),
            Action::make('export')
                ->label('Export blueprint')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->authorize(fn () => auth()->user()->can('blueprint.export'))
                ->action(function () {
                    $json = json_encode(app(Blueprints::class)->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $name = 'blueprint-'.app(TenantContext::class)->current()?->slug.'-'.now()->format('Ymd-His').'.json';
                    // Phase 14: configuration exports are audited like every other export.
                    app(AuditRecorder::class)->record(AuditAction::Export, 'configuration', null, [], null, metadata: ['export' => 'blueprint', 'file' => $name, 'bytes' => strlen((string) $json)]);

                    return response()->streamDownload(fn () => print $json, $name, ['Content-Type' => 'application/json']);
                }),
            Action::make('import')
                ->label('Import blueprint')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->authorize(fn () => auth()->user()->can('blueprint.import'))
                ->schema([
                    FileUpload::make('file')->acceptedFileTypes(['application/json'])->required()->disk('local')->directory('blueprints'),
                    AuditReasonField::make(),
                ])
                ->action(function (array $data) {
                    try {
                        $blueprint = json_decode(Storage::disk('local')->get($data['file']), true, 512, JSON_THROW_ON_ERROR);
                        $counts = app(Blueprints::class)->import($blueprint, $data[AuditReasonField::NAME] ?? null);
                        Notification::make()->success()->title('Blueprint imported')->body(collect($counts)->map(fn ($n, $s) => "{$s}: {$n}")->implode(', '))->send();
                    } catch (ConfigurationException|\JsonException $e) {
                        Notification::make()->danger()->title('Import failed')->body($e->getMessage())->send();
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }
                }),
        ];
    }
}
