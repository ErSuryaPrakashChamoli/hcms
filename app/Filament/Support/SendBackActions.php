<?php

namespace App\Filament\Support;

use App\Domain\Workflow\Services\RequestReturns;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne setup parity P3 (Send back): on a request's own screen (leave, time office, loans, reimbursement claims), the
 * person an approver sent it back to sees what to change and resubmits it, with an answer.
 */
final class SendBackActions
{
    public static function resubmit(): Action
    {
        $open = fn (Model $record) => app(RequestReturns::class)->openFor($record);

        return Action::make('resubmitSentBack')->label('Resubmit')->icon('heroicon-m-arrow-uturn-right')->color('success')
            ->visible(fn (Model $record) => ($return = $open($record)) !== null && (int) $return->requester_id === (int) auth()->id())
            ->modalHeading('Sent back to you')
            ->modalDescription(fn (Model $record) => ($return = $open($record)) ? 'Sent back by '.($return->returner()->value('name') ?? 'an approver').': '.$return->note : null)
            ->schema([Textarea::make('response')->label('Your answer (the approver sees it)')->maxLength(2000)])
            ->modalSubmitActionLabel('Resubmit')
            ->action(function (Model $record, array $data) use ($open) {
                $return = $open($record);
                if ($return === null) {
                    return;
                }
                try {
                    app(RequestReturns::class)->resubmit($return, auth()->user(), $data['response'] ?? null);
                    Notification::make()->success()->title('Resubmitted')->body('It is back with the approvers.')->send();
                } catch (AuthorizationException|RuntimeException $e) {
                    Notification::make()->danger()->title('Not resubmitted')->body($e->getMessage())->send();
                }
            });
    }
}
