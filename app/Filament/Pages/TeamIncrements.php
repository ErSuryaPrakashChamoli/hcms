<?php

namespace App\Filament\Pages;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\CompensationCycleProposal;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Compensation\Services\IncrementCalculator;
use App\Domain\Compensation\Services\IncrementPlanSharing;
use App\Filament\Support\CompensationActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * HROne parity PO-17 (matrix 25 §25.14 #5, Team → Action → "Increment calculator"): the increment plans HR shared with
 * this manager, as the lines of the people they manage (configured manager relationships, CompensationAccess::teamOf),
 * inside their access scope and never their own line — current and planned annual CTC, the rating, and their own
 * proposal. While a plan is a draft the manager proposes an annual CTC per person, within the grid and the team's
 * budget (IncrementPlanSharing::propose); HR accepts or declines. For `compensation.team` holders with a plan shared.
 * Opens on a plan with `?plan=<cycle id>`.
 */
class TeamIncrements extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'Team increments';

    protected static ?string $title = 'Team increments';

    protected static ?string $slug = 'team-increments';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.team-increments';

    /** @var Collection<int, CompensationCycleProposal>|null this manager's proposals by line (per request) */
    private ?Collection $proposals = null;

    public function getSubheading(): ?string
    {
        return 'Increment plans HR shared with you for your team.';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasPermission('compensation.team') && app(IncrementPlanSharing::class)->sharedWith($user)->exists();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<int, string> the plans shared with the manager, latest first */
    public function plans(): array
    {
        return app(IncrementPlanSharing::class)->sharedWith(auth()->user())->orderByDesc('effective_from')->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (CompensationCycle $c) => [(int) $c->id => $c->code.' · '.$c->name])->all();
    }

    public function table(Table $table): Table
    {
        $plan = (int) request()->query('plan', 0);

        return $table
            ->query(fn (): Builder => $this->lines())
            ->columns([
                TextColumn::make('cycle.code')->label('Plan')->description(fn (CompensationChange $r) => $r->cycle?->name),
                TextColumn::make('employee.employee_code')->label('Employee')->description(fn (CompensationChange $r) => $r->employee?->person?->full_name),
                TextColumn::make('performance_label')->label('Rating')->placeholder('—'),
                TextColumn::make('base_ctc_annual')->label('Current CTC')->numeric(2)->placeholder('—')
                    ->state(fn (CompensationChange $r) => $r->base_ctc_annual ?? $r->previous_ctc_annual),
                TextColumn::make('increase_percent')->label('Planned %')->numeric(2)->placeholder('—'),
                TextColumn::make('ctc_annual')->label('Planned CTC')->numeric(2)->description(fn (CompensationChange $r) => $r->currency),
                TextColumn::make('my_proposal')->label('My proposal')->numeric(2)->placeholder('—')
                    ->state(fn (CompensationChange $r) => $this->proposalFor($r)?->ctc_annual)
                    ->description(fn (CompensationChange $r) => ($p = $this->proposalFor($r)) ? (CompensationCycleProposal::STATUSES[$p->status] ?? $p->status).($p->increase_percent !== null ? ' · '.number_format((float) $p->increase_percent, 2).'%' : '').($p->decision_note ? ' · '.$p->decision_note : '') : null),
            ])
            ->filters([
                SelectFilter::make('plan')->label('Plan')->options(fn () => $this->plans())->default($plan > 0 ? (string) $plan : null)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->where('compensation_cycle_id', (int) $data['value']) : $query),
            ])
            ->defaultSort('id')
            ->recordActions([$this->proposeAction()])
            ->emptyStateHeading('No one of your team is in a shared plan');
    }

    /** The lines of the people the manager manages in the plans shared with them (their scope applies; never their own). */
    private function lines(): Builder
    {
        $user = auth()->user();
        $team = app(CompensationAccess::class)->teamOf($user)->all();

        return CompensationChange::query()->with(['employee.person', 'cycle'])
            ->whereIn('compensation_cycle_id', app(IncrementPlanSharing::class)->sharedWith($user)->select('id'))
            ->whereIn('employee_id', $team ?: [0])
            ->whereDoesntHave('employee', fn (Builder $e) => $e->where('user_id', $user->id));
    }

    private function proposalFor(CompensationChange $line): ?CompensationCycleProposal
    {
        $this->proposals ??= CompensationCycleProposal::query()->where('proposed_by', auth()->id())->whereNotNull('compensation_change_id')
            ->orderBy('id')->get()->keyBy('compensation_change_id');

        return $this->proposals->get((int) $line->id);
    }

    private function proposeAction(): Action
    {
        return Action::make('propose')->label('Propose')->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (CompensationChange $record) => $record->status === 'draft' && $record->cycle?->status === 'draft')
            ->modalHeading(fn (CompensationChange $record) => 'Propose · '.$record->employee?->employee_code.' · '.($record->employee?->person?->full_name ?? ''))
            ->modalDescription(fn (CompensationChange $record) => $this->rangeLine($record))
            ->fillForm(fn (CompensationChange $record) => ['ctc_annual' => (float) ($this->proposalFor($record)?->ctc_annual ?? $record->ctc_annual), 'note' => $this->proposalFor($record)?->note])
            ->schema([
                TextInput::make('ctc_annual')->label('Proposed annual CTC')->numeric()->minValue(1)->required(),
                Textarea::make('note')->label('Note for HR (optional)')->rows(2)->maxLength(2000),
            ])
            ->action(function (CompensationChange $record, array $data) {
                $this->proposals = null;
                CompensationActions::run(fn () => app(IncrementPlanSharing::class)->propose($record, (float) $data['ctc_annual'], $data['note'] ?? null, auth()->user()), 'Proposal sent to HR');
            });
    }

    /** What the manager may propose for the line: the grid's range for the person. */
    private function rangeLine(CompensationChange $line): string
    {
        $cycle = $line->cycle;
        $current = $cycle && $line->employee ? app(CompensationOutput::class)->on($line->employee, $cycle->effective_from) : null;
        if ($cycle === null || $current === null) {
            return 'Propose an annual CTC within the plan\'s grid and what it gives your team.';
        }
        $range = app(IncrementCalculator::class)->range($cycle, $line->employee, $current);

        return 'An increase between '.number_format($range['low'], 2).' and '.number_format($range['high'], 2).' a year (the plan\'s grid for this person), and your team\'s proposals together within what the plan gives your team.';
    }
}
