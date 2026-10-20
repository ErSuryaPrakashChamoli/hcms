<?php

namespace App\Filament\Resources\Reports\RelationManagers;

use App\Domain\Analytics\Models\ReportRun;
use App\Domain\Analytics\Services\ReportExports;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Runs';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['runner', 'schedule']))
            ->columns([
                TextColumn::make('started_at')->dateTime()->sortable(),
                TextColumn::make('runner.name')->label('By')->placeholder('Schedule'),
                TextColumn::make('row_count')->label('Rows'),
                TextColumn::make('format')->badge()->color('gray'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'completed' => 'success', 'failed' => 'danger', default => 'warning'
                }),
                TextColumn::make('error')->placeholder('—')->limit(60),
            ])
            ->defaultSort('started_at', 'desc')
            ->recordActions([
                Action::make('download')->label('Download')->icon('heroicon-m-arrow-down-tray')
                    ->visible(fn (ReportRun $record) => app(ReportExports::class)->canDownload($record, auth()->user()))
                    ->action(function (ReportRun $record) {
                        $contents = app(ReportExports::class)->download($record, auth()->user());

                        return response()->streamDownload(fn () => print ($contents), basename($record->path), ['Content-Type' => 'text/csv']);
                    }),
            ]);
    }
}
