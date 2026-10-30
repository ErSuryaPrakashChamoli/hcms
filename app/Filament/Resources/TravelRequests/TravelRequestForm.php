<?php

namespace App\Filament\Resources\TravelRequests;

use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Services\ExpenseForms;
use App\Domain\Expense\Services\ExpensePolicies;
use App\Domain\Expense\Services\TravelRequests;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Throwable;

/**
 * HROne setup parity EX-F: the "Request travel" form — the trip's purpose and dates, its legs (journeys and stays in the
 * travel categories of the employee's expense policy; who books follows the category, the employee choosing where the
 * category allows either), the travel form fields placed by Setup → Expense and travel forms (labels, visible,
 * mandatory; visa and passport for international travel; custom fields), the delay reason of a backdated trip and an
 * advance. TravelRequests checks everything again on the server.
 */
final class TravelRequestForm
{
    /**
     * @param  Closure(Get): ?Employee  $employee  whose trip this is
     * @return list<Component>
     */
    public static function schema(Closure $employee): array
    {
        $fields = fn (): array => app(ExpenseForms::class)->travelFields();
        $shown = fn (string $key) => fn () => (bool) ($fields()[$key]['visible'] ?? false);
        $needed = fn (string $key) => fn () => (bool) ($fields()[$key]['mandatory'] ?? false);
        $label = fn (string $key, string $default) => fn () => $fields()[$key]['label'] ?? $default;
        $category = fn (mixed $id): ?ExpenseCategory => is_numeric($id) ? ExpenseCategory::query()->find((int) $id) : null;
        $international = fn (Get $get) => (bool) $get('is_international');

        return [
            Section::make('Trip')->columns(2)->schema([
                TextInput::make('purpose')->required()->maxLength(500)->columnSpanFull(),
                DatePicker::make('start_date')->label('Starts on')->native(false)->required()->live(),
                DatePicker::make('end_date')->label('Ends on')->native(false)->required()->afterOrEqual('start_date'),
                Textarea::make('backdated_reason')->label('Reason for the delay (the trip has already started)')->maxLength(500)->rows(2)->columnSpanFull()
                    ->visible(fn (Get $get) => filled($get('start_date')) && CarbonImmutable::parse($get('start_date'))->lt(CarbonImmutable::today()))
                    ->required(fn (Get $get) => filled($get('start_date')) && CarbonImmutable::parse($get('start_date'))->lt(CarbonImmutable::today())
                        && (bool) app(SettingsRepository::class)->get('expense.travel.backdated_reason_required', true)),
            ]),
            Repeater::make('legs')->label('Journeys and stays')->minItems(1)->defaultItems(1)->columns(3)->addActionLabel('Add a journey or stay')
                ->maxItems(fn () => (bool) app(SettingsRepository::class)->get('expense.travel.multi_leg', true) ? 20 : 2)
                ->schema([
                    Select::make('expense_category_id')->label('Travel category')->required()->live()->native(false)->columnSpan(2)
                        ->options(function (Get $get) use ($employee) {
                            $person = $employee($get);

                            return $person === null ? [] : app(ExpensePolicies::class)->categoriesFor($person, $get('../../start_date') ?: today()->toDateString())
                                ->filter(fn (ExpenseCategory $c) => ($c->travel_link ?: 'none') !== 'none' && $c->travel_form !== null)
                                ->mapWithKeys(fn (ExpenseCategory $c) => [$c->id => $c->name.' · '.config('peopleos.expense.travel_forms.'.$c->travel_form, $c->travel_form)])->all();
                        }),
                    Select::make('mode')->options(fn () => (array) config('peopleos.expense.travel_modes'))->native(false)
                        ->visible(fn (Get $get) => $category($get('expense_category_id'))?->travel_form === 'travel')
                        ->required(fn (Get $get) => $category($get('expense_category_id'))?->travel_form === 'travel'),
                    TextInput::make('from_city')->label('From')->maxLength(120)
                        ->visible(fn (Get $get) => $category($get('expense_category_id'))?->travel_form === 'travel')
                        ->required(fn (Get $get) => $category($get('expense_category_id'))?->travel_form === 'travel'),
                    TextInput::make('to_city')->label(fn (Get $get) => $category($get('expense_category_id'))?->travel_form === 'accommodation' ? 'City of the stay' : 'To')->maxLength(120)->required(),
                    Select::make('to_state')->label('State')->options(fn () => (array) config('peopleos.compliance.states'))->searchable()->native(false),
                    DatePicker::make('starts_on')->label(fn (Get $get) => $category($get('expense_category_id'))?->travel_form === 'accommodation' ? 'Check-in' : 'Travel date')->native(false)->required(),
                    DatePicker::make('ends_on')->label(fn (Get $get) => $category($get('expense_category_id'))?->travel_form === 'accommodation' ? 'Check-out' : 'Return (optional)')->native(false)
                        ->required(fn (Get $get) => $category($get('expense_category_id'))?->travel_form === 'accommodation'),
                    TextInput::make('preferred_time')->label('Preferred time (HH:MM)')->maxLength(5),
                    Select::make('booked_by')->label('Who books it')->options(['employee' => 'I book it myself', 'company' => 'The company books it'])->default('employee')->native(false)
                        ->visible(fn (Get $get) => $category($get('expense_category_id'))?->booked_by === 'either'),
                    Textarea::make('notes')->maxLength(500)->rows(1)->columnSpanFull(),
                ]),
            Section::make('Travel details')->columns(3)->schema([
                TextInput::make('currency')->label($label('currency', 'Currency'))->maxLength(3)->default(fn () => app(CurrencyRates::class)->baseCurrency())
                    ->visible($shown('currency'))->required($needed('currency')),
                TextInput::make('estimated_cost')->label($label('estimated_cost', 'Estimated cost'))->numeric()->minValue(0)->visible($shown('estimated_cost'))->required($needed('estimated_cost')),
                Toggle::make('is_international')->label($label('international_travel', 'International travel'))->live()->visible($shown('international_travel')),
                TextInput::make('visa_number')->label($label('visa_number', 'Visa number'))->maxLength(64)
                    ->visible(fn (Get $get) => $shown('visa_number')() && $international($get))->required(fn (Get $get) => $needed('visa_number')() && $international($get)),
                TextInput::make('passport_number')->label($label('passport_number', 'Passport number'))->maxLength(64)
                    ->default(fn (Get $get) => self::lastPassport($employee($get)))
                    ->visible(fn (Get $get) => $shown('passport_number')() && $international($get))->required(fn (Get $get) => $needed('passport_number')() && $international($get)),
                ...self::customFields($shown, $needed),
                FileUpload::make('attachments')->label($label('attachment', 'Attachment'))->multiple()->disk(config('peopleos.documents.disk', 'local'))->directory(fn () => TravelRequests::directory())
                    ->visibility('private')->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize((int) config('peopleos.documents.max_kb', 10240))
                    ->visible($shown('attachment'))->required($needed('attachment'))->columnSpanFull(),
            ]),
            TextInput::make('advance_amount')->label('Advance needed for the trip (in the trip currency)')->numeric()->minValue(0)
                ->visible(fn () => (bool) app(SettingsRepository::class)->get('expense.travel.advance_allowed', true))
                ->helperText('Follows your expense advance policy; it is approved or rejected with the trip.'),
        ];
    }

    /** @return array<string, mixed> the form's state as TravelRequests takes it */
    public static function toData(array $state): array
    {
        $data = $state;
        $data['legs'] = array_values((array) ($state['legs'] ?? []));
        $data['attachments'] = array_values((array) ($state['attachments'] ?? []));

        return $data;
    }

    /** The passport number of the employee's latest international trip, to prefill the form. */
    private static function lastPassport(?Employee $employee): ?string
    {
        if ($employee === null) {
            return null;
        }

        return TravelRequest::query()->where('employee_id', $employee->id)->whereNotNull('passport_number')->latest('id')->first()?->passport_number;
    }

    /** @return list<Component> custom fields of the travel_request entity, placed by the travel form matrix */
    private static function customFields(Closure $shown, Closure $needed): array
    {
        try {
            $definitions = app(CustomFields::class)->definitionsFor(TravelRequest::class);
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
