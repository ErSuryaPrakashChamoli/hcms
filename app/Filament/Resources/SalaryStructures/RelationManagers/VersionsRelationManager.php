<?php

namespace App\Filament\Resources\SalaryStructures\RelationManagers;

use App\Domain\Compensation\Models\SalaryStructureVersion;
use App\Domain\Compensation\Services\CompensationStructures;
use App\Filament\Pages\SalaryStructureBuilder;
use App\Filament\Support\CompensationActions;
use App\Filament\Support\SalaryFormulaUi;
use App\Filament\Support\StructureApplicabilitySchema;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 11 §6: a structure's versions. Only a draft is edited (in the salary structure builder); it is submitted
 * and approved by another person, and an approved version never changes — a correction is a new version from a
 * later date. Components shows a version's lines; Preview tries it on a sample CTC.
 */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', SalaryStructureVersion::class) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('components')->with(['preparer', 'approver', 'company']))
            ->columns([
                TextColumn::make('version')->label('v')->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => SalaryStructureVersion::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success', 'scheduled' => 'info', 'pending_approval' => 'warning', default => 'gray'
                    }),
                TextColumn::make('effective_from')->label('From')->date(),
                TextColumn::make('effective_to')->label('To')->date()->placeholder('Open'),
                TextColumn::make('currency'),
                TextColumn::make('applies_to')->label('Applies to')->state(fn (SalaryStructureVersion $record) => StructureApplicabilitySchema::describe($record))->wrap()->toggleable(),
                TextColumn::make('components_count')->label('Components'),
                TextColumn::make('preparer.name')->label('Prepared by')->placeholder('—')->toggleable(),
                TextColumn::make('approver.name')->label('Approved by')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('version', 'desc')
            ->headerActions([
                // Components change in the salary structure builder (a new draft from the version in use, or the draft itself).
                Action::make('newVersion')->label('Change components')->icon('heroicon-m-adjustments-horizontal')
                    ->visible(fn () => auth()->user()->can('compensation.configure'))
                    ->url(fn () => SalaryStructureBuilder::getUrl(['structure' => $this->getOwnerRecord()->getKey()])),
                SalaryFormulaUi::guideAction(),
            ])
            ->recordActions([
                Action::make('components')->label('Components')->icon('heroicon-m-list-bullet')->color('gray')->modalSubmitAction(false)
                    ->schema(fn (SalaryStructureVersion $record) => [
                        ...($record->parameters ? [TextEntry::make('parameters')->label('Parameters')->state(collect($record->parameters)->map(fn ($value, $name) => "{$name} = ".(float) $value)->implode(' · '))] : []),
                        // HROne setup parity BP-2: how CTC is handled and which component balances to it.
                        ...($record->formulaOptions()['ctc_mode'] !== 'ctc' || $record->balancingComponentId() !== null ? [TextEntry::make('ctc_mode')->label('CTC')
                            ->state(SalaryStructureVersion::CTC_MODES[$record->formulaOptions()['ctc_mode']].($record->balancingComponent ? ' · balanced by '.$record->balancingComponent->code : ''))] : []),
                        ...$record->components()->with('component')->get()
                            ->map(fn ($c) => TextEntry::make("c{$c->id}")->label("{$c->sort_order}. {$c->component?->name} ({$c->component?->code})")
                                ->state(ucfirst($c->pay_nature).' · '.str_replace('_', '-', $c->frequency).' · '.($c->component?->isInput() ? 'input from the employee\'s compensation, read by formulas, never paid' : (($formula = $c->component?->formulaFor($c->formula_override)) ? $formula.(filled($c->formula_override) ? ' (override)' : '') : 'fixed amount from the employee\'s compensation'))))->all(),
                    ]),
                Action::make('preview')->label('Preview')->icon('heroicon-m-calculator')->color('gray')
                    ->modalHeading(fn (SalaryStructureVersion $record) => "Preview breakup: v{$record->version}")->modalWidth('6xl')
                    ->modalDescription('Try the structure on a sample CTC. Nothing here is saved.')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema(fn (SalaryStructureVersion $record) => SalaryFormulaUi::preview(fn () => $record->formulaLines(), fn () => (array) ($record->parameters ?? []), fn () => $record->effective_from, fn () => $record->formulaOptions())),
                ActionGroup::make([
                    Action::make('edit')->label('Edit draft')->icon('heroicon-m-pencil-square')
                        ->visible(fn (SalaryStructureVersion $record) => $record->status === 'draft' && auth()->user()->can('compensation.configure'))
                        ->url(fn () => SalaryStructureBuilder::getUrl(['structure' => $this->getOwnerRecord()->getKey()])),
                    Action::make('submit')->label('Submit for approval')->icon('heroicon-m-paper-airplane')->requiresConfirmation()
                        ->visible(fn (SalaryStructureVersion $record) => $record->status === 'draft' && auth()->user()->can('compensation.configure'))
                        ->action(fn (SalaryStructureVersion $record) => CompensationActions::run(fn () => app(CompensationStructures::class)->submit($record, auth()->user()), 'Submitted for approval')),
                    Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')->requiresConfirmation()
                        ->visible(fn (SalaryStructureVersion $record) => $record->status === 'pending_approval' && auth()->user()->can('compensation.approve') && (int) $record->prepared_by !== (int) auth()->id())
                        ->schema([Textarea::make('note')->label('Note (optional)')])
                        ->action(fn (SalaryStructureVersion $record, array $data) => CompensationActions::run(fn () => app(CompensationStructures::class)->approve($record, auth()->user(), $data['note'] ?? null), 'Version approved')),
                    Action::make('return')->label('Return to draft')->icon('heroicon-m-arrow-uturn-left')
                        ->visible(fn (SalaryStructureVersion $record) => $record->status === 'pending_approval' && auth()->user()->can('compensation.approve'))
                        ->schema([Textarea::make('note')->required()])
                        ->action(fn (SalaryStructureVersion $record, array $data) => CompensationActions::run(fn () => app(CompensationStructures::class)->returnToDraft($record, auth()->user(), $data['note']), 'Returned to draft')),
                    Action::make('archive')->label('Archive')->icon('heroicon-m-archive-box')->color('danger')
                        ->visible(fn (SalaryStructureVersion $record) => in_array($record->status, ['draft', 'pending_approval', 'superseded'], true) && auth()->user()->can('compensation.configure'))
                        ->schema([Textarea::make('reason')->required()])
                        ->action(fn (SalaryStructureVersion $record, array $data) => CompensationActions::run(fn () => app(CompensationStructures::class)->archive($record, auth()->user(), $data['reason']), 'Version archived')),
                ]),
            ]);
    }
}
