<?php

namespace App\Filament\Pages;

use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Services\ExpenseForms;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * HROne setup parity EX-A (Expense → Expense form, Travel plan form): "Expense form settings let you change label names
 * and decide if they're required or optional." One page, two tabs: the expense form (each field visible and mandatory
 * on amount, mileage, accommodation and travel details, with its label) and the travel form (visible, mandatory,
 * label). Custom fields of the expense receipt and travel request entities ("Field one … ten") are listed with them.
 * Saved through ExpenseForms (audited settings).
 */
class ExpenseFormSetup extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $navigationLabel = 'Expense and travel forms';

    protected static ?string $title = 'Expense and travel forms';

    protected static ?string $slug = 'expense-form-setup';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.expense-form-setup';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('expense.manage') || $user->can('expense.view'));
    }

    public static function canEdit(): bool
    {
        return auth()->user()?->can('expense.manage') ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Change field labels and decide where each field is shown and whether it is mandatory. Add more fields (Field one … ten) as custom fields of the expense receipt or travel request.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->fillForm();
    }

    public function form(Schema $schema): Schema
    {
        $sections = ExpenseForms::sections();
        $editable = static::canEdit();
        $label = fn (array $state) => $state['default_label'] ?? null;

        return $schema->statePath('data')->components([
            Tabs::make('forms')->tabs([
                Tab::make('Expense form')->schema([
                    Repeater::make('expense')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->columns(2)->itemLabel($label)
                        ->schema([
                            Hidden::make('key'),
                            Hidden::make('default_label'),
                            Hidden::make('custom'),
                            TextInput::make('label')->label('Rename label')->maxLength(60)->disabled(fn ($get) => (bool) $get('custom') || ! $editable)
                                ->helperText(fn ($get) => (bool) $get('custom') ? 'A custom field: rename it under Custom fields.' : null)->columnSpanFull(),
                            CheckboxList::make('visible')->label('Visible on')->options($sections)->columns(4)->disabled(! $editable),
                            CheckboxList::make('mandatory')->label('Mandatory on')->options($sections)->columns(4)->disabled(! $editable),
                        ]),
                ]),
                Tab::make('Travel form')->schema([
                    Repeater::make('travel')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->columns(3)->itemLabel($label)
                        ->schema([
                            Hidden::make('key'),
                            Hidden::make('default_label'),
                            Hidden::make('custom'),
                            TextInput::make('label')->label('Rename label')->maxLength(60)->disabled(fn ($get) => (bool) $get('custom') || ! $editable)
                                ->helperText(fn ($get) => (bool) $get('custom') ? 'A custom field: rename it under Custom fields.' : null),
                            Toggle::make('visible')->label('Visible on travel form')->disabled(! $editable),
                            Toggle::make('mandatory')->label('Mandatory on travel form')->disabled(! $editable),
                        ]),
                ]),
            ]),
            Section::make('Save')->compact()->visible($editable)->schema([AuditReasonField::make()]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canEdit(), 403);
        $state = $this->form->getState();
        $reason = AuditReasonField::extract($state) ?? '';
        try {
            $forms = app(ExpenseForms::class);
            $forms->saveExpense(array_values((array) ($state['expense'] ?? [])), $reason, auth()->user());
            $forms->saveTravel(array_values((array) ($state['travel'] ?? [])), $reason, auth()->user());
        } catch (ExpenseRuleViolation $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title('Expense and travel forms saved')->send();
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $forms = app(ExpenseForms::class);
        $this->form->fill([
            'expense' => array_values(array_map(fn (array $f) => array_intersect_key($f, array_flip(['key', 'label', 'default_label', 'visible', 'mandatory', 'custom'])), $forms->expenseFields())),
            'travel' => array_values(array_map(fn (array $f) => array_intersect_key($f, array_flip(['key', 'label', 'default_label', 'visible', 'mandatory', 'custom'])), $forms->travelFields())),
        ]);
    }
}
