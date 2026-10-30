<?php

namespace App\Filament\Resources\ExpenseReceipts;

use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseTaxType;
use App\Domain\Expense\Services\ExpenseForms;
use App\Domain\Expense\Services\ExpenseLimits;
use App\Domain\Expense\Services\ExpensePolicies;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Services\TravelRequests;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Throwable;

/**
 * HROne setup parity EX-C: the "Add expense" form. It follows the chosen category — amount or mileage, odometer, from /
 * to places, hours for a daily allowance, taxes, declaration — and the expense form matrix (Setup → Expense and travel
 * forms: which fields show and are mandatory for the category's section, with their labels, including custom fields).
 * The helper under the category shows the limit left. ExpenseReceipts checks everything again on the server.
 */
final class ExpenseReceiptForm
{
    /**
     * @param  Closure(Get): ?Employee  $employee  whose expense this is
     * @return list<Component>
     */
    public static function schema(Closure $employee): array
    {
        $category = fn (Get $get): ?ExpenseCategory => self::category($get('expense_category_id'));
        $section = fn (Get $get): ?string => ($c = $category($get)) ? ($c->travel_form ?: ($c->isMileage() ? 'mileage' : 'amount')) : null;
        $mileage = fn (Get $get) => (bool) $category($get)?->isMileage();
        $fields = fn (): array => app(ExpenseForms::class)->expenseFields();
        $shown = fn (string $key) => fn (Get $get) => ($s = $section($get)) !== null && in_array($s, $fields()[$key]['visible'] ?? [], true);
        $needed = fn (string $key) => fn (Get $get) => ($s = $section($get)) !== null && in_array($s, $fields()[$key]['mandatory'] ?? [], true);
        $label = fn (string $key, string $default) => fn () => $fields()[$key]['label'] ?? $default;

        return [
            DatePicker::make('expense_date')->label('Expense date')->native(false)->maxDate(today())->default(today())->required()->live(),
            Select::make('expense_category_id')->label('Expense category')->required()->live()->native(false)->searchable()
                ->options(function (Get $get) use ($employee) {
                    $person = $employee($get);
                    if ($person === null) {
                        return [];
                    }
                    $on = $get('expense_date') ?: today()->toDateString();

                    return app(ExpensePolicies::class)->categoriesFor($person, $on)->mapWithKeys(fn (ExpenseCategory $c) => [$c->id => $c->displayLabel()])->all();
                })
                ->helperText(fn (Get $get) => self::limitHint($employee($get), $category($get), $get('expense_date'))),
            // EX-F: a travel-linked category points at one of the employee's approved trips (a travel plan one must).
            Select::make('travel_request_id')->label(fn (Get $get) => $category($get)?->travel_link === 'plan' ? 'Travel request (trip)' : 'Travel request (optional)')->native(false)
                ->visible(fn (Get $get) => in_array($category($get)?->travel_link, ['reference', 'plan'], true))
                ->required(fn (Get $get) => $category($get)?->travel_link === 'plan')
                ->options(fn (Get $get) => ($person = $employee($get)) !== null ? app(TravelRequests::class)->choicesFor($person, $category($get), $get('expense_date')) : []),
            Section::make('Amount')->columns(3)->visible(fn (Get $get) => $category($get) !== null)->schema([
                TextInput::make('currency')->maxLength(3)->default(fn () => app(CurrencyRates::class)->baseCurrency())->visible(fn (Get $get) => ! $mileage($get)),
                TextInput::make('amount')->numeric()->minValue(0.01)->visible(fn (Get $get) => ! $mileage($get))->required(fn (Get $get) => ! $mileage($get)),
                TextInput::make('odometer_start')->label('Odometer at start')->numeric()->minValue(0)
                    ->visible(fn (Get $get) => (bool) $category($get)?->odometer_required)->required(fn (Get $get) => (bool) $category($get)?->odometer_required),
                TextInput::make('odometer_end')->label('Odometer at end')->numeric()->minValue(0)
                    ->visible(fn (Get $get) => (bool) $category($get)?->odometer_required)->required(fn (Get $get) => (bool) $category($get)?->odometer_required),
                TextInput::make('distance_km')->label('Distance (km)')->numeric()->minValue(0.1)->visible($mileage)
                    ->required(fn (Get $get) => $mileage($get) && ! $category($get)?->odometer_required)
                    ->helperText(fn (Get $get) => $category($get)?->odometer_required ? 'Leave blank to use the odometer readings.' : null),
                TextInput::make('from_place')->label('From')->maxLength(160)
                    ->visible(fn (Get $get) => ($c = $category($get)) !== null && (($c->isMileage() && $c->location_input !== 'none') || (! $c->isMileage() && $c->from_location_required)))
                    ->required(fn (Get $get) => ($c = $category($get)) !== null && (($c->isMileage() && $c->location_input !== 'none') || (! $c->isMileage() && $c->from_location_required))),
                TextInput::make('to_place')->label('To')->maxLength(160)
                    ->visible(fn (Get $get) => ($c = $category($get)) !== null && $c->isMileage() && $c->location_input !== 'none')
                    ->required(fn (Get $get) => ($c = $category($get)) !== null && $c->isMileage() && $c->location_input !== 'none'),
                TextInput::make('worked_hours')->label('Hours worked that day')->numeric()->minValue(0)->maxValue(24)
                    ->visible(fn (Get $get) => (bool) $category($get)?->hourly_constraints_enabled)
                    ->helperText(fn (Get $get) => $category($get)?->auto_fill_worked_hours ? 'Taken from your attendance when the day is processed.' : null),
            ]),
            Section::make('Details')->columns(2)->visible(fn (Get $get) => $category($get) !== null)->schema([
                TextInput::make('purpose')->label($label('purpose', 'Purpose'))->maxLength(500)->visible($shown('purpose'))->required($needed('purpose')),
                TextInput::make('location_city')->label($label('location', 'Location'))->placeholder('City')->maxLength(120)->visible($shown('location'))->required($needed('location')),
                Select::make('location_state')->label('State')->options(fn () => (array) config('peopleos.compliance.states'))->searchable()->native(false)->visible($shown('location')),
                TextInput::make('merchant')->label($label('merchant', 'Merchant'))->maxLength(160)->visible($shown('merchant'))->required($needed('merchant')),
                TextInput::make('invoice_number')->label($label('invoice_number', 'Invoice number'))->maxLength(64)->visible($shown('invoice_number'))->required($needed('invoice_number')),
                TagsInput::make('participants')->label($label('participants', 'Other participants'))->visible($shown('participants'))->required($needed('participants'))->columnSpanFull(),
                ...self::customFields($shown, $needed),
                Textarea::make('description')->maxLength(5000)->rows(2)->required(fn (Get $get) => (bool) $category($get)?->description_required)->columnSpanFull(),
            ]),
            Section::make('Tax')->visible(fn (Get $get) => (bool) $category($get)?->tax_applicable)->schema([
                TextInput::make('supplier_gstin')->label('Supplier GST registration number')->maxLength(15),
                Repeater::make('taxes')->label('Tax charged (as printed on the receipt)')->defaultItems(0)->columns(3)->addActionLabel('Add a tax')->schema([
                    Select::make('expense_tax_type_id')->label('Tax')->required()->native(false)
                        ->options(fn () => ExpenseTaxType::query()->where('status', ActiveStatus::Active)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')),
                    TextInput::make('rate')->label('Rate %')->numeric()->minValue(0)->maxValue(100),
                    TextInput::make('amount')->numeric()->minValue(0)->required(),
                ]),
            ]),
            FileUpload::make('attachments')->label('Receipts and photos')->multiple()->disk(config('peopleos.documents.disk', 'local'))->directory(fn () => ExpenseReceipts::directory())->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize((int) config('peopleos.documents.max_kb', 10240))
                ->visible(fn (Get $get) => $category($get) !== null)
                ->helperText(fn (Get $get) => $category($get)?->odometer_required ? 'Attach the two odometer photos (start and end).' : null),
            Textarea::make('exceptional_reason')->label('Reason for an exceptional expense')->maxLength(500)->rows(2)
                ->visible(fn () => (bool) app(SettingsRepository::class)->get('expense.exceptional.enabled', false))
                ->helperText('Over the limit (where the category allows it) or in an exceptional-only category: the expense is filed as exceptional and approved on its own.'),
            Checkbox::make('declaration_accepted')->label(fn (Get $get) => (string) $category($get)?->declaration_text)
                ->visible(fn (Get $get) => (bool) $category($get)?->declaration_required)->accepted(fn (Get $get) => (bool) $category($get)?->declaration_required),
        ];
    }

    /**
     * The form's state as ExpenseReceipts takes it.
     *
     * @return array<string, mixed>
     */
    public static function toData(array $state): array
    {
        $data = $state;
        if (is_numeric($state['worked_hours'] ?? null)) {
            $data['worked_minutes'] = (int) round((float) $state['worked_hours'] * 60);
        }
        $data['attachments'] = array_values((array) ($state['attachments'] ?? []));
        $data['taxes'] = array_values((array) ($state['taxes'] ?? []));
        unset($data['worked_hours']);

        return $data;
    }

    /** @return array<string, mixed> a receipt as the form shows it */
    public static function fromReceipt(ExpenseReceipt $receipt): array
    {
        return [
            'expense_date' => $receipt->expense_date?->toDateString(), 'expense_category_id' => $receipt->expense_category_id, 'travel_request_id' => $receipt->travel_request_id, 'currency' => $receipt->currency,
            'amount' => $receipt->type === 'amount' ? (float) $receipt->amount : null, 'distance_km' => $receipt->distance_km !== null ? (float) $receipt->distance_km : null,
            'odometer_start' => $receipt->odometer_start, 'odometer_end' => $receipt->odometer_end, 'from_place' => $receipt->from_place, 'to_place' => $receipt->to_place,
            'worked_hours' => $receipt->worked_minutes !== null ? round($receipt->worked_minutes / 60, 2) : null,
            'purpose' => $receipt->purpose, 'location_city' => $receipt->location_city, 'location_state' => $receipt->location_state, 'merchant' => $receipt->merchant,
            'invoice_number' => $receipt->invoice_number, 'participants' => $receipt->participants ?? [], 'description' => $receipt->description,
            'supplier_gstin' => $receipt->supplier_gstin, 'taxes' => $receipt->taxes()->get()->map(fn ($t) => ['expense_tax_type_id' => $t->expense_tax_type_id, 'rate' => $t->rate, 'amount' => (float) $t->amount])->all(),
            'attachments' => $receipt->attachments ?? [], 'exceptional_reason' => $receipt->exception_reason, 'declaration_accepted' => $receipt->declaration_accepted_at !== null,
            'custom' => $receipt->customFields(),
        ];
    }

    /** "Left today: 1,100.00 of 1,500.00 · receipt needed above 500.00" for the chosen category. */
    public static function limitHint(?Employee $employee, ?ExpenseCategory $category, mixed $date): ?string
    {
        if ($employee === null || $category === null) {
            return null;
        }
        try {
            $limit = app(ExpenseLimits::class)->for($employee, $category, CarbonImmutable::parse($date ?: today()));
        } catch (Throwable) {
            return null;
        }
        if (! $limit->allowed) {
            return $limit->refusal;
        }
        $parts = [];
        if ($limit->limit !== null) {
            $unit = $limit->unit === 'km' ? ' km' : '';
            $parts[] = 'Limit '.number_format((float) $limit->limit, 2).$unit.' '.strtolower((string) config('peopleos.expense.periods.'.$limit->period, $limit->period)).', '.number_format((float) $limit->remaining, 2).$unit.' left';
        }
        if ($limit->frequency !== null) {
            $parts[] = $limit->frequency['remaining'].' of '.$limit->frequency['count'].' claim(s) left';
        }
        if ($limit->receiptRequired) {
            $parts[] = $limit->receiptRequiredAbove !== null ? 'receipt needed above '.number_format($limit->receiptRequiredAbove, 2) : 'receipt needed';
        }
        if ($category->isMileage()) {
            $parts[] = number_format((float) $category->rate_per_km, 2).' per km';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** The employee whose expense the current user files (their own). */
    public static function ownEmployee(): ?Employee
    {
        return auth()->id() ? Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', auth()->id())->first() : null;
    }

    private static function category(mixed $id): ?ExpenseCategory
    {
        if (blank($id) || ! is_numeric($id)) {
            return null;
        }

        return ExpenseCategory::query()->find((int) $id);
    }

    /** @return list<Component> custom fields of the expense_receipt entity, placed by the form matrix */
    private static function customFields(Closure $shown, Closure $needed): array
    {
        try {
            $definitions = app(CustomFields::class)->definitionsFor(ExpenseReceipt::class);
        } catch (Throwable) {
            return [];
        }

        return $definitions->map(function (CustomField $field) use ($shown, $needed) {
            $key = ExpenseForms::CUSTOM.$field->key;
            $name = 'custom.'.$field->key;
            $component = match ($field->type) {
                'textarea' => Textarea::make($name)->rows(2),
                'number' => TextInput::make($name)->numeric(),
                'date' => DatePicker::make($name)->native(false),
                'dropdown' => Select::make($name)->options($field->optionMap()),
                'multiselect' => Select::make($name)->options($field->optionMap())->multiple(),
                default => TextInput::make($name)->maxLength(255),
            };

            return $component->label($field->label)->helperText($field->help_text)->visible($shown($key))->required($needed($key));
        })->values()->all();
    }
}
