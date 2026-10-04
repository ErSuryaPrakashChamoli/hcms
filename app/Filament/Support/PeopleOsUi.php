<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Filament\Support\Forms\PeopleDatePicker;
use App\Filament\Support\Forms\PeopleDateTimePicker;
use App\Filament\Support\Forms\PeopleTimePicker;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * UX.15 closure: central defaults that bring every module table and modal into the PeopleOS interaction
 * language without per-page code. A resource that configures the same thing itself keeps its own choice
 * (configureUsing runs first; the resource's later calls win).
 */
final class PeopleOsUi
{
    /** Employee-name columns and where the row keeps that employee's id ('*' = the row is the employee). */
    private const PERSON_COLUMNS = [
        'employee.person.full_name' => 'employee_id', 'employee.person.display_name' => 'employee_id', 'employee.display_name' => 'employee_id',
        'manager.person.full_name' => 'manager_id', 'manager.person.display_name' => 'manager_id',
        'person.full_name' => '*', 'person.display_name' => '*',
    ];

    public static function register(): void
    {
        // People in tables become person chips: hover (or focus the row) to peek; the peek checks visibility itself.
        TextColumn::configureUsing(function (TextColumn $column): void {
            $key = self::PERSON_COLUMNS[$column->getName()] ?? null;
            if ($key === null) {
                return;
            }
            $column->formatStateUsing(function (mixed $state, Model $record) use ($key): HtmlString {
                $id = $key === '*' ? ($record instanceof Employee ? $record->getKey() : null)
                    : (array_key_exists($key, $record->getAttributes()) ? $record->getAttributes()[$key] : null);
                if ($id === null || blank($state)) {
                    return new HtmlString(e((string) $state));
                }

                return new HtmlString(view('components.pos.person-inline', ['id' => (int) $id, 'name' => (string) $state])->render());
            })->html();
        });

        // Records open beside the list in a drawer, as everywhere else in PeopleOS, instead of a centred dialog.
        // Drawer forms end in the same review step as record pages (shown once something is filled in or changed).
        CreateAction::configureUsing(fn (CreateAction $action) => $action->slideOver()
            ->modalContentFooter(fn () => view('filament.shell.form-review', ['mode' => 'create', 'createLabel' => 'Create'])));
        EditAction::configureUsing(fn (EditAction $action) => $action->slideOver()
            ->modalContentFooter(fn () => view('filament.shell.form-review', ['mode' => 'edit', 'createLabel' => 'Create'])));
        ViewAction::configureUsing(fn (ViewAction $action) => $action->slideOver());

        // Date and time pickers keep Filament's behaviour with an accessible trigger (Filament builds fields
        // through the container, so the subclasses apply everywhere without touching a form).
        app()->bind(DatePicker::class, PeopleDatePicker::class);
        app()->bind(DateTimePicker::class, PeopleDateTimePicker::class);
        app()->bind(TimePicker::class, PeopleTimePicker::class);
    }
}
