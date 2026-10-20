<?php

namespace App\Filament\Resources\SalaryStructures\RelationManagers;

use App\Domain\Compensation\Models\SalaryStructureVersion;
use App\Domain\Compensation\Services\CompensationStructures;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\FormulaEngine;
use App\Filament\Support\CompensationActions;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Phase 11 §6: a structure's versions. Only a draft is edited; it is submitted and approved by another
 * person, and an approved version never changes — a correction is a new version from a later date.
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
                TextColumn::make('company.name')->label('Company')->placeholder('Every company')->toggleable(),
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
            ])
            ->recordActions([
                Action::make('components')->label('Components')->icon('heroicon-m-list-bullet')->color('gray')->modalSubmitAction(false)
                    ->schema(fn (SalaryStructureVersion $record) => $record->components()->with('component')->get()
                        ->map(fn ($c) => TextEntry::make("c{$c->id}")->label("{$c->sort_order}. {$c->component?->name} ({$c->component?->code})")
                            ->state(ucfirst($c->pay_nature).' · '.str_replace('_', '-', $c->frequency).' · '.($c->formula_override ?: ($c->component?->calculation_method === 'formula' ? $c->component?->formula : 'fixed amount from the employee\'s compensation'))))->all()),
                ActionGroup::make([
                    Action::make('edit')->label('Edit draft')->icon('heroicon-m-pencil-square')
                        ->visible(fn (SalaryStructureVersion $record) => $record->status === 'draft' && auth()->user()->can('compensation.configure'))
                        ->fillForm(fn (SalaryStructureVersion $record) => $record->only(['effective_from', 'currency', 'pay_frequency', 'company_id', 'grade_ids', 'change_note']) + ['components' => $record->components()->get()->map(fn ($c) => $c->only(['salary_component_id', 'formula_override', 'pay_nature', 'frequency', 'sort_order']))->all()])
                        ->schema([
                            DatePicker::make('effective_from')->native(false)->required(),
                            Select::make('currency')->options(fn () => array_combine(config('peopleos.compensation.currencies'), config('peopleos.compensation.currencies')))->required(),
                            Select::make('pay_frequency')->options(config('peopleos.compensation.pay_frequencies'))->required(),
                            Select::make('company_id')->label('Company (empty = every company)')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                            Select::make('grade_ids')->label('Grades (empty = every grade)')->multiple()->options(fn () => Grade::query()->orderBy('name')->pluck('name', 'id')->all()),
                            Textarea::make('change_note')->label('What changes and why')->rows(2),
                            Repeater::make('components')->schema([
                                Select::make('salary_component_id')->label('Component')->required()->searchable()
                                    ->options(fn () => SalaryComponent::query()->where('status', 'active')->where('is_statutory', false)->orderBy('sort_order')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->name} ({$c->code})"])->all()),
                                Select::make('pay_nature')->options(config('peopleos.compensation.pay_natures'))->default('fixed')->required(),
                                Select::make('frequency')->options(config('peopleos.compensation.component_frequencies'))->default('monthly')->required(),
                                TextInput::make('sort_order')->numeric()->default(100),
                                Textarea::make('formula_override')->rows(2)->helperText('Optional: replaces the component formula in this version')
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
                        ])
                        ->action(fn (SalaryStructureVersion $record, array $data) => CompensationActions::run(fn () => app(CompensationStructures::class)->updateDraft($record, $data, auth()->user()), 'Draft saved')),
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
