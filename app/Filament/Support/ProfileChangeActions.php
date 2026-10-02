<?php

namespace App\Filament\Support;

use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Phase 12: Employee 360 profile screens are only a UI over the People / Employment change actions.
 * A refusal from the action (authorization, validation, duplicate) is shown and stops the form.
 */
final class ProfileChangeActions
{
    /**
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    public static function run(callable $change): mixed
    {
        try {
            return $change();
        } catch (ProfileChangeRefused $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }
}
