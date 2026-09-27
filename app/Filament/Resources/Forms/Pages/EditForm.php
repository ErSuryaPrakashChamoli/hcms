<?php

namespace App\Filament\Resources\Forms\Pages;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Services\Forms;
use App\Filament\Resources\Forms\FormResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\FormFieldsSchema;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class EditForm extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Publish draft')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('success')
                ->authorize(fn () => auth()->user()->can('form.publish'))
                ->visible(fn (Form $record) => $record->draft()->exists())
                ->requiresConfirmation()
                ->modalDescription('The draft becomes the live version and can no longer be edited. The previous version is retired.')
                ->schema([AuditReasonField::make()])
                ->action(function (Form $record, array $data) {
                    try {
                        $version = app(Forms::class)->publish($record, $data[AuditReasonField::NAME] ?? null);
                        Notification::make()->success()->title("Published v{$version->version}")->send();
                    } catch (ConfigurationException $e) {
                        Notification::make()->danger()->title('Cannot publish')->body($e->getMessage())->send();
                    }
                }),
            Action::make('newDraft')
                ->label('New draft')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->visible(fn (Form $record) => $record->draft()->doesntExist())
                ->action(function (Form $record) {
                    $draft = app(Forms::class)->draft($record);
                    Notification::make()->success()->title("Draft v{$draft->version} created from the published fields")->send();
                }),
            Action::make('fill')
                ->label('Fill form')
                ->icon(Heroicon::OutlinedPencil)
                ->authorize(fn () => auth()->user()->can('form.submit'))
                ->visible(fn (Form $record) => $record->published()->exists())
                ->modalHeading(fn (Form $record) => 'Fill: '.$record->name)
                ->schema(fn (Form $record) => FormFieldsSchema::components($record->published()->first()))
                ->action(function (Form $record, array $data) {
                    try {
                        $submission = app(Forms::class)->submit($record, $data);
                        Notification::make()->success()->title("Submission #{$submission->id} recorded")->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title('Invalid submission')->body(implode(' ', $e->validator->errors()->all()))->send();
                    }
                }),
            DeleteAction::make(),
        ];
    }
}
