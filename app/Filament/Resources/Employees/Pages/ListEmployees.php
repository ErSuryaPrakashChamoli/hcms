<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\Url;

class ListEmployees extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = EmployeeResource::class;

    /** HROne parity WO-1 / WO-2: what the Employees register can do in bulk (selected rows or all matching). */
    public const BULK_OPERATIONS = ['employees.initiate_exit', 'employees.start_onboarding', 'employees.initiate_bgv', 'employees.send_email'];

    /** HROne parity WO-1 (§23.1 #10–11): the card layout and the fields a card shows (kept with a saved view). */
    public const CARD_FIELDS = [
        'joining_date' => 'Date of joining', 'manager' => 'Reporting manager', 'employment_type' => 'Employment type', 'status' => 'Status',
        'company' => 'Company', 'location' => 'Location', 'work_email' => 'Work e-mail',
    ];

    public const DEFAULT_CARD_FIELDS = ['joining_date', 'manager', 'employment_type', 'status'];

    /** table (default) | cards */
    #[Url(as: 'layout')]
    public string $posLayout = 'table';

    /** @var list<string> */
    public array $posCardFields = self::DEFAULT_CARD_FIELDS;

    public static function registerKey(): string
    {
        return 'employees';
    }

    public static function presetViews(): array
    {
        return [
            'new_hires' => ['label' => 'New hires (not joined yet)', 'filters' => ['lifecycle_state' => ['values' => ['pre_employee', 'preboarding', 'onboarding']]]],
            'probation' => ['label' => 'On probation', 'filters' => ['lifecycle_state' => ['values' => ['probation']]]],
            'notice' => ['label' => 'Serving notice', 'filters' => ['lifecycle_state' => ['values' => ['notice_period']]]],
            'without_bgv' => ['label' => 'Without a background check', 'filters' => ['background_check' => ['value' => 'none'], 'exclude_pending_resignation' => ['isActive' => true]]],
            'exited' => ['label' => 'Exited and alumni', 'filters' => ['lifecycle_state' => ['values' => ['exited', 'alumni']]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['employee_master', 'employee_directory', 'headcount_department', 'new_joiners_this_month', 'upcoming_joiners', 'probation_due', 'employees_without_manager', 'exited_this_fy'];
    }

    public static function registerBulkOperations(): array
    {
        return self::BULK_OPERATIONS;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            $this->layoutAction(),
            // The employees the list shows (filters and search applied), in the import template format.
            Action::make('exportEmployees')
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => EmployeeImports::enabled() && auth()->user()->can('create', EmployeeImport::class))
                ->action(function () {
                    $csv = app(EmployeeImports::class)->export($this->getFilteredTableQuery(), auth()->user());

                    return response()->streamDownload(fn () => print ($csv), 'employees-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
                }),
            CreateAction::make()->label('Hire employee'),
        ];
    }

    /** Cards (with the chosen fields) or the table. */
    public function table(Table $table): Table
    {
        $table = parent::table($table);
        if ($this->posLayout !== 'cards') {
            return $table;
        }
        $fields = array_values(array_intersect(array_keys(self::CARD_FIELDS), $this->posCardFields));

        return $table->contentGrid(['md' => 2, 'xl' => 3])->columns([
            Stack::make([
                TextColumn::make('person.display_name')->label('Name')->weight('bold')->searchable(query: EmployeeFilters::search())
                    ->description(fn (Employee $record) => '#'.$record->employee_code),
                TextColumn::make('card_role')->label('Role')->color('gray')
                    ->state(fn (Employee $record) => collect([$record->currentPosition?->designation?->name, $record->currentPosition?->department?->name])->filter()->implode(' · ') ?: null),
                ...array_map(fn (string $field) => $this->cardColumn($field), $fields),
            ])->space(1),
        ]);
    }

    private function cardColumn(string $field): TextColumn
    {
        $label = self::CARD_FIELDS[$field];

        return match ($field) {
            'joining_date' => TextColumn::make('joining_date')->date()->prefix($label.': ')->placeholder($label.': —'),
            'manager' => TextColumn::make('currentManager.manager.person.display_name')->prefix($label.': ')->placeholder($label.': not available'),
            'employment_type' => TextColumn::make('currentPosition.employmentType.name')->prefix($label.': ')->placeholder($label.': —'),
            'status' => TextColumn::make('lifecycle_state')->badge(),
            'company' => TextColumn::make('currentPosition.company.name')->prefix($label.': ')->placeholder($label.': —'),
            'location' => TextColumn::make('currentPosition.location.name')->prefix($label.': ')->placeholder($label.': —'),
            default => TextColumn::make('work_email')->prefix($label.': ')->placeholder($label.': —'),
        };
    }

    private function layoutAction(): Action
    {
        return Action::make('posLayout')
            ->label(fn () => $this->posLayout === 'cards' ? 'Table' : 'Cards')
            ->icon(fn () => $this->posLayout === 'cards' ? Heroicon::OutlinedTableCells : Heroicon::OutlinedSquares2x2)
            ->color('gray')
            ->modalHeading('Cards')
            ->modalDescription('Show the employees as cards, with the fields you choose.')
            ->modalSubmitActionLabel('Show cards')
            ->fillForm(fn () => ['fields' => $this->posCardFields])
            ->schema(fn () => $this->posLayout === 'cards' ? [] : [CheckboxList::make('fields')->label('Fields on each card')->options(self::CARD_FIELDS)->columns(2)])
            ->modalHidden(fn () => $this->posLayout === 'cards')
            ->action(function (array $data) {
                if ($this->posLayout === 'cards') {
                    $this->posLayout = 'table';
                } else {
                    $this->posLayout = 'cards';
                    $this->posCardFields = array_values(array_intersect(array_keys(self::CARD_FIELDS), (array) ($data['fields'] ?? self::DEFAULT_CARD_FIELDS)));
                }
                $this->rebuildRegisterTable();
            });
    }
}
