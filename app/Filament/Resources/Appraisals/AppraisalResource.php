<?php

namespace App\Filament\Resources\Appraisals;

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Appraisals\Pages\ListAppraisals;
use App\Filament\Resources\Appraisals\Pages\ViewAppraisal;
use App\Filament\Resources\Appraisals\RelationManagers\ReviewsRelationManager;
use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
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

/** Appraisals (§34): HR sees all; employees their own; managers their reports; reviewers what they must review. */
class AppraisalResource extends Resource
{
    protected static ?string $model = Appraisal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Appraisals';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['cycle', 'employee.person', 'manager.person', 'reviews']);
        $user = auth()->user();

        if ($user->can('performance.view')) {
            return $query;
        }

        $me = EmployeeOwnedPolicy::employeeOf($user);
        if ($me === null) {
            return $query->whereRaw('1 = 0');
        }
        $reports = $user->can('performance.team') ? $me->directReports()->currentlyEffective()->pluck('employee_id') : collect();

        return $query->where(fn (Builder $q) => $q->where('employee_id', $me->id)->orWhereIn('employee_id', $reports)->orWhereHas('reviews', fn (Builder $r) => $r->where('reviewer_id', $me->id)));
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'pending' => 'gray', 'in_review' => 'info', 'calibration' => 'warning', 'finalized' => 'success', 'acknowledged' => 'primary', default => 'gray'
        };
    }

    /** @return array<int, TextColumn> */
    public static function columns(): array
    {
        return [
            TextColumn::make('employee.employee_code')->label('Code')->searchable(),
            TextColumn::make('employee.person.full_name')->label('Employee')->searchable(['first_name', 'last_name']),
            TextColumn::make('manager.person.full_name')->label('Manager')->placeholder('—'),
            TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.performance.appraisal_statuses.{$state}", $state)),
            TextColumn::make('reviews')->label('Reviews')->state(fn (Appraisal $record) => $record->reviews->map(fn ($r) => ucfirst($r->type).($r->isSubmitted() ? ' ✓' : ' …'))->implode(', ')),
            TextColumn::make('self_rating')->label('Self')->placeholder('—'),
            TextColumn::make('manager_rating')->label('Manager')->placeholder('—'),
            TextColumn::make('computed_rating')->label('Computed')->placeholder('—'),
            TextColumn::make('calibrated_rating')->label('Calibrated')->placeholder('—'),
            TextColumn::make('final_rating')->label('Final')->placeholder('—')->weight('bold'),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Appraisal')->columns(4)->schema([
                TextEntry::make('cycle.name')->label('Cycle')->url(fn (Appraisal $record) => PerformanceCycleResource::getUrl('edit', ['record' => $record->cycle])),
                TextEntry::make('employee.person.full_name')->label('Employee'),
                TextEntry::make('manager.person.full_name')->label('Manager')->placeholder('—'),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.performance.appraisal_statuses.{$state}", $state)),
                TextEntry::make('cycle.current_stage')->label('Cycle stage')->placeholder('—')->formatStateUsing(fn (?string $state) => config("peopleos.performance.stages.{$state}", $state)),
                TextEntry::make('goal_score')->label('Goal score')->suffix('%')->placeholder('—'),
                TextEntry::make('competency_score')->label('Competency score')->suffix('%')->placeholder('—'),
                TextEntry::make('computed_rating')->label('Computed rating')->placeholder('—'),
                TextEntry::make('self_rating')->label('Self rating')->placeholder('—'),
                TextEntry::make('manager_rating')->label('Manager rating')->placeholder('—'),
                TextEntry::make('peer_rating')->label('Peer rating')->placeholder('—'),
                TextEntry::make('calibrated_rating')->label('Calibrated')->placeholder('—'),
                TextEntry::make('final_rating')->label('Final rating')->placeholder('—')->weight('bold'),
                TextEntry::make('final_label')->label('Final label')->placeholder('—')->badge()->color('success'),
                TextEntry::make('promotion_recommended')->label('Promotion')->formatStateUsing(fn ($state) => $state ? 'Recommended' : '—'),
                TextEntry::make('pip_recommended')->label('Improvement plan')->formatStateUsing(fn ($state) => $state ? 'Recommended' : '—'),
                TextEntry::make('manager_summary')->label('Summary')->placeholder('—')->columnSpan(2)->visible(fn (Appraisal $record) => $record->isFinal() || auth()->user()->can('performance.calibrate')),
                TextEntry::make('calibration_note')->placeholder('—')->columnSpan(2)->visible(fn () => auth()->user()->can('performance.calibrate')),
                TextEntry::make('employee_comment')->label('Employee comment')->placeholder('—')->columnSpan(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('cycle.name')->label('Cycle')->sortable(), ...self::columns()])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('performance_cycle_id')->label('Cycle')->relationship('cycle', 'name'),
                SelectFilter::make('status')->options(config('peopleos.performance.appraisal_statuses')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ReviewsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppraisals::route('/'),
            'view' => ViewAppraisal::route('/{record}'),
        ];
    }
}
