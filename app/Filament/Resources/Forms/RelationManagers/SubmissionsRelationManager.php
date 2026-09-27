<?php

namespace App\Filament\Resources\Forms\RelationManagers;

use App\Domain\Configuration\Models\FormSubmission;
use App\Domain\Configuration\Services\Forms;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'submissions';

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('version.version')->label('Form version')->formatStateUsing(fn ($state) => "v{$state}"),
            TextEntry::make('submitter.name')->label('Submitted by')->placeholder('—'),
            TextEntry::make('status')->badge(),
            TextEntry::make('review_note')->placeholder('—'),
            KeyValueEntry::make('data')->label('Answers')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('version.version')->label('Version')->formatStateUsing(fn ($state) => "v{$state}"),
                TextColumn::make('submitter.name')->label('Submitted by')->placeholder('—'),
                TextColumn::make('created_at')->label('Submitted')->dateTime()->sortable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', default => 'warning',
                }),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(FormSubmission::STATUSES)])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')->color('success')->icon('heroicon-m-check')
                    ->authorize(fn () => auth()->user()->can('form.approve'))
                    ->visible(fn (FormSubmission $record) => $record->status === 'submitted')
                    ->schema([Textarea::make('note')->maxLength(255)])
                    ->action(fn (FormSubmission $record, array $data) => app(Forms::class)->review($record, true, $data['note'] ?? null)),
                Action::make('reject')->color('danger')->icon('heroicon-m-x-mark')
                    ->authorize(fn () => auth()->user()->can('form.approve'))
                    ->visible(fn (FormSubmission $record) => $record->status === 'submitted')
                    ->schema([Textarea::make('note')->required()->maxLength(255)])
                    ->action(fn (FormSubmission $record, array $data) => app(Forms::class)->review($record, false, $data['note'])),
            ]);
    }
}
