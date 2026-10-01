<?php

namespace App\Filament\Resources\LearningPrograms;

use App\Domain\Employment\Models\Employee;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Learning\Models\LearningProgram;
use App\Domain\Learning\Models\LearningProgramVersion;
use App\Domain\Learning\Services\Learning;
use App\Domain\Learning\Services\Programs;
use App\Filament\Resources\LearningPrograms\Pages\ManageLearningPrograms;
use App\Filament\Support\LearningActions;
use App\Filament\Support\RuleConditionsSchema;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Phase 8: learning programs — versioned bundles with eligibility, required / optional items and a completion rule. */
class LearningProgramResource extends Resource
{
    protected static ?string $model = LearningProgram::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Programs';

    protected static ?int $navigationSort = 13;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            Textarea::make('description')->rows(2)->columnSpanFull(),
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
            ->modifyQueryUsing(fn ($query) => $query->with('currentVersion')->withCount('versions'))
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (LearningProgram $record) => $record->code),
                TextColumn::make('currentVersion.version')->label('Version')->prefix('v')->placeholder('Unpublished'),
                TextColumn::make('currentVersion.starts_on')->label('Starts')->date()->placeholder('—'),
                TextColumn::make('currentVersion.ends_on')->label('Ends')->date()->placeholder('—'),
                TextColumn::make('participants')->label('Participants')->state(fn (LearningProgram $record) => $record->currentVersion?->participants()->count() ?? 0),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'published' ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make()->visible(fn () => auth()->user()->can('learning.manage')),
                Action::make('publish')->label('Publish version')->icon(Heroicon::OutlinedRocketLaunch)->color('success')
                    ->visible(fn () => auth()->user()->can('learning.manage'))
                    ->modalDescription('A published version is immutable; participants stay on the version they joined.')
                    ->schema([
                        DatePicker::make('starts_on')->native(false),
                        DatePicker::make('ends_on')->native(false)->afterOrEqual('starts_on'),
                        Repeater::make('items')->required()->minItems(1)->columns(3)->schema([
                            Select::make('type')->options(['course' => 'Course', 'path' => 'Learning path'])->default('course')->required()->live(),
                            Select::make('id')->label('Item')->required()->searchable()->options(fn (Get $get) => $get('type') === 'path'
                                ? LearningPath::query()->orderBy('name')->pluck('name', 'id')->all()
                                : Course::query()->whereIn('status', Course::ENROLLABLE)->orderBy('title')->pluck('title', 'id')->all()),
                            Toggle::make('required')->default(true)->inline(false),
                        ]),
                        TextInput::make('min_optional')->label('Optional items to complete')->numeric()->minValue(0)->default(0),
                        Toggle::make('issues_certificate')->label('Issue a program certificate'),
                        TextInput::make('validity_months')->numeric()->minValue(1)->suffix('months')->placeholder('No expiry'),
                        RuleConditionsSchema::repeater()->label('Eligibility (empty = everyone)'),
                    ])
                    ->action(fn (LearningProgram $record, array $data) => LearningActions::run(fn () => app(Programs::class)->publish($record, [
                        'starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null, 'eligibility' => $data['conditions'] ?? null,
                        'items' => array_map(fn ($i) => ['type' => $i['type'], 'id' => (int) $i['id'], 'required' => (bool) ($i['required'] ?? false)], $data['items'] ?? []),
                        'min_optional' => (int) ($data['min_optional'] ?? 0), 'issues_certificate' => (bool) ($data['issues_certificate'] ?? false),
                        'validity_months' => filled($data['validity_months'] ?? null) ? (int) $data['validity_months'] : null,
                    ], auth()->user()), fn (LearningProgramVersion $v) => "Version {$v->version} published")),
                Action::make('enrol')->label('Enrol employee')->icon(Heroicon::OutlinedUserPlus)->color('primary')
                    ->visible(fn (LearningProgram $record) => $record->current_version_id !== null && (auth()->user()->can('learning.manage') || auth()->user()->can('learning.assign')))
                    ->schema([Select::make('employee_id')->label('Employee')->required()->searchable()->options(fn () => LearningActions::peopleOptions())])
                    ->action(fn (LearningProgram $record, array $data) => LearningActions::run(function () use ($record, $data) {
                        $employee = Employee::query()->findOrFail($data['employee_id']);
                        if (! app(Learning::class)->mayAssign(auth()->user(), $employee)) {
                            throw new \RuntimeException('You can enrol only employees you manage.');
                        }

                        return app(Programs::class)->enrol($record, $employee, auth()->user());
                    }, 'Enrolled in the program')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLearningPrograms::route('/')];
    }
}
