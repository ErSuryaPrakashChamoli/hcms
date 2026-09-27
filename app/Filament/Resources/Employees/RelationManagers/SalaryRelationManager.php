<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Payroll\Models\EmployeeSalaryAssignment;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Services\Salaries;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Salary history on the Employee 360 (§70). Sensitive: opening the tab is audited. */
class SalaryRelationManager extends RelationManager
{
    protected static string $relationship = 'salaryAssignments';

    protected static ?string $title = 'Salary';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('viewSensitive', $ownerRecord) && ($user->can('payroll.view') || $user->can('payroll.manage'));
    }

    public function mount(): void
    {
        app(SensitiveAccessAuditor::class)->recordView($this->getOwnerRecord(), 'salary');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['structure', 'creator']))
            ->columns([
                TextColumn::make('effective_from')->label('From')->date()->sortable(),
                TextColumn::make('effective_to')->label('To')->date()->placeholder('Current'),
                TextColumn::make('change_type')->badge()->color('gray')->formatStateUsing(fn (string $state) => EmployeeSalaryAssignment::CHANGE_TYPES[$state] ?? $state),
                TextColumn::make('structure.name')->label('Structure'),
                TextColumn::make('ctc_annual')->label('Annual CTC')->numeric(2),
                TextColumn::make('monthly')->label('Monthly')->state(fn (EmployeeSalaryAssignment $record) => $record->monthlyCtc())->numeric(2),
                TextColumn::make('reason')->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('creator.name')->label('By')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('effective_from', 'desc')
            ->headerActions([
                Action::make('assign')->label('Assign / revise salary')->icon('heroicon-m-banknotes')
                    ->visible(fn () => auth()->user()->can('payroll.manage'))
                    ->schema([
                        Select::make('salary_structure_id')->label('Structure')->required()->options(fn () => SalaryStructure::query()->where('status', 'active')->pluck('name', 'id')->all())
                            ->default(fn () => $this->getOwnerRecord()->salaryAssignments()->orderByDesc('effective_from')->value('salary_structure_id')),
                        TextInput::make('ctc_annual')->label('Annual CTC')->numeric()->required()->minValue(1),
                        DatePicker::make('effective_from')->native(false)->required()->default(now()->startOfMonth()),
                        Select::make('change_type')->options(EmployeeSalaryAssignment::CHANGE_TYPES)->default('revision')->required(),
                        KeyValue::make('component_values')->label('Fixed component amounts (monthly)')->keyLabel('Component code')->valueLabel('Amount')->helperText('For components calculated as "fixed", e.g. CONV = 1600'),
                        Textarea::make('reason')->required()->maxLength(255),
                    ])
                    ->action(function (array $data) {
                        try {
                            app(Salaries::class)->assign($this->getOwnerRecord(), SalaryStructure::query()->findOrFail($data['salary_structure_id']), (float) $data['ctc_annual'], $data['effective_from'], $data['component_values'] ?? [], $data['change_type'], $data['reason'], auth()->user());
                            Notification::make()->success()->title('Salary assigned')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Cannot assign')->body($e->getMessage())->persistent()->send();
                        }
                    }),
            ]);
    }
}
