<?php

namespace App\Filament\Resources\DevelopmentNeeds;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\DevelopmentNeed;
use App\Domain\Performance\Services\DevelopmentNeeds;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\DevelopmentNeeds\Pages\ManageDevelopmentNeeds;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Phase 7: development needs found in performance conversations (a boundary a future Learning module reads). */
class DevelopmentNeedResource extends Resource
{
    protected static ?string $model = DevelopmentNeed::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Development needs';

    protected static ?int $navigationSort = 40;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'competency']);
        $user = auth()->user();
        if ($user->can('performance.view')) {
            return $query;
        }
        $me = PerformanceActions::me();

        return $query->where(fn (Builder $q) => $q->where('employee_id', $me?->id ?? 0)->orWhereIn('employee_id', app(PerformanceRelationships::class)->teamOf($user)));
    }

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('record')->label('Record need')->icon(Heroicon::OutlinedPlus)->color('primary')
                ->visible(fn () => PerformanceActions::me() !== null || auth()->user()->can('performance.manage'))
                ->schema([
                    Select::make('employee_id')->label('Employee')->required()->searchable()->options(function () {
                        $user = auth()->user();
                        $q = Employee::query()->with('person')->employed();
                        if (! $user->can('performance.manage')) {
                            $q->whereIn('id', collect([PerformanceActions::me()?->id])->merge(app(PerformanceRelationships::class)->teamOf($user))->filter());
                        }

                        return $q->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
                    })->default(fn () => PerformanceActions::me()?->id),
                    TextInput::make('title')->required()->maxLength(255),
                    Select::make('competency_id')->label('Competency')->placeholder('—')->options(fn () => Competency::query()->where('status', 'active')->pluck('name', 'id')->all()),
                    Select::make('priority')->options(config('peopleos.performance.development_need_priorities'))->default('medium')->required(),
                    Select::make('source_type')->label('Identified in')->options(array_combine(DevelopmentNeed::SOURCES, array_map(fn ($s) => ucfirst(str_replace('_', ' ', $s)), DevelopmentNeed::SOURCES)))->default('manual')->required(),
                    Textarea::make('description')->rows(2),
                ])
                ->action(fn (array $data) => PerformanceActions::run(fn () => app(DevelopmentNeeds::class)->record(Employee::query()->findOrFail($data['employee_id']), $data['title'], $data['source_type'], null, $data['competency_id'] ?? null, $data['priority'], $data['description'] ?? null, auth()->user()), 'Development need recorded')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable(),
                TextColumn::make('title')->wrap(),
                TextColumn::make('competency.name')->label('Competency')->placeholder('—'),
                TextColumn::make('priority')->badge()->color(fn (string $state) => match ($state) {
                    'high' => 'danger', 'medium' => 'warning', default => 'gray'
                }),
                TextColumn::make('source_type')->label('Source')->badge()->color('gray'),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->since(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.performance.development_need_statuses'))])
            ->recordActions([
                Action::make('status')->label('Update status')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->visible(fn (DevelopmentNeed $record) => auth()->user()->can('update', $record))
                    ->schema([Select::make('status')->options(config('peopleos.performance.development_need_statuses'))->required()])
                    ->action(fn (DevelopmentNeed $record, array $data) => PerformanceActions::run(fn () => app(DevelopmentNeeds::class)->setStatus($record, $data['status']), 'Updated')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDevelopmentNeeds::route('/')];
    }
}
