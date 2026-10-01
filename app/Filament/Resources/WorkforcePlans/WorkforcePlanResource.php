<?php

namespace App\Filament\Resources\WorkforcePlans;

use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Location;
use App\Domain\Workforce\Models\WorkforcePlanLine;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Domain\Workforce\Models\WorkforceScenario;
use App\Domain\Workforce\Services\WorkforceForecast;
use App\Domain\Workforce\Services\WorkforcePlans;
use App\Filament\Resources\WorkforcePlans\Pages\ManageWorkforcePlans;
use App\Filament\Support\TalentActions;
use App\Filament\Support\WorkforceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 10 headcount plans: one row per plan version. Draft versions take lines; submission, review,
 * approval (different people), publication (one active version per plan) and corrections (new
 * versions) go through WorkforcePlans. Plans never change live positions; "propose position" is the
 * explicit bridge and still needs approval.
 */
class WorkforcePlanResource extends Resource
{
    protected static ?string $model = WorkforcePlanVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Workforce';

    protected static ?string $navigationLabel = 'Headcount plans';

    protected static ?string $modelLabel = 'plan version';

    protected static ?int $navigationSort = 40;

    public static function getEloquentQuery(): Builder
    {
        // Versions of plans the user may see (the plan carries the organisation scope).
        $signed = collect(config('peopleos.workforce.movement_types'))->map(fn ($m, $type) => "when '{$type}' then ".((int) $m['sign']).' * headcount')->implode(' ');
        $net = WorkforcePlanLine::query()->whereColumn('workforce_plan_lines.workforce_plan_version_id', 'workforce_plan_versions.id')->selectRaw("coalesce(sum(case movement_type {$signed} else 0 end), 0)");

        return parent::getEloquentQuery()->with(['plan', 'scenario'])->whereHas('plan')->withCount('lines')->select('workforce_plan_versions.*')->selectSub($net->toBase(), 'net_headcount');
    }

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('create')->label('New plan')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('workforce.plan'))
                ->schema([
                    TextInput::make('code')->required()->maxLength(32)->alphaDash(),
                    TextInput::make('name')->required(),
                    Select::make('company_id')->label('Company')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('organisation_node_id')->label('Organisation unit')->options(fn () => TalentActions::nodeOptions())->searchable(),
                    Select::make('location_id')->label('Location')->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('workforce_scenario_id')->label('Scenario')->options(fn () => WorkforceScenario::query()->where('status', '!=', 'archived')->pluck('name', 'id')->all()),
                    Select::make('period_type')->options(config('peopleos.workforce.period_types'))->required(),
                    DatePicker::make('period_start')->native(false)->required(),
                    DatePicker::make('period_end')->native(false)->required()->afterOrEqual('period_start'),
                    Textarea::make('notes')->rows(2),
                ])
                ->action(fn (array $data) => WorkforceActions::run(fn () => app(WorkforcePlans::class)->create(WorkforceActions::filled($data), auth()->user()), 'Plan created with a draft version')),
        ];
    }

    public static function table(Table $table): Table
    {
        $user = fn () => auth()->user();

        return $table
            ->columns([
                TextColumn::make('plan.code')->label('Plan')->searchable()->description(fn (WorkforcePlanVersion $record) => $record->plan?->name),
                TextColumn::make('version')->prefix('v'),
                TextColumn::make('period')->state(fn (WorkforcePlanVersion $record) => $record->period_start->toDateString().' – '.$record->period_end->toDateString())->description(fn (WorkforcePlanVersion $record) => config("peopleos.workforce.period_types.{$record->period_type}")),
                TextColumn::make('scenario.name')->label('Scenario')->placeholder('—'),
                TextColumn::make('lines_count')->label('Lines'),
                TextColumn::make('net_headcount')->label('Net headcount'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.workforce.plan_statuses.{$state}", $state))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success', 'approved' => 'info', 'submitted', 'under_review' => 'warning', 'rejected' => 'danger', default => 'gray'
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.workforce.plan_statuses'))])
            ->recordActions([
                Action::make('lines')->label('Lines')->icon(Heroicon::OutlinedListBullet)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (WorkforcePlanVersion $record) => $record->lines()->with(['designation', 'organisationNode', 'createdPosition'])->orderBy('effective_date')->get()->map(fn (WorkforcePlanLine $l) => TextEntry::make("l{$l->id}")
                        ->label(config("peopleos.workforce.movement_types.{$l->movement_type}.label").' · '.$l->effective_date->toDateString())
                        ->state(trim(sprintf('%d seat(s), %s FTE%s%s%s', $l->headcount, $l->fte, $l->designation ? ' · '.$l->designation->name : '', $l->organisationNode ? ' · '.$l->organisationNode->auditLabel() : '',
                            auth()->user()->can('workforce.costs') && $l->planned_cost !== null ? ' · '.number_format((float) $l->planned_cost, 2).' '.$record->currency.' ('.config("peopleos.workforce.cost_bases.{$l->cost_basis}").')' : '')))
                        ->helperText($l->createdPosition ? 'Proposed position '.$l->createdPosition->code : $l->notes))->all() ?: [TextEntry::make('none')->hiddenLabel()->state('No lines yet.')]),
                Action::make('forecast')->label('Forecast')->icon(Heroicon::OutlinedChartBar)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (WorkforcePlanVersion $record) => self::forecastEntries($record)),
                ActionGroup::make([
                    Action::make('addLine')->label('Add line')->icon(Heroicon::OutlinedPlus)
                        ->visible(fn (WorkforcePlanVersion $record) => $record->status === 'draft' && $user()->can('workforce.plan'))
                        ->schema([
                            Select::make('movement_type')->label('Movement')->options(collect(config('peopleos.workforce.movement_types'))->map(fn ($m) => $m['label'])->all())->required(),
                            Select::make('position_id')->label('Existing position')->options(fn () => WorkforceActions::positionOptions())->searchable(),
                            Select::make('organisation_node_id')->label('Organisation unit')->options(fn () => TalentActions::nodeOptions())->searchable(),
                            Select::make('location_id')->label('Location')->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
                            Select::make('designation_id')->label('Designation')->options(fn () => Designation::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                            Select::make('job_family_id')->label('Job family')->options(fn () => JobFamily::query()->orderBy('name')->pluck('name', 'id')->all()),
                            TextInput::make('headcount')->numeric()->minValue(0)->required(),
                            TextInput::make('fte')->label('FTE')->numeric()->minValue(0),
                            DatePicker::make('effective_date')->native(false)->required(),
                            TextInput::make('planned_cost')->numeric()->visible(fn () => auth()->user()->can('workforce.costs')),
                            Select::make('cost_basis')->options(config('peopleos.workforce.cost_bases'))->visible(fn () => auth()->user()->can('workforce.costs')),
                            Textarea::make('notes')->rows(2),
                        ])
                        ->action(fn (WorkforcePlanVersion $record, array $data) => WorkforceActions::run(fn () => app(WorkforcePlans::class)->addLine($record, WorkforceActions::filled($data), auth()->user()), 'Line added')),
                    Action::make('submit')->label('Submit')->icon(Heroicon::OutlinedPaperAirplane)->requiresConfirmation()
                        ->visible(fn (WorkforcePlanVersion $record) => $record->status === 'draft' && $user()->can('workforce.plan'))
                        ->action(fn (WorkforcePlanVersion $record) => WorkforceActions::run(fn () => app(WorkforcePlans::class)->submit($record, auth()->user()), 'Submitted for review')),
                    Action::make('review')->label('Start review')->icon(Heroicon::OutlinedMagnifyingGlass)->requiresConfirmation()
                        ->visible(fn (WorkforcePlanVersion $record) => $record->status === 'submitted' && ! $record->workflow_instance_id && ($user()->can('workforce.review') || $user()->can('workforce.approve')) && (int) $record->submitted_by !== (int) auth()->id())
                        ->action(fn (WorkforcePlanVersion $record) => WorkforceActions::run(fn () => app(WorkforcePlans::class)->startReview($record, auth()->user()), 'Under review')),
                    Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')
                        ->visible(fn (WorkforcePlanVersion $record) => $record->status === 'under_review' && ! $record->workflow_instance_id && $user()->can('workforce.approve') && (int) $record->submitted_by !== (int) auth()->id())
                        ->schema([Textarea::make('note')->rows(2)])
                        ->action(fn (WorkforcePlanVersion $record, array $data) => WorkforceActions::run(fn () => app(WorkforcePlans::class)->approve($record, $data['note'] ?? null, auth()->user()), 'Plan version approved')),
                    Action::make('reject')->label('Reject')->icon(Heroicon::OutlinedXMark)->color('danger')
                        ->visible(fn (WorkforcePlanVersion $record) => in_array($record->status, ['submitted', 'under_review'], true) && ! $record->workflow_instance_id && ($user()->can('workforce.review') || $user()->can('workforce.approve')) && (int) $record->submitted_by !== (int) auth()->id())
                        ->schema([Textarea::make('note')->required()])
                        ->action(fn (WorkforcePlanVersion $record, array $data) => WorkforceActions::run(fn () => app(WorkforcePlans::class)->reject($record, $data['note'], auth()->user()), 'Plan version rejected')),
                    Action::make('return')->label('Return for changes')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                        ->visible(fn (WorkforcePlanVersion $record) => in_array($record->status, ['submitted', 'under_review'], true) && ! $record->workflow_instance_id && ($user()->can('workforce.review') || $user()->can('workforce.approve')) && (int) $record->submitted_by !== (int) auth()->id())
                        ->schema([Textarea::make('note')->required()])
                        ->action(fn (WorkforcePlanVersion $record, array $data) => WorkforceActions::run(fn () => app(WorkforcePlans::class)->returnToDraft($record, $data['note'], auth()->user()), 'Returned to draft')),
                    Action::make('publish')->label('Publish (make active)')->icon(Heroicon::OutlinedRocketLaunch)->color('success')
                        ->visible(fn (WorkforcePlanVersion $record) => $record->status === 'approved' && $user()->can('workforce.approve'))
                        ->schema([DatePicker::make('effective_from')->native(false)])
                        ->action(fn (WorkforcePlanVersion $record, array $data) => WorkforceActions::run(fn () => app(WorkforcePlans::class)->publish($record, $data['effective_from'] ?? null, auth()->user()), 'Plan version is active')),
                    Action::make('newVersion')->label('New version (correction)')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
                        ->visible(fn (WorkforcePlanVersion $record) => in_array($record->status, ['approved', 'active', 'rejected', 'superseded'], true) && $user()->can('workforce.plan'))
                        ->requiresConfirmation()
                        ->action(fn (WorkforcePlanVersion $record) => WorkforceActions::run(fn () => app(WorkforcePlans::class)->createVersion($record->plan, [], auth()->user(), $record), fn ($v) => "Draft version {$v->version} created")),
                    Action::make('propose')->label('Propose position from line')->icon(Heroicon::OutlinedRectangleStack)
                        ->visible(fn (WorkforcePlanVersion $record) => in_array($record->status, ['approved', 'active'], true) && $user()->can('workforce.manage'))
                        ->schema(fn (WorkforcePlanVersion $record) => [
                            Select::make('line_id')->label('Line')->required()->options($record->lines()->whereIn('movement_type', ['new_position', 'expansion'])->whereNull('created_position_id')->get()
                                ->mapWithKeys(fn ($l) => [$l->id => config("peopleos.workforce.movement_types.{$l->movement_type}.label").' · '.$l->headcount.' seat(s) · '.$l->effective_date->toDateString()])->all()),
                            TextInput::make('code')->label('Position code')->required()->alphaDash(),
                        ])
                        ->action(fn (WorkforcePlanVersion $record, array $data) => WorkforceActions::run(fn () => app(WorkforcePlans::class)->proposePositionFromLine($record->lines()->findOrFail($data['line_id']), $data['code'], auth()->user()), fn ($p) => "Position {$p->code} proposed — it still needs approval")),
                    Action::make('archive')->label('Archive')->icon(Heroicon::OutlinedArchiveBox)->color('danger')
                        ->visible(fn (WorkforcePlanVersion $record) => in_array($record->status, ['draft', 'approved', 'active', 'superseded', 'rejected'], true) && ($user()->can('workforce.plan') || $user()->can('workforce.approve')))
                        ->schema([Textarea::make('reason')->required()])
                        ->action(fn (WorkforcePlanVersion $record, array $data) => WorkforceActions::run(fn () => app(WorkforcePlans::class)->archive($record, $data['reason'], auth()->user()), 'Archived')),
                ])->label('Actions')->icon(Heroicon::OutlinedEllipsisVertical),
            ])
            ->emptyStateHeading('No workforce plans')->emptyStateDescription('Plans state intended capacity; they never change live positions or employees.');
    }

    /** @return list<TextEntry> */
    private static function forecastEntries(WorkforcePlanVersion $record): array
    {
        $forecast = app(WorkforceForecast::class)->forPlan($record);
        $b = $forecast['basis'];

        return [
            TextEntry::make('basis')->label('At '.$b['start_date'])->state("{$b['approved_seats']} approved seat(s), {$b['occupied_seats']} occupied"),
            TextEntry::make('assumption')->label('Attrition')->state($b['attrition_assumption_percent'] === null ? 'No assumption on the scenario' : $b['attrition_assumption_percent'].'% per year')->helperText($b['assumption_label']),
            ...collect($forecast['months'])->map(fn ($m) => TextEntry::make('m'.str_replace('-', '', $m['month']))->label($m['month'])
                ->state(sprintf('Planned seats %d (%+d) · recorded exits %d · occupied after recorded exits %d%s', $m['planned_seats'], $m['planned_change'], $m['recorded_exits'], $m['occupied_after_recorded_exits'],
                    $m['assumed_attrition'] === null ? '' : ' · assumed attrition '.$m['assumed_attrition'].' (assumption)')))->all(),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ManageWorkforcePlans::route('/')];
    }
}
