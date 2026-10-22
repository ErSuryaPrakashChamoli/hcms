<?php

namespace App\Filament\Resources\SalaryStructures\RelationManagers;

use App\Domain\Compensation\Models\SalaryStructureVersion;
use App\Domain\Compensation\Services\CompensationStructures;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\FormulaEngine;
use App\Filament\Support\CompensationActions;
use App\Filament\Support\SalaryFormulaUi;
use App\Filament\Support\StructureApplicabilitySchema;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Phase 11 §6: a structure's versions. Only a draft is edited; it is submitted and approved by another
 * person, and an approved version never changes — a correction is a new version from a later date.
 * §30: the formula guide, structure parameters and a live breakup preview help build the formulas.
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
                Action::make('newVersion')->label('New version')->icon('heroicon-m-document-duplicate')
                    ->visible(fn () => auth()->user()->can('compensation.configure'))
                    ->schema([DatePicker::make('effective_from')->native(false)->required()->default(now()->addMonth()->startOfMonth())->helperText('Copies the latest approved version; edit the draft, then submit it for approval.')])
                    ->action(fn (array $data) => CompensationActions::run(fn () => app(CompensationStructures::class)->newVersion($this->getOwnerRecord(), auth()->user(), $data['effective_from']), 'Draft version created')),
                SalaryFormulaUi::guideAction(),
            ])
            ->recordActions([
                Action::make('components')->label('Components')->icon('heroicon-m-list-bullet')->color('gray')->modalSubmitAction(false)
                    ->schema(fn (SalaryStructureVersion $record) => [
                        ...($record->parameters ? [TextEntry::make('parameters')->label('Parameters')->state(collect($record->parameters)->map(fn ($value, $name) => "{$name} = ".(float) $value)->implode(' · '))] : []),
                        ...$record->components()->with('component')->get()
                            ->map(fn ($c) => TextEntry::make("c{$c->id}")->label("{$c->sort_order}. {$c->component?->name} ({$c->component?->code})")
                                ->state(ucfirst($c->pay_nature).' · '.str_replace('_', '-', $c->frequency).' · '.(($formula = $c->component?->formulaFor($c->formula_override)) ? $formula.(filled($c->formula_override) ? ' (override)' : '') : 'fixed amount from the employee\'s compensation')))->all(),
                    ]),
                Action::make('preview')->label('Preview')->icon('heroicon-m-calculator')->color('gray')
                    ->modalHeading(fn (SalaryStructureVersion $record) => "Preview breakup: v{$record->version}")->modalWidth('6xl')
                    ->modalDescription('Try the structure on a sample CTC. Nothing here is saved.')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema(fn (SalaryStructureVersion $record) => SalaryFormulaUi::preview(fn () => $record->formulaLines(), fn () => (array) ($record->parameters ?? []), fn () => $record->effective_from)),
                ActionGroup::make([
                    Action::make('edit')->label('Edit draft')->icon('heroicon-m-pencil-square')->modalWidth('5xl')
                        ->visible(fn (SalaryStructureVersion $record) => $record->status === 'draft' && auth()->user()->can('compensation.configure'))
                        ->fillForm(fn (SalaryStructureVersion $record) => $record->only(['effective_from', 'currency', 'pay_frequency', 'change_note']) + StructureApplicabilitySchema::fill($record) + ['components' => $record->components()->get()->map(fn ($c) => $c->only(['salary_component_id', 'formula_override', 'pay_nature', 'frequency', 'sort_order']))->all()]
                            + ['parameters' => $record->parameters ?? [], 'preview_ctc' => 600000, 'preview_days' => 30, 'preview_pf' => true, 'preview_pf_ceiling' => true])
                        ->schema([
                            DatePicker::make('effective_from')->native(false)->required(),
                            Select::make('currency')->options(fn () => array_combine(config('peopleos.compensation.currencies'), config('peopleos.compensation.currencies')))->required(),
                            Select::make('pay_frequency')->options(config('peopleos.compensation.pay_frequencies'))->required(),
                            ...StructureApplicabilitySchema::fields(),
                            Textarea::make('change_note')->label('What changes and why')->rows(2),
                            KeyValue::make('parameters')->label('Parameters')->keyLabel('Name')->valueLabel('Number')->addActionLabel('Add parameter')->live(onBlur: true)
                                ->helperText('Named numbers the formulas can use, e.g. basic_pct = 50 with the formula ctc_monthly * basic_pct / 100. Change a number here instead of rewriting formulas.'),
                            Repeater::make('components')->hintAction(SalaryFormulaUi::guideAction('componentsGuide'))->schema([
                                Select::make('salary_component_id')->label('Component')->required()->searchable()->live()
                                    ->options(fn () => SalaryComponent::query()->where('status', 'active')->where('is_statutory', false)->orderBy('sort_order')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->name} ({$c->code})"])->all()),
                                Select::make('pay_nature')->options(config('peopleos.compensation.pay_natures'))->default('fixed')->required(),
                                Select::make('frequency')->options(config('peopleos.compensation.component_frequencies'))->default('monthly')->required(),
                                TextInput::make('sort_order')->numeric()->default(100),
                                Textarea::make('formula_override')->rows(2)->columnSpanFull()->live(onBlur: true)->placeholder(fn (Get $get) => SalaryFormulaUi::defaultFormula($get('salary_component_id')))
                                    ->helperText('Optional: this structure’s own formula for the component (fixed ones too). It can use the rows above, the parameters and the variables in the formula guide.')
                                    ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                                        if (blank($value)) {
                                            return;
                                        }
                                        try {
                                            app(FormulaEngine::class)->validate((string) $value);
                                        } catch (RuntimeException $e) {
                                            $fail($e->getMessage());
                                        }
                                    }),
                            ])->columns(4)->defaultItems(0),
                            Section::make('Preview breakup')->description('Try this draft on a sample CTC as you edit it. Nothing here is saved.')->collapsible()->collapsed()
                                ->schema(SalaryFormulaUi::preview(fn (Get $get) => SalaryFormulaUi::linesFromForm($get('components')), fn (Get $get) => SalaryFormulaUi::parametersFromForm($get('parameters')), fn (Get $get) => $get('effective_from'))),
                        ])
                        ->action(fn (SalaryStructureVersion $record, array $data) => CompensationActions::run(fn () => app(CompensationStructures::class)->updateDraft($record, StructureApplicabilitySchema::toServiceData($data), auth()->user()), 'Draft saved')),
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
