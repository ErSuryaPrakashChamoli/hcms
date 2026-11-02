<?php

namespace App\Filament\Resources\Projects;

use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Customer;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\Projects;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\RelationManagers\MembersRelationManager;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Data import DI-1 (Project Management → Projects): projects with their customer, company, dates, manager, billable
 * flag and status; the Members tab lists the people on them (added through the Projects service, like the upload).
 */
class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Workforce';

    protected static ?int $navigationSort = 81;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'project';

    protected static ?string $pluralModelLabel = 'projects';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Project')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(160),
                TextInput::make('code')->required()->maxLength(32)->alphaDash()->helperText('Short unique code, used by uploads.')
                    ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
                Select::make('customer_id')->label('Customer')->placeholder('None (internal)')->searchable()
                    ->options(fn () => Customer::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('company_id')->label('Company')->searchable()
                    ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('status')->options(ProjectStatus::class)->default(ProjectStatus::Planned)->required(),
                Toggle::make('is_billable')->label('Billable')->inline(false),
            ]),
            Section::make('Dates and manager')->columns(2)->schema([
                DatePicker::make('start_date')->native(false),
                DatePicker::make('end_date')->native(false)->afterOrEqual('start_date'),
                Select::make('manager_employee_id')->label('Manager')->searchable()
                    ->options(fn (?Project $record) => self::employeeOptions($record?->manager_employee_id)),
                Textarea::make('description')->rows(3)->maxLength(5000)->columnSpanFull(),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    /**
     * Employees in the person's reach as "code · name": those employed or about to join (not those who left or never
     * joined), plus the one given (e.g. a saved manager who has left).
     *
     * @return array<int, string>
     */
    public static function employeeOptions(?int $keep = null): array
    {
        return Employee::query()->with('person')->where(fn ($q) => $q->whereNotIn('lifecycle_state', Projects::goneStates())->orWhere('id', $keep ?? 0))->orderBy('employee_code')->limit(2000)->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'manager.person'])->withCount('members'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('customer.name')->label('Customer')->placeholder('Internal'),
                TextColumn::make('manager.person.full_name')->label('Manager')->placeholder('—')->toggleable(),
                TextColumn::make('start_date')->date()->sortable()->placeholder('—'),
                TextColumn::make('end_date')->date()->sortable()->placeholder('—')->toggleable(),
                IconColumn::make('is_billable')->label('Billable')->boolean(),
                TextColumn::make('members_count')->label('Members'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ProjectStatus::class),
                SelectFilter::make('customer_id')->label('Customer')->relationship('customer', 'name'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [MembersRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
