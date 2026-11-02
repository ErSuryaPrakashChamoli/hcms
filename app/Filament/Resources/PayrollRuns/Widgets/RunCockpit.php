<?php

namespace App\Filament\Resources\PayrollRuns\Widgets;

use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRunCockpit;
use App\Filament\Support\PayrollReminders;
use Filament\Widgets\Widget;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HROne parity PO-4 (matrix 25 §25.6.1 #2, #7; §25.6.3 #2, #4, #9; §25.6.4 #1; §25.6.6 #3, #7; §25.6.8; §25.6.10 #2): the run
 * cockpit on the payroll run page — the run as HROne's salary dashboard steps with their counters, the step tables (open
 * one, download it as an audited CSV), the reminders card and the run's activity. Read-only: every action on the run stays
 * in the page's header (PayrollActions) and its entries table. Loads after the page (lazy). payroll.view, as the run page.
 */
class RunCockpit extends Widget
{
    #[Locked]
    public ?int $runId = null;

    /** The step table that is open (one of PayrollRunCockpit::TABLES), or none. */
    public ?string $openTable = null;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.payroll.run-cockpit';

    /** this request's run (one read per request) */
    private ?PayrollRun $resolved = null;

    private bool $looked = false;

    public static function canView(): bool
    {
        return auth()->user()?->can('payroll.view') ?? false;
    }

    public function run(): ?PayrollRun
    {
        if (! $this->looked) {
            $this->looked = true;
            $this->resolved = $this->runId !== null && static::canView() ? PayrollRun::query()->with(['period', 'company'])->find($this->runId) : null;
        }

        return $this->resolved;
    }

    public function toggleTable(string $table): void
    {
        $this->openTable = array_key_exists($table, PayrollRunCockpit::TABLES) && $this->openTable !== $table ? $table : null;
    }

    /** A step table as CSV (payroll.view, audited as an export of the run). */
    public function download(string $table): StreamedResponse
    {
        $run = $this->run();
        abort_unless($run !== null && array_key_exists($table, PayrollRunCockpit::TABLES), 403);
        $cockpit = app(PayrollRunCockpit::class);
        $csv = $cockpit->csv($run, $table, auth()->user());

        return response()->streamDownload(fn () => print ($csv), $cockpit->fileName($run, $table), ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $run = $this->run();
        if ($run === null) {
            return ['run' => null];
        }
        $cockpit = app(PayrollRunCockpit::class);
        $counters = $cockpit->counters($run);

        return [
            'run' => $run,
            'steps' => $cockpit->steps($run, $counters),
            'counters' => $counters,
            'sources' => $cockpit->sources($run),
            'tables' => PayrollRunCockpit::TABLES,
            'open' => $this->openTable !== null ? $cockpit->table($run, $this->openTable) : null,
            'screenRows' => PayrollRunCockpit::SCREEN_ROWS,
            'reminders' => PayrollReminders::forViewer(),
            'activity' => $cockpit->activity($run, 15),
        ];
    }
}
