<?php

namespace App\Filament\Resources\BgvCases\RelationManagers;

use App\Domain\Bgv\Models\BgvCheck;
use App\Domain\Bgv\Services\Bgv;
use App\Domain\Documents\Models\EmployeeDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChecksRelationManager extends RelationManager
{
    protected static string $relationship = 'checks';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->formatStateUsing(fn (string $state) => config("peopleos.bgv.check_types.{$state}", $state)),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'clear' => 'success', 'discrepancy' => 'warning', 'failed' => 'danger', 'skipped' => 'gray', default => 'info',
                })->formatStateUsing(fn (string $state) => BgvCheck::STATUSES[$state] ?? $state),
                TextColumn::make('result_notes')->placeholder('—')->wrap(),
                TextColumn::make('evidence.title')->label('Evidence')->placeholder('—'),
                TextColumn::make('verifier.name')->label('By')->placeholder('—'),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('record')->label('Record result')->icon('heroicon-m-pencil-square')
                    ->authorize(fn () => auth()->user()->can('bgv.manage'))
                    ->visible(fn (BgvCheck $record) => ! $record->isClosed() && $this->getOwnerRecord()->isOpen())
                    ->schema([
                        Select::make('status')->options(array_diff_key(BgvCheck::STATUSES, ['pending' => 1]))->required(),
                        Textarea::make('notes')->maxLength(2000),
                        Select::make('evidence_document_id')->label('Evidence document')->options(fn () => $this->getOwnerRecord()->employee->documents()->pluck('title', 'id')->all())->placeholder('Optional'),
                    ])
                    ->action(function (BgvCheck $record, array $data) {
                        $evidence = isset($data['evidence_document_id']) ? EmployeeDocument::query()->find($data['evidence_document_id']) : null;
                        app(Bgv::class)->recordCheck($record, $data['status'], $data['notes'] ?? null, $evidence);
                        Notification::make()->success()->title('Result recorded')->send();
                    }),
            ]);
    }
}
