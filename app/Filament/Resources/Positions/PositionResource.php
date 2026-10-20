<?php

namespace App\Filament\Resources\Positions;

use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Services\PositionOccupancy;
use App\Domain\Workforce\Services\WorkforceAccess;
use App\Domain\Workforce\Services\WorkforceIntegrations;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Positions\Pages\ListPositions;
use App\Filament\Resources\Positions\Pages\ViewPosition;
use App\Filament\Resources\Positions\RelationManagers\ChangeRequestsRelationManager;
use App\Filament\Resources\Positions\RelationManagers\OccupantsRelationManager;
use App\Filament\Resources\Positions\RelationManagers\VersionsRelationManager;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 10 positions: organisational capacity (seats with FTE), never employees. Occupancy is derived
 * from employee assignments; every change after draft is an effective-dated version. No recruitment
 * action exists here.
 */
class PositionResource extends Resource
{
    protected static ?string $model = Position::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Workforce';

    protected static ?string $navigationLabel = 'Positions';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getEloquentQuery(): Builder
    {
        // Occupied seats today in one correlated subquery (same rules as PositionOccupancy; no per-row queries).
        $occupied = app(PositionOccupancy::class)->occupantRows(null, now())->whereColumn('employee_positions.position_id', 'positions.id')->selectRaw('count(distinct employee_positions.employee_id)');
        $query = parent::getEloquentQuery()->with(['company', 'currentVersion'])->select('positions.*')->selectSub($occupied->toBase(), 'occupied_seats');
        $user = auth()->user();
        if ($user->can('workforce.view') || $user->can('workforce.manage')) {
            return $query;
        }

        return $query->whereIn('positions.id', app(WorkforceAccess::class)->teamPositionIds($user));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('company.name')->label('Company')->toggleable(),
                TextColumn::make('currentVersion.headcount')->label('Seats'),
                TextColumn::make('currentVersion.fte_capacity')->label('FTE'),
                TextColumn::make('occupied_seats')->label('Occupied'),
                TextColumn::make('first_effective_from')->label('From')->date(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.workforce.position_statuses.{$state}", $state))
                    ->color(fn (string $state) => match ($state) {
                        'open' => 'success', 'frozen', 'on_hold' => 'warning', 'abolished', 'closed' => 'gray', 'proposed', 'approved', 'planned' => 'info', default => 'gray'
                    }),
            ])
            ->defaultSort('code')
            ->filters([SelectFilter::make('status')->options(config('peopleos.workforce.position_statuses'))])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No positions')->emptyStateDescription('A position is capacity (seats and FTE) that employees occupy through their employment record.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Overview')->columns(4)->schema([
                TextEntry::make('code')->copyable(),
                TextEntry::make('title')->weight('bold'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.workforce.position_statuses.{$state}", $state)),
                TextEntry::make('occupancy_state')->label('Occupancy today')->state(fn (Position $record) => self::occupancyLabel($record)),
                TextEntry::make('currentVersion.headcount')->label('Seats'),
                TextEntry::make('currentVersion.fte')->label('FTE per seat'),
                TextEntry::make('currentVersion.fte_capacity')->label('FTE capacity'),
                TextEntry::make('currentVersion.occupancy_mode')->label('Occupancy mode')->formatStateUsing(fn (?string $state) => config("peopleos.workforce.occupancy_modes.{$state}", $state)),
            ]),
            Section::make('Organisation')->columns(4)->schema([
                TextEntry::make('company.name')->label('Company'),
                TextEntry::make('currentVersion.organisationNode.id')->label('Organisation unit')->formatStateUsing(fn ($state, Position $record) => $record->currentVersion?->organisationNode?->auditLabel())->placeholder('—'),
                TextEntry::make('currentVersion.location.name')->label('Location')->placeholder('—'),
                TextEntry::make('currentVersion.establishment.name')->label('Establishment')->placeholder('—'),
                TextEntry::make('currentVersion.designation.name')->label('Designation')->placeholder('—'),
                TextEntry::make('currentVersion.jobFamily.name')->label('Job family')->placeholder('—'),
                TextEntry::make('currentVersion.grade.name')->label('Grade')->placeholder('—'),
                TextEntry::make('currentVersion.costCentre.name')->label('Cost centre')->placeholder('—'),
                TextEntry::make('currentVersion.parent.code')->label('Parent position')->placeholder('—'),
                TextEntry::make('currentVersion.employmentType.name')->label('Employment type')->placeholder('—'),
                TextEntry::make('currentVersion.worker_type')->label('Worker type')->formatStateUsing(fn (?string $state) => config("peopleos.workforce.worker_types.{$state}", $state)),
                TextEntry::make('first_effective_from')->label('First effective')->date(),
            ]),
            Grid::make(3)->schema([
                Section::make('Requirements')->description('Phase 9 role requirements for this role (read only).')->schema([
                    TextEntry::make('requirements')->hiddenLabel()->state(fn (Position $record) => self::requirementsText($record)),
                ]),
                Section::make('Succession')->description('Phase 9 succession for this role (read only).')->schema([
                    TextEntry::make('succession')->hiddenLabel()->state(fn (Position $record) => self::successionText($record)),
                ])->visible(fn () => auth()->user()->can('succession.view')),
                Section::make('Career path')->description('Career paths through this role. Nobody is moved automatically.')->schema([
                    TextEntry::make('career')->hiddenLabel()->state(fn (Position $record) => collect(app(WorkforceIntegrations::class)->careerPaths($record))->map(fn ($p) => $p['path'].($p['next'] ? ' → '.implode(', ', $p['next']) : ''))->implode(' · ') ?: 'No career path includes this role.'),
                ]),
            ]),
        ]);
    }

    public static function occupancyLabel(Position $record): string
    {
        $o = app(PositionOccupancy::class)->occupancy($record);

        return $o['in_force'] ? sprintf('%s — %d of %d seat(s), %s of %s FTE', str_replace('_', ' ', $o['state']), $o['occupied_seats'], $o['seats'], $o['occupied_fte'], $o['fte_capacity']) : 'Not in force today';
    }

    private static function requirementsText(Position $record): string
    {
        $r = app(WorkforceIntegrations::class)->requirements($record);

        return $r === null ? 'No published requirements for this role.' : sprintf('v%d (%s): %d skill(s), %d competency(ies), %d certification(s), %d learning item(s)%s', $r['version'], $r['scope'], $r['skills'], $r['competencies'], $r['certifications'], $r['learning'], $r['min_experience_years'] !== null ? ", {$r['min_experience_years']}+ years" : '');
    }

    private static function successionText(Position $record): string
    {
        $s = app(WorkforceIntegrations::class)->succession($record);

        return $s === null ? 'This role is not designated critical.' : sprintf('%s (%s) — plan %s, %d successor(s), %d ready now', $s['critical_position'], $s['criticality'] ?? '—', $s['plan_status'] ?? 'none', $s['successors'], $s['ready_now']);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class, OccupantsRelationManager::class, ChangeRequestsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListPositions::route('/'), 'view' => ViewPosition::route('/{record}')];
    }
}
