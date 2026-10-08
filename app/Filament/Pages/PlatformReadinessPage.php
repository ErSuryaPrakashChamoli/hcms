<?php

namespace App\Filament\Pages;

use App\Support\Observability\PlatformReadiness;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Phase 14: production readiness for platform administrators. It covers configuration, runtime
 * health, audit chains, the statutory production gate and the evidence only operators can supply.
 * It never declares PeopleOS production-ready on its own.
 */
class PlatformReadinessPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Platform readiness';

    protected static ?string $title = 'Platform readiness';

    protected static ?string $slug = 'platform-readiness';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.platform-readiness';

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    /** @return array{checks: list<array<string, string>>, summary: array<string, mixed>} */
    public function getReport(): array
    {
        return app(PlatformReadiness::class)->report();
    }
}
