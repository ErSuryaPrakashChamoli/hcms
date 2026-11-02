<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * HROne parity WO-15: runs a Partners service call from a drawer or modal. The services validate everything themselves
 * (the same rules for the screen and for uploads), so their ValidationException is shown on the fields of the open form
 * (`mountedActions.N.data.<field>`), and a refusal (RuntimeException, AuthorizationException) as a notification that keeps
 * the form open.
 */
final class PartnerForms
{
    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public static function run(Action $action, Closure $call): mixed
    {
        try {
            return $call();
        } catch (ValidationException $e) {
            $path = 'mountedActions.'.($action->getNestingIndex() ?? 0).'.data.';
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $field) => [$path.$field => $messages])->all());
        } catch (RuntimeException|AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }

        return null;
    }
}
