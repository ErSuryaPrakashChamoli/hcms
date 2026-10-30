<?php

namespace App\Filament\Resources\ConfirmationReviews\Pages;

use App\Domain\Lifecycle\Services\ConfirmationReviews;
use App\Filament\Resources\ConfirmationReviews\ConfirmationReviewResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class ListConfirmationReviews extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = ConfirmationReviewResource::class;

    public static function registerKey(): string
    {
        return 'confirmation_reviews';
    }

    public static function presetViews(): array
    {
        return [
            'pending' => ['label' => 'Waiting for a decision', 'filters' => ['status' => ['value' => 'pending']]],
            'due_this_month' => ['label' => 'Probation ending this month', 'filters' => ['status' => ['value' => 'pending'], 'request_dates' => ['from' => now()->startOfMonth()->toDateString(), 'until' => now()->endOfMonth()->toDateString()]]],
            'confirmed' => ['label' => 'Confirmed', 'filters' => ['status' => ['value' => 'confirmed']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['probation_due', 'probation_overdue', 'confirmations_this_month'];
    }

    public static function registerBulkOperations(): array
    {
        return ['confirmation_reviews.confirm'];
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            Action::make('openDue')->label('Open due reviews now')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(function () {
                    $opened = app(ConfirmationReviews::class)->openDue();
                    Notification::make()->success()->title("{$opened} review(s) opened")->send();
                }),
        ];
    }
}
