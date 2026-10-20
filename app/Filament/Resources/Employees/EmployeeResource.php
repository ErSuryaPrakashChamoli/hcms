<?php

namespace App\Filament\Resources\Employees;

use App\Domain\Employment\Models\Employee;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\AssetsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\AttendanceRelationManager;
use App\Filament\Resources\Employees\RelationManagers\BankAccountsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\BgvRelationManager;
use App\Filament\Resources\Employees\RelationManagers\CareerRelationManager;
use App\Filament\Resources\Employees\RelationManagers\CertificationsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\CompensationChangesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\CompensationRelationManager;
use App\Filament\Resources\Employees\RelationManagers\DevelopmentRelationManager;
use App\Filament\Resources\Employees\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\EmergencyContactsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\ExperiencesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\FamilyMembersRelationManager;
use App\Filament\Resources\Employees\RelationManagers\LearningRelationManager;
use App\Filament\Resources\Employees\RelationManagers\LeaveRelationManager;
use App\Filament\Resources\Employees\RelationManagers\OnboardingRelationManager;
use App\Filament\Resources\Employees\RelationManagers\PerformanceRelationManager;
use App\Filament\Resources\Employees\RelationManagers\PositionsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\QualificationsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\ReportingRelationManager;
use App\Filament\Resources\Employees\RelationManagers\RequestsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\SkillsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\SuccessionRelationManager;
use App\Filament\Resources\Employees\RelationManagers\TalentRelationManager;
use App\Filament\Resources\Employees\RelationManagers\TimelineRelationManager;
use App\Filament\Resources\Employees\RelationManagers\WorkflowsRelationManager;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Resources\Employees\Schemas\EmployeeInfolist;
use App\Filament\Resources\Employees\Tables\EmployeesTable;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Employee master and Employee 360 (blueprint §16–§18). The view page is the 360: overview
 * infolist on top, one relation-manager tab per facet beneath.
 */
class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'employee_code';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['person', 'currentPosition.designation', 'currentPosition.department', 'currentPosition.company']);
    }

    public static function form(Schema $schema): Schema
    {
        return EmployeeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmployeeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeesTable::configure($table);
    }

    /**
     * Experience Transformation §14: the thirty record tabs are grouped into the 360 sections, so the
     * profile reads as a person rather than a list of tables. Each manager keeps its own
     * canViewForRecord(); a group with nothing visible disappears.
     */
    public static function getRelations(): array
    {
        return [
            TimelineRelationManager::class,
            RelationGroup::make('Employment', [PositionsRelationManager::class, ReportingRelationManager::class, OnboardingRelationManager::class,
                RelationManagers\ExitRelationManager::class, WorkflowsRelationManager::class]),
            RelationGroup::make('Time', [AttendanceRelationManager::class, LeaveRelationManager::class]),
            RelationGroup::make('Growth', [PerformanceRelationManager::class, RelationManagers\GoalsRelationManager::class, LearningRelationManager::class,
                SkillsRelationManager::class, CareerRelationManager::class, TalentRelationManager::class, SuccessionRelationManager::class,
                CertificationsRelationManager::class, DevelopmentRelationManager::class]),
            RelationGroup::make('Rewards', [CompensationRelationManager::class, CompensationChangesRelationManager::class, RelationManagers\PayslipsRelationManager::class]),
            RelationGroup::make('Operations', [DocumentsRelationManager::class, RelationManagers\LettersRelationManager::class, RequestsRelationManager::class,
                AssetsRelationManager::class, BgvRelationManager::class, RelationManagers\CommunicationsRelationManager::class]),
            RelationGroup::make(fn (?Model $ownerRecord) => $ownerRecord !== null && BankAccountsRelationManager::canViewForRecord($ownerRecord, ViewEmployee::class) ? 'Bank & personal' : 'Personal', [AddressesRelationManager::class, FamilyMembersRelationManager::class, EmergencyContactsRelationManager::class,
                QualificationsRelationManager::class, ExperiencesRelationManager::class, BankAccountsRelationManager::class]),
            AuditHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'view' => ViewEmployee::route('/{record}'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['employee_code', 'work_email', 'person.first_name', 'person.last_name', 'person.preferred_name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->person->display_name.' · '.$record->employee_code;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $position = $record->currentPosition;

        return array_filter([
            'Designation' => $position?->designation?->name,
            'Department' => $position?->department?->name,
            'Company' => $position?->company?->name,
        ]);
    }
}
