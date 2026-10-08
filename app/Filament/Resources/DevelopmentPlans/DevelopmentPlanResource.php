<?php

namespace App\Filament\Resources\DevelopmentPlans;

use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Development\Models\DevelopmentPlanItem;
use App\Domain\Development\Services\DevelopmentPlans;
use App\Domain\Employment\Models\Employee;
use App\Domain\Learning\Models\Course;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\DevelopmentPlans\Pages\ManageDevelopmentPlans;
use App\Filament\Support\LearningActions;
use BackedEnum;
use Filament\Actions\Action;
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
 * Phase 8 development plans: employee, manager and L&D work through goals, skill gaps, learning,
 * milestones and reviews. Needs come from the Performance boundary; learning is recommended, and
 * enrolled only when someone chooses to. Closed plans are read-only history.
 */
class DevelopmentPlanResource extends Resource
{
    protected static ?string $model = DevelopmentPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Development plans';

    protected static ?int $navigationSort = 40;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'owner.person'])->withCount(['items', 'items as open_items_count' => fn ($q) => $q->where('status', 'open')]);
        $user = auth()->user();
        if ($user->can('development.view') || $user->can('development.manage')) {
            return $query;
        }
        $me = LearningActions::me();
        $team = $user->can('development.team') ? app(PerformanceRelationships::class)->reportIds($me) : collect();

        return $query->where(fn (Builder $q) => $q->where('employee_id', $me?->id ?? 0)->orWhereIn('employee_id', $team));
    }

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('create')->label('New plan')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('create', DevelopmentPlan::class))
                ->schema([
                    Select::make('employee_id')->label('Employee')->required()->searchable()->default(fn () => LearningActions::me()?->id)
                        ->options(fn () => (auth()->user()->can('development.manage') ? LearningActions::allPeopleOptions() : Employee::query()->with('person')->whereIn('id', app(PerformanceRelationships::class)->reportIds(LearningActions::me()))->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()) + array_filter([LearningActions::me()?->id => 'Me'])),
                    TextInput::make('title')->required()->maxLength(255),
                    Textarea::make('summary')->rows(2),
                    DatePicker::make('starts_on')->native(false)->default(now()),
                    DatePicker::make('target_date')->native(false)->afterOrEqual('starts_on'),
                    Textarea::make('private_notes')->label('Private notes (manager / L&D only)')->rows(2)->visible(fn () => auth()->user()->can('development.team') || auth()->user()->can('development.manage')),
                ])
                ->action(fn (array $data) => LearningActions::run(function () use ($data) {
                    $plan = app(DevelopmentPlans::class)->create(Employee::query()->findOrFail($data['employee_id']), $data['title'], $data, auth()->user());
                    app(DevelopmentPlans::class)->addOpenNeeds($plan, auth()->user());

                    return $plan;
                }, 'Plan created with the employee\'s open development needs')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable(),
                TextColumn::make('title')->wrap(),
                TextColumn::make('progress')->label('Items done')->state(fn (DevelopmentPlan $record) => ($record->items_count - $record->open_items_count).' / '.$record->items_count),
                TextColumn::make('target_date')->date()->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => DevelopmentPlan::STATUSES[$state] ?? $state)->color(fn (string $state) => match ($state) {
                    'active' => 'info', 'completed' => 'success', 'on_hold' => 'warning', 'cancelled', 'archived' => 'gray', default => 'gray'
                }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(DevelopmentPlan::STATUSES)])
            ->recordActions([
                Action::make('items')->label('Items')->icon(Heroicon::OutlinedListBullet)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (DevelopmentPlan $record) => [
                        ...$record->items()->with(['skill', 'course'])->get()->map(fn (DevelopmentPlanItem $i) => TextEntry::make("item{$i->id}")
                            ->label(DevelopmentPlanItem::TYPES[$i->item_type].' · '.$i->status)
                            ->state(self::describe($i))
                            ->helperText(self::suggestion($i)))->all(),
                        TextEntry::make('private')->label('Private notes')->state(fn () => app(DevelopmentPlans::class)->privateNotesFor($record, auth()->user()) ?? 'Not visible to you')
                            ->visible(fn () => LearningActions::me()?->id !== $record->employee_id),
                    ]),
                Action::make('addItem')->label('Add item')->icon(Heroicon::OutlinedPlus)->color('primary')
                    ->visible(fn (DevelopmentPlan $record) => ! $record->isClosed() && auth()->user()->can('update', $record))
                    ->schema([
                        Select::make('item_type')->label('Type')->options(DevelopmentPlanItem::TYPES)->default('learning')->required(),
                        TextInput::make('title')->required()->maxLength(255),
                        Select::make('skill_id')->label('Skill')->searchable()->options(fn () => Skill::query()->orderBy('name')->pluck('name', 'id')->all()),
                        TextInput::make('current_level')->numeric(),
                        TextInput::make('target_level')->numeric(),
                        Select::make('course_id')->label('Course')->searchable()->options(fn () => Course::query()->whereIn('status', Course::ENROLLABLE)->orderBy('title')->pluck('title', 'id')->all()),
                        DatePicker::make('due_on')->native(false),
                    ])
                    ->action(fn (DevelopmentPlan $record, array $data) => LearningActions::run(fn () => app(DevelopmentPlans::class)->addItem($record, $data['item_type'], $data['title'], $data, auth()->user()), 'Item added')),
                Action::make('enrolItem')->label('Enrol learning')->icon(Heroicon::OutlinedAcademicCap)->color('primary')
                    ->visible(fn (DevelopmentPlan $record) => $record->status === 'active' && auth()->user()->can('update', $record) && $record->items()->whereNotNull('course_id')->whereNull('learning_enrolment_id')->where('status', 'open')->exists())
                    ->schema(fn (DevelopmentPlan $record) => [Select::make('item_id')->label('Item')->required()->options($record->items()->whereNotNull('course_id')->whereNull('learning_enrolment_id')->where('status', 'open')->pluck('title', 'id')->all())])
                    ->action(fn (DevelopmentPlan $record, array $data) => LearningActions::run(fn () => app(DevelopmentPlans::class)->enrolItem($record->items()->findOrFail($data['item_id']), auth()->user()), 'Learning enrolled / requested')),
                Action::make('completeItem')->label('Complete item')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (DevelopmentPlan $record) => ! $record->isClosed() && auth()->user()->can('update', $record) && $record->items()->where('status', 'open')->exists())
                    ->schema(fn (DevelopmentPlan $record) => [Select::make('item_id')->label('Item')->required()->options($record->items()->where('status', 'open')->pluck('title', 'id')->all()), Textarea::make('notes')->maxLength(1000)])
                    ->action(fn (DevelopmentPlan $record, array $data) => LearningActions::run(fn () => app(DevelopmentPlans::class)->completeItem($record->items()->findOrFail($data['item_id']), $data['notes'] ?? null, auth()->user()), 'Item completed')),
                Action::make('transition')->label('Change status')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->visible(fn (DevelopmentPlan $record) => DevelopmentPlan::TRANSITIONS[$record->status] !== [] && auth()->user()->can('update', $record))
                    ->schema(fn (DevelopmentPlan $record) => [
                        Select::make('to')->label('New status')->required()->options(collect(DevelopmentPlan::TRANSITIONS[$record->status])->mapWithKeys(fn ($s) => [$s => DevelopmentPlan::STATUSES[$s]])->all()),
                        Textarea::make('reason')->maxLength(500),
                    ])
                    ->action(fn (DevelopmentPlan $record, array $data) => LearningActions::run(fn () => app(DevelopmentPlans::class)->transition($record, $data['to'], $data['reason'] ?? null, auth()->user()), 'Status changed')),
            ])
            ->emptyStateHeading('No development plans')->emptyStateDescription('Plans turn development needs from reviews, check-ins and skill assessments into learning and milestones.');
    }

    private static function describe(DevelopmentPlanItem $item): string
    {
        $parts = [$item->title];
        if ($item->skill) {
            $parts[] = $item->skill->name.' '.(float) $item->current_level.' → '.(float) $item->target_level;
        }
        if ($item->course) {
            $parts[] = 'course '.$item->course->code;
        }
        if ($item->due_on) {
            $parts[] = 'due '.$item->due_on->toDateString();
        }

        return implode(' — ', $parts);
    }

    /** Learning recommendations for an unfilled skill-gap item (a suggestion only, never an enrolment). */
    private static function suggestion(DevelopmentPlanItem $item): ?string
    {
        if (! $item->skill_id || $item->course_id) {
            return null;
        }
        $titles = app(DevelopmentPlans::class)->recommendations($item)->pluck('title')->implode(', ');

        return 'Suggested: '.($titles !== '' ? $titles : 'no matching courses');
    }

    public static function getPages(): array
    {
        return ['index' => ManageDevelopmentPlans::route('/')];
    }
}
