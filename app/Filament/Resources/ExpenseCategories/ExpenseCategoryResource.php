<?php

namespace App\Filament\Resources\ExpenseCategories;

use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Expense\Models\ExpenseLocationGroup;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Filament\Resources\ExpenseCategories\Pages\CreateExpenseCategory;
use App\Filament\Resources\ExpenseCategories\Pages\EditExpenseCategory;
use App\Filament\Resources\ExpenseCategories\Pages\ListExpenseCategories;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne setup parity EX-A (Expense → Expense category): amount and mileage categories with every rule of HROne's category
 * form, asked as HROne asks them. Saved through ExpenseCategories, which checks the rules on the server. Categories are
 * inactivated, never deleted.
 */
class ExpenseCategoryResource extends Resource
{
    protected static ?string $model = ExpenseCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $navigationLabel = 'Expense categories';

    protected static ?string $modelLabel = 'expense category';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('expense.manage') || auth()->user()?->can('expense.view');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('group');
    }

    public static function form(Schema $schema): Schema
    {
        $vocabulary = (array) config('peopleos.expense');
        $type = fn (Get $get): string => (string) ($get('type') ?: ExpenseCategory::AMOUNT);
        $yes = fn (string $key) => fn (Get $get): bool => (bool) $get($key);
        $basis = fn (Get $get): string => (string) ($get('limit_basis') ?: 'none');

        return $schema->columns(1)->components([
            Section::make('Expense type')->compact()->schema([
                Radio::make('type')->label('Expense type')->options($vocabulary['types'])->inline()->required()->live()->default(ExpenseCategory::AMOUNT)
                    ->disabled(fn (string $operation) => $operation === 'edit')->dehydrated()
                    ->afterStateUpdated(fn (Set $set) => $set('limit_basis', 'none'))
                    ->helperText('Mileage: distance × rate per km (e.g. 2-wheeler, 4-wheeler as separate categories). Amount: the amount spent. Fixed once the category exists.'),
            ]),
            Section::make('Expense info')->columns(2)->schema([
                TextInput::make('name')->label('Expense category name')->required()->maxLength(120),
                Select::make('expense_group_id')->label('Expense group')->placeholder('No group')
                    ->options(fn () => ExpenseGroup::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('code')->label('Expense code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated()
                    ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
                Textarea::make('description')->label('Category description')->rows(2)->maxLength(2000)->columnSpanFull(),
            ]),
            Section::make('Mileage details')->columns(2)->visible(fn (Get $get) => $type($get) === ExpenseCategory::MILEAGE)->schema([
                TextInput::make('rate_per_km')->label('Rate per KM')->numeric()->minValue(0.0001)->required(fn (Get $get) => $type($get) === ExpenseCategory::MILEAGE),
                Select::make('location_input')->label('Location input type')->options($vocabulary['location_inputs'])->default('none')->required(),
                Radio::make('odometer_required')->label('Do you want to calculate distance as per odometer?')->boolean()->inline()->default(false)
                    ->helperText('Yes: the employee gives the opening and closing readings with two photos; the distance is the difference.')->columnSpanFull(),
            ]),
            Section::make('Limit details')->columns(2)->schema([
                Select::make('limit_basis')->label(fn (Get $get) => $type($get) === ExpenseCategory::MILEAGE ? 'Do you want to specify distance limit (KM)?' : 'Choose type')
                    ->options(fn (Get $get) => $vocabulary['limit_bases'][$type($get)])->default('none')->required()->live(),
                Select::make('limit_dimension')->label('Limits for employees by')->placeholder('Everyone alike')->options(ExpenseCategories::dimensions())->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => $set('limits', array_map(fn (array $row) => ['dimension_value_id' => null] + $row, (array) $get('limits'))))
                    ->visible(fn (Get $get) => $basis($get) !== 'none'),
                Select::make('ctc_component_id')->label('Percentage of')->placeholder('Total CTC')
                    ->options(fn () => SalaryComponent::query()->where('type', 'earning')->where('status', 'active')->orderBy('sort_order')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->name} ({$c->code})"])->all())
                    ->helperText('The yearly amount on the expense date, from the employee\'s approved compensation.')
                    ->visible(fn (Get $get) => $basis($get) === 'percent_of_ctc'),
                Repeater::make('limits')->label('Limits')->columns(4)->columnSpanFull()->addActionLabel('Add a limit')->defaultItems(1)
                    ->helperText('The most specific row applies: the employee\'s value and the place\'s location group, then the employee\'s value anywhere, then everyone in the location group, then everyone everywhere.')
                    ->visible(fn (Get $get) => $basis($get) !== 'none')
                    ->schema([
                        Select::make('dimension_value_id')->label('For employees')->placeholder('Every other employee')
                            ->options(fn (Get $get) => ExpenseCategories::dimensionOptions($get('../../limit_dimension')))
                            ->visible(fn (Get $get) => filled($get('../../limit_dimension'))),
                        Select::make('expense_location_group_id')->label('Location group')->placeholder('Every other place')
                            ->options(fn () => ExpenseLocationGroup::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all()),
                        TextInput::make('amount')->label('Specify limit amount')->numeric()->minValue(0)
                            ->visible(fn (Get $get) => $get('../../limit_basis') === 'amount')->required(fn (Get $get) => $get('../../limit_basis') === 'amount'),
                        TextInput::make('percent')->label('Percentage (%)')->numeric()->minValue(0.01)->maxValue(100)
                            ->visible(fn (Get $get) => $get('../../limit_basis') === 'percent_of_ctc')->required(fn (Get $get) => $get('../../limit_basis') === 'percent_of_ctc'),
                        TextInput::make('km_limit')->label('Specify limit (KM)')->numeric()->minValue(0.01)
                            ->visible(fn (Get $get) => $get('../../limit_basis') === 'km')->required(fn (Get $get) => $get('../../limit_basis') === 'km'),
                        Select::make('period')->label('Limit time period')->options($vocabulary['periods'])->default('per_claim')->required(),
                    ]),
                Radio::make('limit_exception_allowed')->label('Do you want user to file expense as exceptional when out of limit?')->boolean()->inline()->default(false)
                    ->helperText('Needs exceptional expenses switched on (Setup → Expense). Otherwise a claim is capped at the limit.')
                    ->visible(fn (Get $get) => $basis($get) !== 'none')->columnSpanFull(),
                Radio::make('hourly_constraints_enabled')->label('Do you want to set hourly constraints for daily allowance?')->boolean()->inline()->default(false)->live()
                    ->visible(fn (Get $get) => $type($get) === ExpenseCategory::AMOUNT)->columnSpanFull(),
                Repeater::make('hourly_constraints')->label('Hourly bands')->columns(5)->columnSpanFull()->addActionLabel('Add a band')->defaultItems(1)
                    ->helperText('The limit for a day is this percentage of the limit, for the band holding the hours worked (or travelled). Hours outside every band get nothing.')
                    ->visible(fn (Get $get) => $type($get) === ExpenseCategory::AMOUNT && (bool) $get('hourly_constraints_enabled'))
                    ->schema([
                        TextInput::make('from_hours')->label('From hours')->numeric()->minValue(0)->required(),
                        TextInput::make('from_minutes')->label('Minutes')->numeric()->minValue(0)->maxValue(59)->default(0),
                        TextInput::make('to_hours')->label('To hours')->numeric()->minValue(0)->required(),
                        TextInput::make('to_minutes')->label('Minutes')->numeric()->minValue(0)->maxValue(59)->default(0),
                        TextInput::make('percent')->label('Percentage')->numeric()->minValue(0)->maxValue(100)->required(),
                    ]),
                Radio::make('auto_fill_worked_hours')->label('Do you want the system to auto-fill employee\'s working hours?')->boolean()->inline()->default(false)
                    ->visible(fn (Get $get) => $type($get) === ExpenseCategory::AMOUNT && (bool) $get('hourly_constraints_enabled'))->columnSpanFull(),
            ]),
            Section::make('Claim rules')->columns(2)->schema([
                Toggle::make('description_required')->label('Description required'),
                Toggle::make('reimbursable')->label('Reimbursable')->default(true)->helperText('Off: company-paid (booked tickets, corporate card) — recorded and approved, never paid to the employee.'),
                Toggle::make('receipt_required')->label('Receipt required')->live(),
                TextInput::make('receipt_required_above')->label('Min expense limit for receipt')->numeric()->minValue(0)
                    ->helperText('Blank: every claim needs a receipt.')->visible($yes('receipt_required')),
                Select::make('claim_frequency_period')->label('Claim frequency')->placeholder('Any number of claims')
                    ->options(array_diff_key($vocabulary['periods'], ['per_claim' => true]))->live(),
                TextInput::make('claim_frequency_count')->label('Claim frequency count')->numeric()->minValue(1)
                    ->visible(fn (Get $get) => filled($get('claim_frequency_period')))->required(fn (Get $get) => filled($get('claim_frequency_period'))),
            ]),
            Section::make('GST')->columns(2)->visible(fn (Get $get) => $type($get) === ExpenseCategory::AMOUNT)->schema([
                Toggle::make('tax_applicable')->label('Is tax applicable')->live()->helperText('Yes: the receipt takes tax lines (Expense → Tax), the supplier\'s tax number and the invoice number.'),
                Radio::make('gst_supply_type')->label('GST type')->options($vocabulary['gst_supply_types'])->inline()->visible($yes('tax_applicable')),
            ]),
            Section::make('Travel details')->columns(2)->schema([
                Radio::make('travel_link')->label('Do you want to link it with Pre-travel request?')->options($vocabulary['travel_links'])->default('none')->required()->live()->columnSpanFull(),
                Radio::make('travel_form')->label('Link travel plan with')->options($vocabulary['travel_forms'])->inline()
                    ->visible(fn (Get $get) => ($get('travel_link') ?: 'none') !== 'none')->required(fn (Get $get) => ($get('travel_link') ?: 'none') !== 'none')->columnSpanFull(),
                Radio::make('booking_task')->label('Do you want to assign a task to a travel booking agent through helpdesk?')->boolean()->inline()->default(false)->live()
                    ->visible(fn (Get $get) => ($get('travel_link') ?: 'none') !== 'none')->columnSpanFull(),
                Select::make('booking_service_id')->label('Sub category (helpdesk service)')
                    ->options(fn () => ServiceDefinition::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (Get $get) => ($get('travel_link') ?: 'none') !== 'none' && (bool) $get('booking_task'))->required(fn (Get $get) => (bool) $get('booking_task')),
                Radio::make('booked_by')->label('Who is responsible to book travel for this category?')->options($vocabulary['booked_by'])->default('employee')
                    ->visible(fn (Get $get) => ($get('travel_link') ?: 'none') !== 'none')->columnSpanFull(),
            ]),
            Section::make('Eligibility')->columns(2)->schema([
                Radio::make('before_joining_allowed')->label('Do you want to allow the user to file expenses before the date of joining?')->boolean()->inline()->default(false)->columnSpanFull(),
                Radio::make('attendance_restricted')->label('Do you want to restrict the employee to apply expenses based on their attendance?')->boolean()->inline()->default(false)->live()->columnSpanFull(),
                Radio::make('attendance_mode')->label('The employee must be')->options($vocabulary['attendance_modes'])->live()
                    ->visible($yes('attendance_restricted'))->required($yes('attendance_restricted'))->columnSpanFull(),
                TextInput::make('attendance_min_hours')->label('Minimum working hours')->numeric()->minValue(0)->maxValue(24)
                    ->visible(fn (Get $get) => (bool) $get('attendance_restricted') && $get('attendance_mode') === 'min_hours'),
                TextInput::make('attendance_min_minutes')->label('Minutes')->numeric()->minValue(0)->maxValue(59)
                    ->visible(fn (Get $get) => (bool) $get('attendance_restricted') && $get('attendance_mode') === 'min_hours'),
                Radio::make('attendance_on_duty')->label('Select on duty type')->options($vocabulary['on_duty_kinds'])->inline()->live()
                    ->visible(fn (Get $get) => (bool) $get('attendance_restricted') && $get('attendance_mode') === 'on_duty')->columnSpanFull(),
                TagsInput::make('attendance_on_duty_types')->label('Select on duty')->placeholder('The on-duty types of your time-office policy, e.g. Client visit')
                    ->visible(fn (Get $get) => (bool) $get('attendance_restricted') && $get('attendance_mode') === 'on_duty' && $get('attendance_on_duty') === 'specific')->columnSpanFull(),
                Radio::make('from_location_required')->label('Do you also want from location to be captured?')->boolean()->inline()->default(false)
                    ->visible(fn (Get $get) => $type($get) === ExpenseCategory::AMOUNT)->columnSpanFull(),
                Radio::make('tenure_enabled')->label('Do you want to set a tenure for expense submission?')->boolean()->inline()->default(false)->live()
                    ->helperText('A claim can be filed only between these many days after the expense date.')->columnSpanFull(),
                TextInput::make('tenure_from_days')->label('From days')->numeric()->minValue(0)->visible($yes('tenure_enabled'))->required($yes('tenure_enabled')),
                TextInput::make('tenure_to_days')->label('To days')->numeric()->minValue(1)->gt('tenure_from_days')->visible($yes('tenure_enabled'))->required($yes('tenure_enabled'))
                    ->validationMessages(['gt' => '"To Days" must be greater than "From Days".']),
            ]),
            Section::make('Declaration')->schema([
                Radio::make('declaration_required')->label('Do you want a declaration on category?')->boolean()->inline()->default(false)->live(),
                Textarea::make('declaration_text')->label('Declaration')->rows(3)->maxLength(2000)->placeholder('Write declaration statement here...')
                    ->visible($yes('declaration_required'))->required($yes('declaration_required')),
            ]),
            Section::make('Status')->columns(2)->schema([
                TextInput::make('sort_order')->numeric()->minValue(0)->default(0),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required()
                    ->helperText('Inactive categories stay on earlier claims and policies but cannot be chosen.'),
                AuditReasonField::make()->visibleOn('edit'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('What employees can claim, by amount or by mileage, with limits, receipts and other rules. Expense policies choose the categories each group of employees can claim.')
            ->columns([
                TextColumn::make('name')->label('Expense category')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => strtoupper((string) config("peopleos.expense.types.{$state}", $state))),
                TextColumn::make('group.name')->label('Expense group')->placeholder('—'),
                TextColumn::make('limit_basis')->label('Limit')->formatStateUsing(fn (string $state, ExpenseCategory $record) => (string) (config('peopleos.expense.limit_bases.'.$record->getAttributes()['type'])[$state] ?? $state)),
                IconColumn::make('reimbursable')->boolean(),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('type')->options(config('peopleos.expense.types')),
                SelectFilter::make('expense_group_id')->label('Expense group')->relationship('group', 'name'),
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseCategories::route('/'),
            'create' => CreateExpenseCategory::route('/create'),
            'edit' => EditExpenseCategory::route('/{record}/edit'),
        ];
    }
}
