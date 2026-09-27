<?php

namespace App\Filament\Resources\AlumniProfiles\RelationManagers;

use App\Domain\Alumni\Models\AlumniRequest;
use App\Domain\Alumni\Services\Alumni;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'requests';

    protected static ?string $title = 'Requests';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    /** @return array<int, Action> */
    public static function actions(): array
    {
        $manage = fn () => auth()->user()->can('alumni.manage');

        return [
            Action::make('verify')->label('Verify identity')->icon('heroicon-m-identification')->color('gray')
                ->visible(fn (AlumniRequest $record) => $record->status === 'submitted' && $manage())
                ->schema([Textarea::make('note')->maxLength(500)])
                ->action(fn (AlumniRequest $record, array $data) => ServiceDeskActions::run(fn () => app(Alumni::class)->verify($record, auth()->user(), $data['note'] ?? null), 'Verified')),
            Action::make('approve')->label('Approve & generate')->icon('heroicon-m-check-badge')->color('success')
                ->visible(fn (AlumniRequest $record) => in_array($record->status, ['submitted', 'verified'], true) && $manage())
                ->schema([Textarea::make('note')->maxLength(500)])
                ->action(fn (AlumniRequest $record, array $data) => ServiceDeskActions::run(fn () => app(Alumni::class)->approve($record, auth()->user(), $data['note'] ?? null), fn ($r) => $r->letter_id ? 'Letter generated and issued' : 'Approved')),
            Action::make('deliver')->label('Mark delivered')->icon('heroicon-m-paper-airplane')->color('primary')
                ->visible(fn (AlumniRequest $record) => in_array($record->status, ['approved', 'generated'], true) && $manage())
                ->schema([Textarea::make('response')->maxLength(1000)])
                ->action(fn (AlumniRequest $record, array $data) => ServiceDeskActions::run(fn () => app(Alumni::class)->deliver($record, auth()->user(), $data['response'] ?? null), 'Delivered')),
            Action::make('reject')->label('Reject')->icon('heroicon-m-x-mark')->color('danger')
                ->visible(fn (AlumniRequest $record) => $record->isOpen() && $manage())
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (AlumniRequest $record, array $data) => ServiceDeskActions::run(fn () => app(Alumni::class)->reject($record, auth()->user(), $data['reason']), 'Rejected')),
            Action::make('letter')->label('Open letter')->icon('heroicon-m-envelope')->color('gray')
                ->visible(fn (AlumniRequest $record) => $record->letter_id !== null)
                ->url(fn (AlumniRequest $record) => LetterResource::getUrl('view', ['record' => $record->letter_id])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('handler'))
            ->columns([
                TextColumn::make('number'),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.alumni.request_types.{$state}", $state)),
                TextColumn::make('details')->limit(60)->placeholder('—')->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'delivered' => 'success', 'rejected' => 'danger', 'generated', 'approved' => 'primary', default => 'warning'
                })->formatStateUsing(fn (string $state) => config("peopleos.alumni.request_statuses.{$state}", $state)),
                TextColumn::make('handler.name')->label('Handled by')->placeholder('—'),
                TextColumn::make('response')->limit(60)->placeholder('—'),
                TextColumn::make('created_at')->since(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions(self::actions());
    }
}
