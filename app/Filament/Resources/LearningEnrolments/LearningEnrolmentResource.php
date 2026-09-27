<?php

namespace App\Filament\Resources\LearningEnrolments;

use App\Domain\Learning\Models\LearningEnrolment;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\LearningEnrolments\Pages\ListLearningEnrolments;
use App\Filament\Resources\LearningEnrolments\Pages\ViewLearningEnrolment;
use App\Filament\Support\LearningActions;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Enrolments (§37): staff see everyone; learners see "My learning"; managers see their reports. */
class LearningEnrolmentResource extends Resource
{
    protected static ?string $model = LearningEnrolment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->can('learning.view') ? 'Enrolments' : 'My learning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $me = LearningActions::me();
        if ($me === null) {
            return null;
        }
        $count = LearningEnrolment::query()->where('employee_id', $me->id)->whereIn('status', LearningEnrolment::OPEN)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'course', 'path']);
        $user = auth()->user();
        if ($user->can('learning.view') || $user->can('learning.assign')) {
            return $query;
        }
        $me = LearningActions::me();
        $reports = $me ? $me->directReports()->currentlyEffective()->pluck('employee_id') : collect();

        return $query->where(fn (Builder $q) => $q->where('employee_id', $me?->id ?? 0)->orWhereIn('employee_id', $reports));
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'completed' => 'success', 'in_progress' => 'info', 'overdue', 'failed' => 'danger', 'expired' => 'warning', 'withdrawn' => 'gray', default => 'gray'
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (LearningEnrolment $record) => $record->course->title)->columns(4)->schema([
                TextEntry::make('employee.person.full_name')->label('Learner'),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.learning.enrolment_statuses.{$state}", $state)),
                TextEntry::make('progress')->suffix('%'),
                TextEntry::make('due_on')->date()->placeholder('—'),
                TextEntry::make('score')->placeholder('—')->suffix('%'),
                TextEntry::make('attempts')->formatStateUsing(fn ($state, LearningEnrolment $record) => $state.' / '.$record->course->attempts_allowed),
                TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                TextEntry::make('expires_on')->date()->placeholder('—'),
                TextEntry::make('course.description')->label('About')->placeholder('—')->columnSpanFull(),
                TextEntry::make('course.content_url')->label('Content')->url(fn ($state) => $state)->openUrlInNewTab()->placeholder('—')->columnSpan(2),
            ]),
            Section::make('Modules')->schema([
                TextEntry::make('modules')->hiddenLabel()->state(fn (LearningEnrolment $record) => $record->course->modules()->get()->map(fn ($m) => ($record->hasCompletedModule($m->id) ? '✓ ' : '○ ').$m->title.($m->duration_minutes ? " ({$m->duration_minutes} min)" : '').($m->url ? ' — '.$m->url : ''))->all())->listWithLineBreaks()->placeholder('No modules; see the content link above.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course.title')->label('Course')->searchable()->sortable(),
                TextColumn::make('employee.person.full_name')->label('Learner')->searchable(['first_name', 'last_name']),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.learning.enrolment_statuses.{$state}", $state)),
                TextColumn::make('progress')->suffix('%')->sortable(),
                TextColumn::make('due_on')->date()->sortable()->placeholder('—')->color(fn (LearningEnrolment $record) => $record->isOpen() && $record->due_on?->isPast() ? 'danger' : null),
                TextColumn::make('is_mandatory')->label('Mandatory')->formatStateUsing(fn ($state) => $state ? 'Yes' : '—'),
                TextColumn::make('score')->suffix('%')->placeholder('—'),
                TextColumn::make('completed_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(config('peopleos.learning.enrolment_statuses')),
                SelectFilter::make('course_id')->label('Course')->relationship('course', 'title'),
            ])
            ->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLearningEnrolments::route('/'),
            'view' => ViewLearningEnrolment::route('/{record}'),
        ];
    }
}
