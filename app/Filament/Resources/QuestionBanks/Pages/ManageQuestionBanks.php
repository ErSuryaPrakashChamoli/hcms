<?php

namespace App\Filament\Resources\QuestionBanks\Pages;

use App\Filament\Resources\FeedbackTemplates\FeedbackTemplateResource;
use App\Filament\Resources\QuestionBanks\QuestionBankResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;

class ManageQuestionBanks extends PeopleManageRecords
{
    protected static string $resource = QuestionBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data) => QuestionBankResource::save(null, $data)),
            Action::make('templates')->label('Feedback templates')->color('gray')->icon('heroicon-m-document-text')->url(fn () => FeedbackTemplateResource::getUrl('index')),
        ];
    }
}
