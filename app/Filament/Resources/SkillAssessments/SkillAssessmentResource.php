<?php

namespace App\Filament\Resources\SkillAssessments;

use App\Domain\Employment\Models\Employee;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Skills\Models\SkillAssessment;
use App\Domain\Skills\Services\SkillAssessments;
use App\Filament\Resources\SkillAssessments\Pages\ManageSkillAssessments;
use App\Filament\Support\LearningActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 8 skill assessments. Employees see their own (never the assessor's private notes),
 * managers the employees they manage, skills.view everyone. Finalized assessments are immutable;
 * corrections are new assessments.
 */
class SkillAssessmentResource extends Resource
{
    protected static ?string $model = SkillAssessment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Skill assessments';

    protected static ?int $navigationSort = 82;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'skill', 'scaleVersion', 'assessor']);
        $user = auth()->user();
        if ($user->can('skills.view') || $user->can('skills.manage')) {
            return $query;
        }
        $me = LearningActions::me();
        $team = $user->can('skills.assess') ? app(PerformanceRelationships::class)->reportIds($me) : collect();

        return $query->where(fn (Builder $q) => $q->where('employee_id', $me?->id ?? 0)->orWhereIn('employee_id', $team)->orWhere('assessor_user_id', $user->id));
    }

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('assess')->label('New assessment')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('skills.self') || auth()->user()->can('skills.assess') || auth()->user()->can('skills.manage'))
                ->schema([
                    Select::make('employee_id')->label('Employee')->required()->searchable()->default(fn () => LearningActions::me()?->id)
                        ->options(fn () => (auth()->user()->can('skills.manage') ? LearningActions::allPeopleOptions() : self::teamOptions()) + array_filter([LearningActions::me()?->id => 'Me'])),
                    Select::make('skill_id')->label('Skill')->required()->searchable()->options(fn () => Skill::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('assessment_type')->label('Type')->options(SkillAssessment::TYPES)->default('manager')->required(),
                    TextInput::make('level')->numeric()->required()->helperText('A value on the skill\'s scale.'),
                    TextInput::make('target_level')->numeric(),
                    DatePicker::make('valid_until')->native(false),
                    Textarea::make('evidence')->rows(2),
                    Textarea::make('comments')->label('Comments (visible to the employee)')->rows(2),
                    Textarea::make('private_notes')->label('Private notes (assessor only)')->rows(2),
                ])
                ->action(fn (array $data) => LearningActions::run(fn () => app(SkillAssessments::class)->draft(Employee::query()->findOrFail($data['employee_id']), (int) $data['skill_id'], $data['assessment_type'], [
                    'level' => (float) $data['level'], 'target_level' => filled($data['target_level'] ?? null) ? (float) $data['target_level'] : null,
                    'valid_until' => $data['valid_until'] ?? null, 'evidence' => $data['evidence'] ?? null, 'comments' => $data['comments'] ?? null, 'private_notes' => $data['private_notes'] ?? null,
                ], auth()->user()), 'Draft saved — finalize it when ready')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable(),
                TextColumn::make('skill.name')->label('Skill'),
                TextColumn::make('assessment_type')->label('Type')->badge()->formatStateUsing(fn (string $state) => SkillAssessment::TYPES[$state] ?? $state),
                TextColumn::make('level')->state(fn (SkillAssessment $record) => rtrim(rtrim((string) $record->level, '0'), '.').' · '.$record->scaleVersion?->labelFor((float) $record->level)),
                TextColumn::make('assessed_on')->date(),
                TextColumn::make('assessor.name')->label('Assessor')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'finalized' => 'success', 'superseded' => 'gray', default => 'warning'
                }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(['draft' => 'Draft', 'finalized' => 'Finalized', 'superseded' => 'Superseded'])])
            ->recordActions([
                Action::make('details')->label('Details')->icon(Heroicon::OutlinedEye)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (SkillAssessment $record) => [
                        TextEntry::make('comments')->state($record->comments ?? '—'),
                        TextEntry::make('evidence')->state($record->evidence ?? '—'),
                        TextEntry::make('private')->label('Private notes')->state(fn () => app(SkillAssessments::class)->privateNotesFor($record, auth()->user()) ?? 'Not visible to you')
                            ->visible(fn () => LearningActions::me()?->id !== $record->employee_id),
                        TextEntry::make('correction')->label('Corrects')->state($record->corrects_assessment_id ? '#'.$record->corrects_assessment_id.': '.$record->correction_reason : '—'),
                    ]),
                Action::make('finalize')->label('Finalize')->icon(Heroicon::OutlinedLockClosed)->color('success')
                    ->visible(fn (SkillAssessment $record) => $record->status === 'draft' && ((int) $record->assessor_user_id === (int) auth()->id() || auth()->user()->can('skills.manage')))
                    ->modalDescription('A finalized assessment cannot be changed; mistakes are fixed with a correction.')
                    ->schema([Toggle::make('record_need')->label('Record a development need for the gap to the target')])
                    ->action(fn (SkillAssessment $record, array $data) => LearningActions::run(fn () => app(SkillAssessments::class)->finalize($record, auth()->user(), (bool) ($data['record_need'] ?? false)), 'Finalized')),
                Action::make('correct')->label('Correct')->icon(Heroicon::OutlinedPencilSquare)->color('warning')
                    ->visible(fn (SkillAssessment $record) => $record->status === 'finalized' && ((int) $record->assessor_user_id === (int) auth()->id() || auth()->user()->can('skills.manage')))
                    ->schema([TextInput::make('level')->numeric()->required(), Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (SkillAssessment $record, array $data) => LearningActions::run(fn () => app(SkillAssessments::class)->correct($record, (float) $data['level'], $data['reason'], auth()->user()), 'Correction drafted — finalize it to apply')),
            ]);
    }

    /** @return array<int, string> */
    private static function teamOptions(): array
    {
        return Employee::query()->with('person')->whereIn('id', app(PerformanceRelationships::class)->reportIds(LearningActions::me()))->get()
            ->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
    }

    public static function getPages(): array
    {
        return ['index' => ManageSkillAssessments::route('/')];
    }
}
