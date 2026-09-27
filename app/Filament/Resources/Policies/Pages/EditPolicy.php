<?php

namespace App\Filament\Resources\Policies\Pages;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Services\Policies;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPolicy extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = PolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Publish draft')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('success')
                ->authorize(fn () => auth()->user()->can('policy.publish'))
                ->visible(fn (Policy $record) => $record->draft()->exists())
                ->fillForm(['effective_from' => now()->toDateString()])
                ->schema([
                    DatePicker::make('effective_from')->native(false)->required()->helperText('The previous version ends the day before.'),
                    AuditReasonField::make(),
                ])
                ->action(function (Policy $record, array $data) {
                    try {
                        $version = app(Policies::class)->publish($record, $data['effective_from'], $data[AuditReasonField::NAME] ?? null);
                        Notification::make()->success()->title("Published v{$version->version}")->send();
                    } catch (ConfigurationException $e) {
                        Notification::make()->danger()->title('Cannot publish')->body($e->getMessage())->send();
                    }
                }),
            Action::make('newDraft')
                ->label('New draft')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->visible(fn (Policy $record) => $record->draft()->doesntExist())
                ->action(function (Policy $record) {
                    $draft = app(Policies::class)->draft($record);
                    Notification::make()->success()->title("Draft v{$draft->version} created")->send();
                }),
            DeleteAction::make(),
        ];
    }
}
