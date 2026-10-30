<?php

namespace App\Filament\Resources\EmployeeImports;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Filament\Resources\EmployeeImports\Pages\ListEmployeeImports;
use App\Filament\Resources\EmployeeImports\Pages\ViewEmployeeImport;
use App\Filament\Resources\EmployeeImports\RelationManagers\RowsRelationManager;
use App\Filament\Support\ImportActions;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Employee import foundation (Phase 1 §38): staged, validated, approved, audited. */
class EmployeeImportResource extends Resource
{
    protected static ?string $model = EmployeeImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Employee imports';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        return EmployeeImports::enabled() && parent::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', 'employees');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('File')->columns(4)->schema([
                TextEntry::make('original_name')->label('File'),
                TextEntry::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'imported' => 'success', 'approved' => 'warning', 'failed', 'discarded' => 'danger', default => 'gray',
                }),
                TextEntry::make('uploader.name')->label('Uploaded by'),
                TextEntry::make('created_at')->dateTime()->label('Uploaded'),
            ]),
            Section::make('Rows')->columns(4)->schema([
                TextEntry::make('row_count')->label('Rows'),
                TextEntry::make('valid_count')->label('Valid'),
                TextEntry::make('error_count')->label('Errors'),
                TextEntry::make('review_count')->label('To review'),
                TextEntry::make('create_count')->label('Create'),
                TextEntry::make('update_count')->label('Update'),
                TextEntry::make('skip_count')->label('Skip'),
                TextEntry::make('failure_count')->label('Failed'),
            ]),
            Section::make('Choices')->columns(4)->visible(fn (EmployeeImport $record) => ImportActions::modes($record->type) !== null)->schema([
                TextEntry::make('mode')->label('File is for')->state(fn (EmployeeImport $record) => ImportActions::modes($record->type)[ImportMode::of($record)->value][0] ?? null),
                TextEntry::make('month')->label('Attendance month')->state(fn (EmployeeImport $record) => ImportActions::monthLabel($record))
                    ->visible(fn (EmployeeImport $record) => $record->type === AttendanceSheetImports::TYPE),
                TextEntry::make('blank_days')->label('Blank days')->state(fn (EmployeeImport $record) => AttendanceSheetImports::BLANK_DAYS[$record->options['blank_days'] ?? '']['0'] ?? null)
                    ->visible(fn (EmployeeImport $record) => $record->type === AttendanceSheetImports::TYPE),
            ]),
            Section::make('Mapping')->schema([
                TextEntry::make('mapping')->hiddenLabel()->placeholder('Not mapped yet')->state(fn (EmployeeImport $record) => collect($record->mapping ?? [])->map(fn ($f, $h) => "{$h} → {$f}")->values()->all())->listWithLineBreaks(),
            ])->collapsible(),
            Section::make('Approval and run')->columns(4)->schema([
                // HROne setup parity P2: who approves this upload type, its workflow, and when the run started.
                TextEntry::make('approval_mode')->label('Approved by rule')->state(fn (EmployeeImport $record) => ImportGovernance::approvalLabel($record)),
                TextEntry::make('workflow_status')->label('Workflow')->state(fn (EmployeeImport $record) => ImportGovernance::workflowStatus($record))->placeholder('—'),
                TextEntry::make('approver.name')->label('Approved by')->placeholder('—'),
                TextEntry::make('approved_at')->dateTime()->placeholder('—'),
                TextEntry::make('run_started_at')->label('Run started')->dateTime()->placeholder('—'),
                TextEntry::make('imported_at')->label('Run finished')->dateTime()->placeholder('—'),
                TextEntry::make('source')->label('Uploaded through')->formatStateUsing(fn (?string $state) => EmployeeImport::SOURCES[$state] ?? $state)->placeholder('—'),
                TextEntry::make('operation_id')->label('Audit operation')->placeholder('—')->fontFamily('mono'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('original_name')->label('File')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('row_count')->label('Rows'),
                TextColumn::make('create_count')->label('Create'),
                TextColumn::make('update_count')->label('Update'),
                TextColumn::make('error_count')->label('Errors'),
                TextColumn::make('uploader.name')->label('By'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RowsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeImports::route('/'),
            'view' => ViewEmployeeImport::route('/{record}'),
        ];
    }
}
