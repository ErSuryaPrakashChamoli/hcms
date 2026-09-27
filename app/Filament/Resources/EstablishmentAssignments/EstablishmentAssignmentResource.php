<?php

namespace App\Filament\Resources\EstablishmentAssignments;

use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\EmployeeEstablishmentAssignment;
use App\Domain\Organisation\Models\Establishment;
use App\Filament\Resources\EstablishmentAssignments\Pages\ManageEstablishmentAssignments;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Phase 5 Part C/T: effective-dated employee ↔ establishment history. Append-only. */
class EstablishmentAssignmentResource extends Resource
{
    protected static ?string $model = EmployeeEstablishmentAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?int $navigationSort = 13;

    protected static ?string $navigationLabel = 'Establishment assignments';

    protected static ?string $modelLabel = 'establishment assignment';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'establishment']);
    }

    public static function createFields(): array
    {
        return [
            Select::make('employee_id')->label('Employee')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => Employee::query()->where('employee_code', 'like', "%{$search}%")->orWhereHas('person', fn ($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))->limit(25)->get()->mapWithKeys(fn ($e) => [$e->id => $e->employee_code.' — '.$e->person?->display_name])->all()),
            Select::make('establishment_id')->label('Establishment')->required()->options(fn () => Establishment::query()->orderBy('name')->pluck('name', 'id')->all()),
            DatePicker::make('effective_from')->required(),
            Select::make('source')->options(['manual' => 'Manual', 'transfer' => 'Transfer'])->default('transfer')->required(),
            Textarea::make('reason')->required()->rows(2),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.employee_code')->label('Employee')->searchable(),
                TextColumn::make('employee.person.display_name')->label('Name'),
                TextColumn::make('establishment.name')->label('Establishment'),
                TextColumn::make('effective_from')->date()->sortable(),
                TextColumn::make('effective_to')->date()->placeholder('Open'),
                TextColumn::make('source')->badge(),
                TextColumn::make('assignment_reason')->label('Reason')->limit(40),
            ])
            ->filters([SelectFilter::make('establishment_id')->label('Establishment')->options(fn () => Establishment::query()->pluck('name', 'id')->all())])
            ->defaultSort('effective_from', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageEstablishmentAssignments::route('/')];
    }
}
