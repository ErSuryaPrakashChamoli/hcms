<?php

namespace App\Filament\Pages;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Phase 11 employee self-service: my own approved compensation — in force today, scheduled and past —
 * when the tenant allows it (setting compensation.self_service, permission compensation.self). Never
 * proposals, reasons, internal notes, ranges or anyone else's pay. Opening it is an audited read.
 */
class MyCompensation extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My compensation';

    protected static ?string $title = 'My compensation';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.my-compensation';

    public static function canAccess(): bool
    {
        $me = self::employee();

        return $me !== null && app(CompensationAccess::class)->level(auth()->user(), $me) === 'self';
    }

    public function mount(): void
    {
        app(SensitiveAccessAuditor::class)->recordView(self::employee(), 'compensation', 'self-service');
    }

    public static function employee(): ?Employee
    {
        return auth()->check() ? Employee::query()->where('user_id', auth()->id())->first() : null;
    }

    /** @return Collection<int, CompensationSnapshot> newest first */
    public function getHistory(): Collection
    {
        return app(CompensationOutput::class)->history(self::employee())->reverse()->values();
    }

    public function getCurrent(): ?CompensationSnapshot
    {
        return app(CompensationOutput::class)->on(self::employee());
    }
}
