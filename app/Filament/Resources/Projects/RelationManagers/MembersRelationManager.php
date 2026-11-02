<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Domain\Employment\Models\Employee;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMember;
use App\Domain\Projects\Services\Projects;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

/** Data import DI-1: the people on a project, added and taken off through the Projects service. */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Members';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('employee_id')->label('Employee')->searchable()->required()->disabledOn('edit')
                ->options(fn (?ProjectMember $record) => ProjectResource::employeeOptions($record?->employee_id)),
            TextInput::make('role')->maxLength(64)->placeholder('Developer, analyst …'),
            TextInput::make('allocation_percent')->label('Allocation (%)')->numeric()->minValue(0.01)->maxValue(100),
            DatePicker::make('from_date')->label('From')->native(false),
            DatePicker::make('to_date')->label('To')->native(false)->afterOrEqual('from_date'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('employee.person'))
            ->columns([
                TextColumn::make('employee.employee_code')->label('Code'),
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('role')->placeholder('—'),
                TextColumn::make('allocation_percent')->label('Allocation')->suffix('%')->placeholder('—'),
                TextColumn::make('from_date')->label('From')->date()->placeholder('—'),
                TextColumn::make('to_date')->label('To')->date()->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->label('Add member')->using(function (array $data) {
                    /** @var Project $project */
                    $project = $this->getOwnerRecord();
                    try {
                        return app(Projects::class)->addMember($project, Employee::query()->findOrFail($data['employee_id']), $data, 'Added on the project screen');
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not added')->body($e->getMessage())->send();
                        throw new Halt;
                    }
                }),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('end')->label('Take off')->icon('heroicon-o-arrow-right-start-on-rectangle')->color('gray')
                    ->visible(fn (ProjectMember $record) => $record->to_date === null && auth()->user()?->can('update', $record))
                    ->schema([DatePicker::make('to_date')->label('Last day on the project')->native(false)->default(now())->required()])
                    ->action(fn (ProjectMember $record, array $data) => app(Projects::class)->endMember($record, $data['to_date'], 'Taken off on the project screen')),
            ]);
    }
}
