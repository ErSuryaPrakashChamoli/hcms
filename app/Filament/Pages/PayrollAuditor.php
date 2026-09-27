<?php

namespace App\Filament\Pages;

use App\Domain\Ai\Services\PayrollAnomalyDetector;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Platform\Services\FeatureFlags;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** AI Payroll Auditor (§94): anomaly findings for a run, with evidence. Read-only. */
class PayrollAuditor extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Payroll Auditor';

    protected static ?string $title = 'AI Payroll Auditor';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.payroll-auditor';

    #[Url]
    public ?int $run = null;

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('ai.payroll_auditor') ?? false) && app(FeatureFlags::class)->enabled('ai.payroll_auditor');
    }

    public function mount(): void
    {
        $this->run ??= PayrollRun::query()->whereIn('status', ['calculated', 'validated', 'approved', 'finalized', 'paid'])->orderByDesc('id')->value('id');
    }

    public function getRun(): ?PayrollRun
    {
        return $this->run ? PayrollRun::query()->with(['period', 'company'])->find($this->run) : null;
    }

    public function getFindings(): array
    {
        $run = $this->getRun();

        return $run ? app(PayrollAnomalyDetector::class)->audit($run) : [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pick')->label('Choose run')->icon(Heroicon::OutlinedCalculator)
                ->schema([Select::make('run')->options(fn () => PayrollRun::query()->with(['period', 'company'])->whereIn('status', ['calculated', 'validated', 'approved', 'finalized', 'paid'])->orderByDesc('id')->get()->mapWithKeys(fn ($r) => [$r->id => $r->period->label().' · '.$r->company->name.' · '.$r->status])->all())->default(fn () => $this->run)->required()])
                ->action(fn (array $data) => $this->run = (int) $data['run']),
        ];
    }
}
