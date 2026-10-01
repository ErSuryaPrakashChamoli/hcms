<?php

namespace App\Filament\Resources\SuccessionPlans;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Succession\Models\Successor;
use App\Domain\Succession\Services\Readiness;
use App\Domain\Succession\Services\SuccessionPlans;
use App\Filament\Resources\SuccessionPlans\Pages\ManageSuccessionPlans;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 9 succession plans: one open plan per critical position; successors are added and removed
 * by people with reasons; readiness is a recorded label. The plan never appoints anyone, and
 * candidates are not told (candidacy is confidential). Confidential notes are read through
 * TalentAccess (audited).
 */
class SuccessionPlanResource extends Resource
{
    protected static ?string $model = SuccessionPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Succession plans';

    protected static ?int $navigationSort = 50;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['position.designation', 'position.currentAssessment'])->withCount(['successors as active_successors' => fn ($q) => $q->where('status', 'active')]))
            ->columns([
                TextColumn::make('position.title')->label('Critical position')->searchable()->wrap(),
                TextColumn::make('position.currentAssessment.criticality')->label('Criticality')->badge()->color('gray'),
                TextColumn::make('active_successors')->label('Successors'),
                TextColumn::make('vacancy_risk')->label('Vacancy risk')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('review_date')->date()->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'active' => 'success', 'under_review' => 'warning', 'draft' => 'info', default => 'gray'
                }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'under_review' => 'Under review', 'closed' => 'Closed'])])
            ->recordActions([
                Action::make('successors')->label('Successors')->icon(Heroicon::OutlinedListBullet)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (SuccessionPlan $record) => [
                        ...$record->successors()->with('employee.person')->orderBy('status')->get()->filter(fn (Successor $s) => auth()->user()->can('view', $s))->map(fn (Successor $s) => TextEntry::make("s{$s->id}")
                            ->label(($s->employee?->employee_code ?? '').' · '.($s->employee?->person?->full_name ?? '').' — '.$s->status)
                            ->state(trim(($s->strengths ? "Strengths: {$s->strengths}. " : '').($s->development_gaps ? "Gaps: {$s->development_gaps}." : '')) ?: '—')
                            ->helperText('Readiness: '.(app(Readiness::class)->current(Employee::query()->withoutGlobalScopes([AccessScope::class])->find($s->employee_id), $record->critical_position_id, null)?->readiness_level ?? 'not assessed')))->all(),
                        TextEntry::make('notes')->label('Confidential notes')->state(fn () => TalentActions::confidential($record, 'confidential_notes'))->visible(fn () => auth()->user()->can('talent.confidential')),
                    ]),
                Action::make('addSuccessor')->label('Add successor')->icon(Heroicon::OutlinedUserPlus)->color('primary')
                    ->visible(fn (SuccessionPlan $record) => $record->isOpen() && auth()->user()->can('succession.manage'))
                    ->schema([
                        Select::make('employee_id')->label('Employee')->options(fn () => TalentActions::scopedPeopleOptions())->searchable()->required(),
                        Textarea::make('strengths')->rows(2),
                        Textarea::make('development_gaps')->label('Development gaps')->rows(2),
                        Textarea::make('confidential_notes')->label('Confidential notes')->rows(2),
                    ])
                    ->action(fn (SuccessionPlan $record, array $data) => TalentActions::run(fn () => app(SuccessionPlans::class)->addSuccessor($record, Employee::query()->findOrFail($data['employee_id']), $data['strengths'] ?? null, $data['development_gaps'] ?? null, $data['confidential_notes'] ?? null, auth()->user()), 'Successor added')),
                Action::make('transition')->label('Change status')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->visible(fn (SuccessionPlan $record) => (SuccessionPlan::TRANSITIONS[$record->status] ?? []) !== [] && auth()->user()->can('succession.manage'))
                    ->schema(fn (SuccessionPlan $record) => [
                        Select::make('to')->label('New status')->required()->options(collect(SuccessionPlan::TRANSITIONS[$record->status] ?? [])->mapWithKeys(fn ($s) => [$s => str_replace('_', ' ', ucfirst($s))])->all()),
                        Textarea::make('reason')->maxLength(500)->helperText('Required when closing.'),
                    ])
                    ->action(fn (SuccessionPlan $record, array $data) => TalentActions::run(fn () => app(SuccessionPlans::class)->transition($record, $data['to'], $data['reason'] ?? null, auth()->user()), 'Status changed')),
            ])
            ->emptyStateHeading('No succession plans')->emptyStateDescription('Create a plan from a critical position.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSuccessionPlans::route('/')];
    }
}
