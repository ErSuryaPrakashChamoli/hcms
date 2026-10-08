<?php

namespace App\Filament\Resources\RoleRequirements;

use App\Domain\Career\Models\RoleRequirementVersion;
use App\Domain\Career\Services\CareerArchitecture;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Organisation\Models\Designation;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\Competency;
use App\Filament\Resources\RoleRequirements\Pages\ManageRoleRequirements;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 9 role requirements: effective-dated, immutable versions per role (optionally per
 * organisation unit) with skill levels on the pinned Phase 8 scale version, competencies,
 * experience, certifications and learning. A new version closes the previous window.
 */
class RoleRequirementResource extends Resource
{
    protected static ?string $model = RoleRequirementVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Role requirements';

    protected static ?int $navigationSort = 15;

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('publish')->label('Publish requirements')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('create', RoleRequirementVersion::class))
                ->schema([
                    Select::make('designation_id')->label('Role')->options(fn () => TalentActions::designationOptions())->searchable()->required(),
                    Select::make('organisation_node_id')->label('Organisation unit (optional)')->options(fn () => TalentActions::nodeOptions())->searchable()->placeholder('Role-wide'),
                    DatePicker::make('effective_from')->native(false)->default(now())->required(),
                    Repeater::make('skills')->schema([
                        Select::make('skill_id')->label('Skill')->options(fn () => Skill::query()->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                        TextInput::make('level')->numeric()->required()->helperText('A level on the skill\'s current scale version'),
                        Toggle::make('required')->default(true),
                    ])->columns(3)->defaultItems(0),
                    Select::make('competency_ids')->label('Competencies')->multiple()->options(fn () => Competency::query()->orderBy('name')->pluck('name', 'id')->all()),
                    TextInput::make('min_experience_years')->label('Minimum experience (years)')->numeric()->minValue(0),
                    Select::make('certification_course_ids')->label('Certifications (courses)')->multiple()->options(fn () => Course::query()->orderBy('title')->pluck('title', 'id')->all()),
                    Select::make('course_ids')->label('Learning: courses')->multiple()->options(fn () => Course::query()->orderBy('title')->pluck('title', 'id')->all()),
                    Select::make('path_ids')->label('Learning: paths')->multiple()->options(fn () => LearningPath::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Textarea::make('notes')->rows(2),
                ])
                ->action(fn (array $data) => TalentActions::run(fn () => app(CareerArchitecture::class)->publishRequirements(
                    Designation::query()->findOrFail($data['designation_id']), $data['organisation_node_id'] ?? null, [
                        'skills' => array_values($data['skills'] ?? []),
                        'competencies' => collect($data['competency_ids'] ?? [])->map(fn ($id) => ['competency_id' => (int) $id])->all(),
                        'min_experience_years' => $data['min_experience_years'] ?? null,
                        'certifications' => collect($data['certification_course_ids'] ?? [])->map(fn ($id) => ['course_id' => (int) $id])->all(),
                        'learning' => [...collect($data['course_ids'] ?? [])->map(fn ($id) => ['type' => 'course', 'id' => (int) $id])->all(), ...collect($data['path_ids'] ?? [])->map(fn ($id) => ['type' => 'path', 'id' => (int) $id])->all()],
                        'notes' => $data['notes'] ?? null,
                    ], $data['effective_from'], auth()->user()), fn ($v) => "Requirements version {$v->version} published")),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['designation', 'organisationNode']))
            ->columns([
                TextColumn::make('designation.name')->label('Role')->searchable(),
                TextColumn::make('organisationNode.id')->label('Unit')->formatStateUsing(fn ($state, RoleRequirementVersion $record) => $record->organisationNode?->auditLabel())->placeholder('Role-wide'),
                TextColumn::make('version')->prefix('v'),
                TextColumn::make('skills')->label('Skills')->state(fn (RoleRequirementVersion $record) => count($record->skills ?? [])),
                TextColumn::make('min_experience_years')->label('Experience')->suffix(' yrs')->placeholder('—'),
                TextColumn::make('effective_from')->date(),
                TextColumn::make('effective_to')->date()->placeholder('open'),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No role requirements')->emptyStateDescription('Publish what a role needs; gaps are then shown as facts on career pages.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoleRequirements::route('/')];
    }
}
