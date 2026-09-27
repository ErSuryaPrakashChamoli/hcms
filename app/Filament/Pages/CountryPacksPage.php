<?php

namespace App\Filament\Pages;

use App\Domain\Enterprise\Services\CountryPacks;
use App\Domain\Organisation\Models\Company;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Country framework (§96): the packs available and which companies use them. */
class CountryPacksPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAsiaAustralia;

    protected static string|UnitEnum|null $navigationGroup = 'Enterprise';

    protected static ?string $navigationLabel = 'Country packs';

    protected static ?string $title = 'Country packs';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.country-packs';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('company.view') ?? false;
    }

    public function getPacks(): array
    {
        $companies = Company::query()->get()->groupBy(fn ($c) => strtoupper($c->country_code ?: 'IN'));

        return collect(app(CountryPacks::class)->all())->map(fn ($pack, $code) => $pack + ['code' => $code, 'companies' => $companies->get($code, collect())->pluck('name')->all()])->values()->all();
    }
}
