<?php

namespace App\Filament\Pages;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\CompensationCycleShare;
use App\Filament\Resources\CompensationCycles\CompensationCycleResource;
use App\Filament\Support\IncrementCalculatorWizard;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne parity PO-17 (matrix 25 §25.9.2 #1–#10): the increment calculator — the increment plans (compensation cycles,
 * salary uploads left out) with HROne's card figures (employees, average increment, total increment, total budget,
 * shared with), "Create new" (`?action=create`: the Population → Basis & proration → Grid → Review wizard), "Clone",
 * "Share with managers" and the error file. Each plan opens on its cycle page (summary, lines, managers' proposals and
 * the review → approve → execute steps, unchanged). For `compensation.cycles`.
 */
class IncrementCalculator extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Compensation';

    protected static ?string $navigationLabel = 'Increment calculator';

    protected static ?string $title = 'Increment calculator';

    protected static ?string $slug = 'increment-calculator';

    protected static ?int $navigationSort = 41;

    protected string $view = 'filament.pages.increment-calculator';

    /** @var array<int, array<string, float|int|null>>|null figures per plan on this page (one query) */
    private ?array $figures = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('compensation.cycles');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Plan increments for many people at once: choose who is in the plan, the basis (percent of CTC or of fixed pay, or an amount), tenure proration and a CTC band × rating grid; see the summary and the employees skipped; share the plan with managers for their team. Lines are still reviewed, approved and executed by other people.';
    }

    protected function getHeaderActions(): array
    {
        return [IncrementCalculatorWizard::createAction()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CompensationCycle::query()->where('cycle_type', '!=', CompensationCycle::UPLOAD)->with(['company', 'budget']))
            ->columns([
                TextColumn::make('code')->searchable()->url(fn (CompensationCycle $r) => CompensationCycleResource::getUrl('view', ['record' => $r])),
                TextColumn::make('name')->label('Plan')->searchable()->description(fn (CompensationCycle $r) => $r->company?->name),
                TextColumn::make('effective_from')->label('Effective from')->date()->sortable(),
                TextColumn::make('employee_count')->label('Increments')->numeric(),
                TextColumn::make('average_increment')->label('Average increment')->state(fn (CompensationCycle $r) => $this->figure($r, 'average'))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 2).'%')->placeholder('—'),
                TextColumn::make('total_increment')->label('Total increment')->state(fn (CompensationCycle $r) => $this->figure($r, 'increase'))->numeric(2)->placeholder('—'),
                TextColumn::make('budget.amount')->label('Total budget')->numeric(2)->placeholder('No budget'),
                TextColumn::make('shared')->label('Shared with')->state(fn (CompensationCycle $r) => (int) ($this->figure($r, 'shared') ?? 0))
                    ->formatStateUsing(fn ($state) => (int) $state === 0 ? '—' : $state.' manager(s)'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => CompensationCycle::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'executed' => 'success', 'approved' => 'info', 'rejected', 'cancelled' => 'danger', default => 'warning'
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(CompensationCycle::STATUSES)->multiple(),
                SelectFilter::make('cycle_type')->label('Type')->options(collect(config('peopleos.compensation.cycle_types'))->reject(fn ($t) => $t['upload'] ?? false)->map(fn ($t) => $t['label'])->all()),
            ])
            ->defaultSort('effective_from', 'desc')
            ->recordActions([
                Action::make('open')->label('Open')->icon(Heroicon::OutlinedEye)->url(fn (CompensationCycle $r) => CompensationCycleResource::getUrl('view', ['record' => $r])),
                IncrementCalculatorWizard::cloneAction(),
                IncrementCalculatorWizard::shareAction(),
                IncrementCalculatorWizard::errorFileAction(),
            ])
            ->emptyStateHeading('No increment plans yet')
            ->emptyStateDescription('Create one with "Create new".');
    }

    /** One aggregate over the lines of the plans on this page (main currency of each plan), and the shares. */
    private function figure(CompensationCycle $cycle, string $key): float|int|null
    {
        if ($this->figures === null) {
            $ids = $this->getTableRecords()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->figures = [];
            $rows = CompensationChange::query()->whereIn('compensation_cycle_id', $ids ?: [0])
                ->selectRaw('compensation_cycle_id, COUNT(*) AS lines, AVG(increase_percent) AS average, SUM(CASE WHEN COALESCE(previous_ctc_annual, base_ctc_annual) IS NULL THEN 0 ELSE ctc_annual - COALESCE(previous_ctc_annual, base_ctc_annual) END) AS increase')
                ->groupBy('compensation_cycle_id')->toBase()->get();
            foreach ($rows as $row) {
                $this->figures[(int) $row->compensation_cycle_id] = ['average' => $row->average !== null ? round((float) $row->average, 2) : null, 'increase' => round((float) $row->increase, 2)];
            }
            $shares = CompensationCycleShare::query()->whereIn('compensation_cycle_id', $ids ?: [0])->whereNull('revoked_at')
                ->selectRaw('compensation_cycle_id, COUNT(*) AS shared')->groupBy('compensation_cycle_id')->toBase()->get();
            foreach ($shares as $row) {
                $this->figures[(int) $row->compensation_cycle_id]['shared'] = (int) $row->shared;
            }
        }

        return $this->figures[(int) $cycle->id][$key] ?? null;
    }
}
