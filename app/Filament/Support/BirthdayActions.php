<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\People\Services\Birthdays;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/** "Send wishes" on someone's birthday, mounted with ['employee' => id] (Birthdays page and Home). */
final class BirthdayActions
{
    public static function wish(?Closure $after = null): Action
    {
        return Action::make('wish')->label('Send wishes')->icon(Heroicon::OutlinedGift)
            ->modalHeading(fn (array $arguments) => 'Wish '.(self::celebrant($arguments)?->person?->display_name ?? 'them').' a happy birthday')
            ->modalDescription('They see your name and message in their notifications and on their Birthdays page.')
            ->modalSubmitActionLabel('Send')
            ->fillForm(fn (array $arguments) => ['message' => 'Happy birthday, '.(self::celebrant($arguments)?->person?->first_name ?? '').'! Have a wonderful year ahead.'])
            ->schema([Textarea::make('message')->label('Your message')->required()->rows(3)->maxLength(Birthdays::MESSAGE_MAX)])
            ->action(function (array $data, array $arguments) use ($after) {
                $employee = self::celebrant($arguments);
                try {
                    if ($employee === null) {
                        throw new RuntimeException('This birthday is not shared with you.');
                    }
                    app(Birthdays::class)->wish(auth()->user(), $employee, $data['message']);
                    Notification::make()->success()->title('Wishes sent to '.$employee->person->first_name)->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Could not send')->body($e->getMessage())->send();
                }
                if ($after !== null) {
                    $after();
                }
            });
    }

    /** @param  array<string, mixed>  $arguments  The celebrant, only when the viewer can see their birthday. */
    private static function celebrant(array $arguments): ?Employee
    {
        return isset($arguments['employee']) ? app(Birthdays::class)->query(auth()->user())->whereKey((int) $arguments['employee'])->first() : null;
    }
}
