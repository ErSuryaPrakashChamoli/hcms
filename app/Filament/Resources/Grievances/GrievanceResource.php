<?php

namespace App\Filament\Resources\Grievances;

use App\Domain\Grievance\Models\Grievance;
use App\Domain\Grievance\Services\Grievances;
use App\Domain\Identity\Models\User;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Grievances\Pages\ListGrievances;
use App\Filament\Resources\Grievances\Pages\ViewGrievance;
use App\Filament\Resources\Grievances\RelationManagers\NotesRelationManager;
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

/** Grievance cases (§49). The list only ever shows cases the viewer can access. */
class GrievanceResource extends Resource
{
    protected static ?string $model = Grievance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return auth()->user()?->can('grievance.view') || auth()->user()?->can('grievance.manage') ? 'Grievances' : 'Me';
    }

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->can('grievance.view') || auth()->user()?->can('grievance.manage') ? 'Cases' : 'My grievances';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['category', 'employee.person', 'assignee']);
        $service = app(Grievances::class);

        // Access is per-case; filter by ids the service allows (cases are few, confidentiality matters more than a join).
        $ids = $query->get()->filter(fn (Grievance $g) => $service->canAccess($user, $g))->pluck('id');

        return parent::getEloquentQuery()->with(['category', 'employee.person', 'assignee'])->whereIn('id', $ids);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'submitted' => 'info', 'under_review', 'investigating' => 'warning', 'action_taken' => 'primary', 'resolved', 'closed' => 'success', 'withdrawn' => 'gray', default => 'gray'
        };
    }

    public static function complainant(Grievance $record): string
    {
        return $record->is_anonymous ? 'Anonymous' : ($record->employee?->person?->full_name ?? '—');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Grievance $record) => "{$record->number} · {$record->subject}")->columns(4)->schema([
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.grievance.statuses.{$state}", $state)),
                TextEntry::make('severity')->badge()->color(fn (string $state) => match ($state) {
                    'critical' => 'danger', 'high' => 'warning', default => 'gray'
                }),
                TextEntry::make('category.name')->label('Category'),
                TextEntry::make('complainant')->label('Raised by')->state(fn (Grievance $record) => self::complainant($record)),
                TextEntry::make('assignee.name')->label('Handler')->placeholder('Unassigned'),
                TextEntry::make('created_at')->label('Raised')->dateTime(),
                TextEntry::make('due_on')->label('Due')->date()->placeholder('—')->color(fn (Grievance $record) => $record->isOpen() && $record->due_on?->isPast() ? 'danger' : null),
                TextEntry::make('access')->label('Also visible to')->state(fn (Grievance $record) => User::query()->whereIn('id', $record->access_user_ids ?? [])->pluck('name')->implode(', '))->placeholder('Handlers only'),
                TextEntry::make('details')->columnSpanFull(),
                TextEntry::make('resolution')->placeholder('—')->columnSpanFull()->visible(fn (Grievance $record) => $record->resolution !== null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('subject')->limit(50),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('complainant')->label('Raised by')->state(fn (Grievance $record) => self::complainant($record)),
                TextColumn::make('severity')->badge()->color(fn (string $state) => match ($state) {
                    'critical' => 'danger', 'high' => 'warning', default => 'gray'
                }),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.grievance.statuses.{$state}", $state)),
                TextColumn::make('assignee.name')->label('Handler')->placeholder('Unassigned'),
                TextColumn::make('due_on')->date()->placeholder('—')->color(fn (Grievance $record) => $record->isOpen() && $record->due_on?->isPast() ? 'danger' : null),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.grievance.statuses'))->multiple(), SelectFilter::make('grievance_category_id')->label('Category')->relationship('category', 'name')])
            ->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function getRelations(): array
    {
        return [NotesRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGrievances::route('/'),
            'view' => ViewGrievance::route('/{record}'),
        ];
    }
}
