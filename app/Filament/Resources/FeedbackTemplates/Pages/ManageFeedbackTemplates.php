<?php

namespace App\Filament\Resources\FeedbackTemplates\Pages;

use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Feedback\Services\FeedbackTemplates;
use App\Filament\Resources\FeedbackTemplates\FeedbackTemplateResource;
use App\Filament\Resources\QuestionBanks\QuestionBankResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use RuntimeException;

class ManageFeedbackTemplates extends PeopleManageRecords
{
    protected static string $resource = FeedbackTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add template')->using(fn (array $data) => FeedbackTemplateResource::save(null, $data)),
            // HROne's default templates ("Search template"): the feedback starter pack, template by template.
            Action::make('library')->label('Add from library')->icon('heroicon-m-sparkles')->color('gray')
                ->visible(fn () => auth()->user()->can('feedback.manage'))
                ->schema([
                    CheckboxList::make('codes')->label('Templates')->required()->searchable()
                        ->options(fn () => collect(FeedbackTemplates::library())->map(fn (array $t) => $t['name'].' — '.config("peopleos.feedback.processes.{$t['process']}.label"))->all())
                        ->descriptions(fn () => collect(FeedbackTemplates::library())->map(fn (array $t) => $t['description'] ?? '')->all())
                        ->disableOptionWhen(fn (string $value) => FeedbackTemplate::query()->where('code', $value)->exists())
                        ->helperText('Templates you already have (same code) are left as they are.'),
                ])
                ->action(function (array $data) {
                    try {
                        $added = app(FeedbackTemplates::class)->addFromLibrary(array_values((array) ($data['codes'] ?? [])), auth()->user());
                        Notification::make()->success()->title($added.' template(s) added')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not added')->body($e->getMessage())->send();
                    }
                }),
            Action::make('banks')->label('Question banks')->color('gray')->icon('heroicon-m-question-mark-circle')->url(fn () => QuestionBankResource::getUrl('index')),
        ];
    }
}
