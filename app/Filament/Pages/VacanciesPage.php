<?php

namespace App\Filament\Pages;

use App\Domain\Workforce\Models\PositionVersion;
use App\Domain\Workforce\Services\WorkforceSnapshot;
use App\Filament\Resources\Positions\PositionResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/** Phase 10 vacancies: open positions with unfilled seats today — capacity facts, never requisitions. Paged in the database. */
class VacanciesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Workforce';

    protected static ?string $navigationLabel = 'Vacancies';

    protected static ?string $title = 'Vacancies';

    protected static ?string $slug = 'workforce-vacancies';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.workforce-vacancies';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('workforce.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(WorkforceSnapshot::class)->vacancies(now())->with(['position:id,code', 'designation', 'location']))
            ->columns([
                TextColumn::make('position.code')->label('Position'),
                TextColumn::make('title')->wrap(),
                TextColumn::make('designation.name')->label('Designation')->placeholder('—'),
                TextColumn::make('location.name')->label('Location')->placeholder('—'),
                TextColumn::make('headcount')->label('Seats'),
                TextColumn::make('occupied_seats')->label('Occupied'),
                TextColumn::make('vacant')->label('Vacant')->state(fn (PositionVersion $record) => max(0, $record->headcount - (int) $record->occupied_seats)),
                TextColumn::make('effective_from')->label('Open since')->date(),
            ])
            ->recordUrl(fn (PositionVersion $record) => PositionResource::getUrl('view', ['record' => $record->position_id]))
            ->defaultSort('position_versions.effective_from')
            ->emptyStateHeading('No vacancies')->emptyStateDescription('Every open position is filled.');
    }
}
