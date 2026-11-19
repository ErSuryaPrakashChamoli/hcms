<?php

namespace App\Filament\Support;

use App\Support\Database\DuplicateEntry;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\Form;
use Throwable;

use function Livewire\on;

/**
 * 2026-11-19 (owner: a duplicate entry must never show the server-error page). UniqueCode checks unique columns
 * while a form is filled in; a clash that still reaches the database — two people saving the same code at once, or a
 * form whose unique key spans several fields — is caught here, on any screen: the save is undone, the field (when the
 * form has one for the clashing column) shows the error, and a notice says what to change. The page stays as it was,
 * with everything typed kept.
 */
final class DuplicateEntryGuard
{
    public static function register(): void
    {
        on('exception', function (mixed $target, Throwable $e, Closure $stopPropagation): void {
            if (! $e instanceof UniqueConstraintViolationException) {
                return;
            }
            $component = $target instanceof Form ? $target->getComponent() : $target;
            if (! $component instanceof Component) {
                return;
            }

            self::handle($component, $e);
            $stopPropagation();
        });
    }

    public static function handle(Component $component, UniqueConstraintViolationException $e): void
    {
        // Nothing is saved: Filament's pages and actions, and DB::transaction(), have rolled their work back by now.
        $entry = DuplicateEntry::from($e);
        [$path, $value] = $entry !== null ? self::field($component, $entry) : [null, null];
        $message = self::message($entry, $path === null ? null : $value);

        if ($path !== null) {
            $component->addError($path, $message);
        }
        Notification::make()->danger()->title('Already in use')->body($message)->persistent()->send();

        Log::notice('A duplicate entry was refused by the database and shown on the form.', [
            'component' => $component::class,
            'table' => $entry?->table,
            'columns' => $entry?->columns,
        ]);
    }

    /**
     * The form field holding the clashing column: in the open drawer or modal first, then the page's form.
     *
     * @return array{0: ?string, 1: mixed} [state path, its value]
     */
    private static function field(Component $component, DuplicateEntry $entry): array
    {
        $bases = [];
        $mounted = property_exists($component, 'mountedActions') ? $component->mountedActions : null;
        if (is_array($mounted) && $mounted !== []) {
            $bases[] = 'mountedActions.'.array_key_last($mounted).'.data';
        }
        $bases[] = 'data';

        foreach ($bases as $base) {
            foreach ($entry->fields() as $column) {
                $value = data_get($component, $base.'.'.$column);
                if ($value !== null && $value !== '' && $value !== []) {
                    return [$base.'.'.$column, $value];
                }
            }
        }

        return [null, $entry->values[$entry->fields()[0] ?? ''] ?? null];
    }

    private static function message(?DuplicateEntry $entry, mixed $formValue): string
    {
        $fields = $entry?->fields() ?? [];
        if ($entry === null || $fields === []) {
            return 'This record already exists. Change the details that must be unique and try again.';
        }

        $noun = $entry->noun();
        if (count($fields) === 1) {
            $label = DuplicateEntry::fieldLabel($fields[0]);
            $value = $formValue ?? ($entry->values[$fields[0]] ?? null);

            return is_scalar($value) && $value !== '' && ! str_ends_with($fields[0], '_id')
                ? 'Another '.$noun.' already has the '.$label.' '.$value.'. Change the '.$label.' and try again.'
                : 'Another '.$noun.' already has this '.$label.'. Change the '.$label.' and try again.';
        }

        $labels = array_map(DuplicateEntry::fieldLabel(...), $fields);
        $last = array_pop($labels);

        return 'A '.$noun.' with the same '.($labels === [] ? $last : implode(', ', $labels).' and '.$last).' already exists. Change one of them and try again.';
    }
}
