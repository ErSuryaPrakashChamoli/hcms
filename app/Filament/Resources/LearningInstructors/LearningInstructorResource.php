<?php

namespace App\Filament\Resources\LearningInstructors;

use App\Domain\Learning\Models\LearningInstructor;
use App\Domain\Learning\Models\LearningProvider;
use App\Filament\Resources\LearningInstructors\Pages\ManageLearningInstructors;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\LearningActions;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Phase 8: instructors. An internal instructor is an existing employee (no second person record). */
class LearningInstructorResource extends Resource
{
    protected static ?string $model = LearningInstructor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Instructors';

    protected static ?int $navigationSort = 61;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('employee_id')->label('Internal instructor (employee)')->searchable()->placeholder('External instructor')->live()
                ->options(fn () => LearningActions::allPeopleOptions()),
            TextInput::make('name')->maxLength(255)->required(fn (Get $get) => blank($get('employee_id')))->helperText('Taken from the employee record for internal instructors.'),
            Select::make('learning_provider_id')->label('Provider')->options(fn () => LearningProvider::query()->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('email')->email()->maxLength(255)->visible(fn (Get $get) => blank($get('employee_id'))),
            TextInput::make('specialisation')->maxLength(255),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
            Textarea::make('bio')->rows(3)->columnSpanFull(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    /** @return array<int, CreateAction> */
    public static function headerActions(): array
    {
        return [CreateAction::make()->visible(fn () => auth()->user()->can('learning.manage'))];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee', 'provider']))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('kind')->label('Kind')->badge()->state(fn (LearningInstructor $record) => $record->isInternal() ? 'Internal · '.$record->employee?->employee_code : 'External')
                    ->color(fn (LearningInstructor $record) => $record->isInternal() ? 'info' : 'gray'),
                TextColumn::make('provider.name')->label('Provider')->placeholder('—'),
                TextColumn::make('specialisation')->placeholder('—')->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordActions([EditAction::make()->visible(fn () => auth()->user()->can('learning.manage'))]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLearningInstructors::route('/')];
    }
}
