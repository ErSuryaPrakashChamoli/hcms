<?php

namespace App\Filament\Resources\ConfigurationChanges\Pages;

use App\Domain\Configuration\Enums\ChangeStatus;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Domain\Configuration\Services\ConfigurationChanges;
use App\Filament\Resources\ConfigurationChanges\ConfigurationChangeResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class ViewConfigurationChange extends PeopleViewRecord
{
    protected static string $resource = ConfigurationChangeResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn (string $key) => auth()->user()->can("configuration.{$key}");

        return [
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->authorize(fn () => $can('approve'))
                ->visible(fn (ConfigurationChange $record) => $record->status === ChangeStatus::PendingApproval)
                ->requiresConfirmation()
                ->modalDescription(fn (ConfigurationChange $record) => ($record->impact['summary'] ?? '').' '.($record->effective_from && $record->effective_from->isFuture() ? 'It will publish on '.$record->effective_from->toFormattedDateString().'.' : 'It publishes immediately.'))
                ->schema([Textarea::make('note')->maxLength(255)])
                ->action(fn (ConfigurationChange $record, array $data) => $this->run(fn () => app(ConfigurationChanges::class)->approve($record, $data['note'] ?? null), 'Approved')),
            Action::make('reject')->label('Reject')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->authorize(fn () => $can('approve'))
                ->visible(fn (ConfigurationChange $record) => $record->status === ChangeStatus::PendingApproval)
                ->schema([Textarea::make('note')->required()->maxLength(255)])
                ->action(fn (ConfigurationChange $record, array $data) => $this->run(fn () => app(ConfigurationChanges::class)->reject($record, $data['note']), 'Rejected')),
            Action::make('publishNow')->label('Publish now')->icon(Heroicon::OutlinedRocketLaunch)->color('warning')
                ->authorize(fn () => $can('publish'))
                ->visible(fn (ConfigurationChange $record) => $record->status === ChangeStatus::Scheduled)
                ->requiresConfirmation()
                ->action(fn (ConfigurationChange $record) => $this->run(fn () => app(ConfigurationChanges::class)->publish($record), 'Published')),
            Action::make('discard')->label('Discard')->icon(Heroicon::OutlinedTrash)->color('gray')
                ->authorize(fn () => $can('delete'))
                ->visible(fn (ConfigurationChange $record) => $record->isOpen())
                ->requiresConfirmation()
                ->schema([Textarea::make('note')->maxLength(255)])
                ->action(fn (ConfigurationChange $record, array $data) => $this->run(fn () => app(ConfigurationChanges::class)->discard($record, $data['note'] ?? null), 'Discarded')),
            Action::make('rollback')->label('Roll back')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
                ->authorize(fn () => $can('rollback'))
                ->visible(fn (ConfigurationChange $record) => $record->status === ChangeStatus::Published)
                ->requiresConfirmation()
                ->modalDescription('Creates a new change that restores the previous values. History is never deleted.')
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (ConfigurationChange $record, array $data) => $this->run(fn () => app(ConfigurationChanges::class)->rollback($record, $data['reason']), 'Rollback created')),
        ];
    }

    private function run(callable $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
            $this->refreshFormData([]);
        } catch (ConfigurationException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
        }
    }
}
