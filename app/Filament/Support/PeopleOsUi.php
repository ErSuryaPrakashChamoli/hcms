<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Filament\Support\Actions\PeopleAction;
use App\Filament\Support\Actions\PeopleBulkAction;
use App\Filament\Support\Actions\PeopleCreateAction;
use App\Filament\Support\Actions\PeopleEditAction;
use App\Filament\Support\Forms\PeopleDatePicker;
use App\Filament\Support\Forms\PeopleDateTimePicker;
use App\Filament\Support\Forms\PeopleTimePicker;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\View\TablesIconAlias;
use Illuminate\Contracts\Support\Htmlable;
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
        // Drawer forms end in the same review step as record pages (shown once something is filled in or changed);
        // a drawer form with sections is a wizard instead, whose last step is that review (FormWizard).
        app()->bind(CreateAction::class, PeopleCreateAction::class);
        app()->bind(EditAction::class, PeopleEditAction::class);
        // Streamline S6 (owner 2026-11-05: "a wizard everywhere"): other actions' modal forms follow the same wizard once
        // they ask for enough to fill in; short confirmations and read-only modals stay as they are.
        app()->bind(Action::class, PeopleAction::class);
        app()->bind(BulkAction::class, PeopleBulkAction::class);
        CreateAction::configureUsing(fn (CreateAction $action) => $action->slideOver()
            ->modalContentFooter(fn (CreateAction $action) => $action->isWizard() ? null : view('filament.shell.form-review', ['mode' => 'create', 'createLabel' => 'Create'])));
        EditAction::configureUsing(fn (EditAction $action) => $action->slideOver()
            ->modalContentFooter(fn (EditAction $action) => $action->isWizard() ? null : view('filament.shell.form-review', ['mode' => 'edit', 'createLabel' => 'Create'])));
        ViewAction::configureUsing(fn (ViewAction $action) => $action->slideOver());

        // Date and time pickers keep Filament's behaviour with an accessible trigger (Filament builds fields
        // through the container, so the subclasses apply everywhere without touching a form).
        app()->bind(DatePicker::class, PeopleDatePicker::class);
        app()->bind(DateTimePicker::class, PeopleDateTimePicker::class);
        app()->bind(TimePicker::class, PeopleTimePicker::class);

        // An empty table shows an empty tray, not Filament's default cross (it read as an error); a resource's own icon wins.
        FilamentIcon::register([TablesIconAlias::EMPTY_STATE => Heroicon::OutlinedInbox]);

        // Every wizard step says where it is (its number, how many there are, its name) and Previous / Next carry arrows:
        // theme.css J draws "Step 2 of 5" and the progress bar over the open step from these, in every wizard at once.
        Step::configureUsing(fn (Step $step) => $step->extraAttributes(fn (Step $step): array => self::stepStamp($step), merge: true));
        Wizard::configureUsing(fn (Wizard $wizard) => $wizard
            ->previousAction(fn (Action $action) => $action->icon('heroicon-m-arrow-left'))
            ->nextAction(fn (Action $action) => $action->icon('heroicon-m-arrow-right')->iconPosition(IconPosition::After)));
    }

    /**
     * Where a step sits in its wizard. Filament merges a step's extra attributes unescaped, so the label is escaped here.
     *
     * @return array<string, string>
     */
    private static function stepStamp(Step $step): array
    {
        $wizard = $step->getContainer()->getParentComponent();
        if (! $wizard instanceof Wizard) {
            return [];
        }
        $steps = array_values(array_filter($wizard->getChildSchema()->getComponents(), fn ($component) => $component instanceof Step));
        $index = array_search($step, $steps, true);
        if ($index === false) {
            return [];
        }
        $label = $step->getLabel();
        $label = trim(strip_tags($label instanceof Htmlable ? $label->toHtml() : (string) $label));

        return [
            'data-pos-step' => (string) ($index + 1),
            'data-pos-steps' => (string) count($steps),
            'data-pos-label' => e($label),
            'style' => '--pos-step-pct: '.(int) round(($index + 1) * 100 / count($steps)),
        ];
    }
}
