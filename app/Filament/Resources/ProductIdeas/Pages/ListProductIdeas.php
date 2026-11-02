<?php

namespace App\Filament\Resources\ProductIdeas\Pages;

use App\Domain\ProductFeedback\Models\ProductIdea;
use App\Domain\ProductFeedback\Services\ProductIdeas;
use App\Filament\Resources\ProductIdeas\ProductIdeaResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/** HROne parity AC-3: the organisation's product ideas and "Suggest an improvement" (opened directly by `?action=create`). */
class ListProductIdeas extends PeopleListRecords
{
    protected static string $resource = ProductIdeaResource::class;

    public function getSubheading(): ?string
    {
        return trim($this->moduleContext()['sentence'].' Your organisation\'s ideas for PeopleOS and where Markedge\'s product team has them; the person who sent one is told when its status changes.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Suggest an improvement')->icon(Heroicon::OutlinedLightBulb)
                ->modalHeading('Suggest an improvement to PeopleOS')
                ->modalDescription('Your idea goes to Markedge\'s product team. Describe the challenge, how you manage it today and what would help.')
                ->createAnother(false)
                ->using(function (array $data, Action $action): ProductIdea {
                    try {
                        return app(ProductIdeas::class)->submit(auth()->user(), $data);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
                        $action->halt();
                    }
                })
                ->successNotificationTitle(fn (ProductIdea $record) => "Thank you: idea {$record->reference} is with Markedge")
                ->successRedirectUrl(fn (ProductIdea $record) => ProductIdeaResource::getUrl('view', ['record' => $record])),
        ];
    }
}
