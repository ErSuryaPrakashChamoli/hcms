<?php

namespace App\Filament\Resources\Policies\Pages;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Services\Policies;
use App\Filament\Pages\PolicyWizard;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class EditPolicy extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = PolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('wizard')
                ->label('Edit in wizard')
                ->icon(Heroicon::OutlinedQueueList)
                ->color('primary')
                ->visible(fn () => auth()->user()->can('policy.update'))
                ->url(fn (Policy $record) => PolicyWizard::getUrl(['policy' => $record->id])),
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
                        $this->reloadPage($record);
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
                    Notification::make()->success()->title("Draft v{$draft->version} created")->body('Open it with Edit draft under Versions & settings.')->send();
                    $this->reloadPage($record);
                }),
            PolicyResource::duplicateAction(),
            DeleteAction::make(),
        ];
    }

    /** A policy with no assignment rules applies to nobody: say so where it is edited. */
    public function getSubheading(): ?string
    {
        return $this->getRecord()->assignmentRules()->exists() ? null : 'This policy applies to nobody yet: choose who it applies to in the wizard (Applicability) or add a rule under Assignment rules.';
    }

    /** Reload so the versions list shows the new draft or published version (relation managers keep their own state). */
    private function reloadPage(Policy $record): void
    {
        $this->redirect(PolicyResource::getUrl('edit', ['record' => $record]));
    }
}
