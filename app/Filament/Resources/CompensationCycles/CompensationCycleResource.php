<?php

namespace App\Filament\Resources\CompensationCycles;

use App\Domain\Compensation\Models\CompensationBudget;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Domain\Compensation\Services\IncrementCalculator;
use App\Domain\Compensation\Services\IncrementPlanSharing;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Filament\Pages\IncrementCalculator as IncrementCalculatorPage;
use App\Filament\Resources\CompensationCycles\Pages\ManageCompensationCycles;
use App\Filament\Resources\CompensationCycles\Pages\ViewCompensationCycle;
use App\Filament\Resources\CompensationCycles\RelationManagers\LinesRelationManager;
use App\Filament\Support\CompensationActions;
use App\Filament\Support\IncrementCalculatorWizard;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Phase 11 §15: bulk compensation cycles. Population snapshots the eligible employees and proposes each
 * line deterministically; preparer, reviewer, approver and executor are four people; execution writes
 * every line or none, once, under one operation id. No uncontrolled "update salaries" action exists.
 */
class CompensationCycleResource extends Resource
{
    protected static ?string $model = CompensationCycle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Compensation';

    protected static ?string $navigationLabel = 'Pay review cycles';

    protected static ?int $navigationSort = 40;

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            // HROne parity PO-17: the increment calculator's wizard (bands × ratings, basis, proration, population).
            Action::make('incrementCalculator')->label('Increment calculator')->icon(Heroicon::OutlinedCalculator)->color('gray')
                ->visible(fn () => IncrementCalculatorPage::canAccess())
                ->url(fn () => IncrementCalculatorPage::getUrl(['action' => 'create'])),
            Action::make('create')->label('New cycle')->icon(Heroicon::OutlinedPlus)->visible(fn () => auth()->user()->can('compensation.cycles'))
                ->schema([
                    TextInput::make('code')->required()->maxLength(40),
                    TextInput::make('name')->required(),
                    Select::make('cycle_type')->options(collect(config('peopleos.compensation.cycle_types'))->reject(fn ($t) => $t['upload'] ?? false)->map(fn ($t) => $t['label'])->all())->required(),
                    Select::make('company_id')->label('Company')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all())->required(),
                    Select::make('organisation_node_id')->label('Organisation unit (optional)')->options(fn () => TalentActions::nodeOptions())->searchable(),
                    Select::make('location_id')->label('Location (optional)')->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
                    DatePicker::make('effective_from')->native(false)->required(),
                    TextInput::make('default_increase_percent')->label('Default increase %')->numeric()->default(0)->required(),
                    Select::make('compensation_budget_id')->label('Budget (optional)')->options(fn () => CompensationBudget::query()->where('status', 'approved')->pluck('name', 'id')->all()),
                    Select::make('performance_cycle_id')->label('Finalized performance cycle (optional)')->options(fn () => PerformanceCycle::query()->orderByDesc('period_end')->pluck('name', 'id')->all())
                        ->helperText('Only finalized outcomes are read; they only prefill proposals that people still review and approve.'),
                    KeyValue::make('rating_increase_percent')->label('Increase % by finalized rating label')->keyLabel('Rating label')->valueLabel('%'),
                ])
                ->action(fn (array $data) => CompensationActions::run(fn () => app(CompensationCycles::class)->create($data, auth()->user()), 'Cycle created as a draft')),
        ];
    }

    /** @return array<int, Action> the steps on the cycle page */
    public static function stepActions(): array
    {
        $service = fn () => app(CompensationCycles::class);
        $user = fn () => auth()->user();

        return [
            Action::make('populate')->label('Populate')->icon('heroicon-m-user-group')->requiresConfirmation()
                ->modalDescription('Snapshots the eligible employees and their current compensation and proposes each line; earlier draft lines are replaced.')
                ->visible(fn (CompensationCycle $record) => $record->status === 'draft' && ! $record->isUpload() && (int) $record->prepared_by === (int) auth()->id())
                ->action(fn (CompensationCycle $record) => CompensationActions::run(fn () => $service()->populate($record, $user()), fn ($r) => "{$r['lines']} line(s) proposed, {$r['skipped']} skipped")),
            Action::make('submit')->label('Submit for review')->icon('heroicon-m-paper-airplane')->requiresConfirmation()
                ->visible(fn (CompensationCycle $record) => $record->status === 'draft' && (int) $record->prepared_by === (int) auth()->id())
                ->action(fn (CompensationCycle $record) => CompensationActions::run(fn () => $service()->submit($record, $user()), 'Cycle submitted')),
            Action::make('review')->label('Mark reviewed')->icon('heroicon-m-eye')->requiresConfirmation()
                ->visible(fn (CompensationCycle $record) => $record->status === 'submitted' && auth()->user()->can('compensation.review') && (int) $record->prepared_by !== (int) auth()->id())
                ->action(fn (CompensationCycle $record) => CompensationActions::run(fn () => $service()->review($record, $user()), 'Cycle reviewed')),
            Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')->requiresConfirmation()
                ->visible(fn (CompensationCycle $record) => $record->status === 'under_review' && auth()->user()->can('compensation.approve') && ! in_array((int) auth()->id(), [(int) $record->prepared_by, (int) $record->reviewed_by], true))
                ->action(fn (CompensationCycle $record) => CompensationActions::run(fn () => $service()->approve($record, $user()), 'Cycle approved')),
            Action::make('reject')->label('Reject')->icon('heroicon-m-x-mark')->color('danger')
                ->visible(fn (CompensationCycle $record) => in_array($record->status, ['submitted', 'under_review'], true) && (auth()->user()->can('compensation.review') || auth()->user()->can('compensation.approve')) && (int) $record->prepared_by !== (int) auth()->id())
                ->schema([Textarea::make('note')->required()])
                ->action(fn (CompensationCycle $record, array $data) => CompensationActions::run(fn () => $service()->reject($record, $user(), $data['note']), 'Cycle rejected')),
            Action::make('execute')->label('Execute')->icon('heroicon-m-play')->color('warning')->requiresConfirmation()
                ->modalDescription('Writes every line into the compensation timeline under one operation, or none if any line cannot be executed.')
                ->visible(fn (CompensationCycle $record) => $record->status === 'approved' && auth()->user()->can('compensation.execute') && ! in_array((int) auth()->id(), [(int) $record->prepared_by, (int) $record->reviewed_by, (int) $record->approved_by], true))
                ->action(fn (CompensationCycle $record) => CompensationActions::run(fn () => $service()->execute($record, $user()), fn ($op) => "Cycle executed (operation {$op})")),
            Action::make('cancel')->label('Cancel')->icon('heroicon-m-no-symbol')->color('danger')
                ->visible(fn (CompensationCycle $record) => in_array($record->status, ['draft', 'submitted', 'under_review', 'approved'], true) && ((int) $record->prepared_by === (int) auth()->id() || auth()->user()->can('compensation.approve')))
                ->schema([Textarea::make('reason')->required()])
                ->action(fn (CompensationCycle $record, array $data) => CompensationActions::run(fn () => $service()->cancel($record, $user(), $data['reason']), 'Cycle cancelled')),
            // HROne parity PO-17: share with managers, clone, the employees skipped.
            IncrementCalculatorWizard::shareAction(),
            IncrementCalculatorWizard::cloneAction(),
            IncrementCalculatorWizard::errorFileAction(),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('code'), TextEntry::make('name'), TextEntry::make('status')->badge(),
            TextEntry::make('cycle_type')->formatStateUsing(fn (string $state) => config("peopleos.compensation.cycle_types.{$state}.label", $state)),
            TextEntry::make('effective_from')->date(), TextEntry::make('employee_count')->label('Lines'),
            TextEntry::make('default_increase_percent')->label('Default increase %'),
            TextEntry::make('rating_increase_percent')->label('By rating')->state(fn (CompensationCycle $record) => $record->hasGrid() ? 'CTC band × rating grid (below)' : (collect($record->rating_increase_percent ?? [])->map(fn ($v, $k) => "{$k}: {$v}%")->implode(', ') ?: '—')),
            TextEntry::make('operation_id')->label('Operation')->placeholder('—'),
            // HROne parity PO-17: the increment calculator's rules and summary.
            Section::make('Increment calculator')->columnSpanFull()->columns(3)->visible(fn (CompensationCycle $record) => ! $record->isUpload())->schema([
                TextEntry::make('increment_basis')->label('Basis')->formatStateUsing(fn (?string $state) => CompensationCycle::BASES[$state ?? 'ctc_percent'] ?? $state)
                    ->suffix(fn (CompensationCycle $record) => $record->isAmountBasis() && $record->default_increase_amount !== null ? ' · default '.number_format((float) $record->default_increase_amount, 2) : null),
                TextEntry::make('prorate_on_tenure')->label('Tenure proration')->state(fn (CompensationCycle $record) => $record->prorate_on_tenure
                    ? (CompensationCycle::PRORATION_BASES[$record->proration_basis] ?? $record->proration_basis).' up to '.$record->proration_cutoff?->format('j M Y').'; full increment at '.$record->proration_full_months.' months'
                    : 'No'),
                TextEntry::make('population')->label('Population')->state(fn (CompensationCycle $record) => self::populationLine($record)),
                TextEntry::make('grid')->label('CTC band × rating grid')->columnSpanFull()->visible(fn (CompensationCycle $record) => $record->hasGrid())
                    ->state(fn (CompensationCycle $record) => new HtmlString(self::gridHtml($record))),
                TextEntry::make('summary')->label('Summary')->columnSpanFull()->visible(fn (CompensationCycle $record) => $record->employee_count > 0 || count((array) ($record->population_errors ?? [])) > 0)
                    ->state(fn (CompensationCycle $record) => new HtmlString(IncrementCalculatorWizard::summaryHtml(app(IncrementCalculator::class)->summary($record)))),
                TextEntry::make('shared_with')->label('Shared with')->state(fn (CompensationCycle $record) => app(IncrementPlanSharing::class)->sharesOf($record)->map(fn ($s) => $s->user?->name)->filter()->implode(', ') ?: '—'),
                TextEntry::make('clonedFrom.code')->label('Cloned from')->placeholder('—'),
            ]),
        ]);
    }

    /** HROne parity PO-17: who the population filters keep. */
    public static function populationLine(CompensationCycle $record): string
    {
        $population = (array) ($record->population ?? []);
        $parts = [];
        foreach ((array) ($population['organisation'] ?? []) as $column => $ids) {
            $parts[] = str_replace('_id', '', $column).': '.count((array) $ids);
        }
        if (($population['codes'] ?? []) !== []) {
            $parts[] = count($population['codes']).' pasted code(s)';
        }
        if (($population['exclude_codes'] ?? []) !== []) {
            $parts[] = count($population['exclude_codes']).' code(s) left out';
        }

        return $parts === [] ? 'Everyone of the company (and unit) taking part in cycles' : implode(' · ', str_replace('_', ' ', $parts));
    }

    /** HROne parity PO-17: the grid as a small table. */
    public static function gridHtml(CompensationCycle $record): string
    {
        $unit = $record->isAmountBasis() ? '' : '%';
        $rows = '';
        foreach ((array) ($record->rating_increase_percent['bands'] ?? []) as $band) {
            $range = number_format((float) $band['ctc_from'], 2).($band['ctc_to'] !== null ? ' – '.number_format((float) $band['ctc_to'], 2) : ' and above');
            $cells = collect($band['ratings'])->map(fn ($r) => 'rating '.$r['from'].'–'.$r['to'].': '.$r['value'].$unit);
            if ($band['value'] !== null) {
                $cells->push('any other rating: '.$band['value'].$unit);
            }
            $rows .= '<tr><td class="pr-4 tabular-nums">'.e($range).'</td><td>'.e($cells->implode(' · ')).'</td></tr>';
        }

        return '<table class="text-sm" data-increment-grid><thead><tr><th class="pr-4 text-left">Annual CTC</th><th class="text-left">Value</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('company'))
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('cycle_type')->label('Type')->formatStateUsing(fn (string $state) => config("peopleos.compensation.cycle_types.{$state}.label", $state)),
                TextColumn::make('company.name')->label('Company'),
                TextColumn::make('effective_from')->date(),
                TextColumn::make('employee_count')->label('Lines'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => CompensationCycle::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'executed' => 'success', 'approved' => 'info', 'rejected', 'cancelled' => 'danger', default => 'warning'
                    }),
            ])
            ->defaultSort('effective_from', 'desc')
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No compensation cycles');
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ManageCompensationCycles::route('/'), 'view' => ViewCompensationCycle::route('/{record}')];
    }
}
