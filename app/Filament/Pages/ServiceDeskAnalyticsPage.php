<?php

namespace App\Filament\Pages;

use App\Domain\ServiceDesk\Services\ServiceDeskAnalytics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Phase 12: HR service analytics — volume, backlog, SLA and resolution over a date range, in the
 * viewer's organisation scope. Small groups are suppressed (with complementary suppression), and
 * confidential cases appear only as a suppressed total.
 */
class ServiceDeskAnalyticsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Service Desk';

    protected static ?string $navigationLabel = 'Service analytics';

    protected static ?string $title = 'HR service analytics';

    protected static ?string $slug = 'service-analytics';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.service-desk-analytics';

    public string $from = '';

    public string $to = '';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->hasPermission('servicedesk.analytics');
    }

    public function mount(): void
    {
        $this->from = now()->subDays(90)->toDateString();
        $this->to = now()->toDateString();
    }

    /** @return array<string, mixed> */
    public function getSummary(): array
    {
        return app(ServiceDeskAnalytics::class)->summary(auth()->user(), $this->from ?: null, $this->to ?: null);
    }
}
