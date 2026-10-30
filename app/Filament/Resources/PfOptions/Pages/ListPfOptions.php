<?php

namespace App\Filament\Resources\PfOptions\Pages;

use App\Domain\Compliance\Models\EmployeePfOption;
use App\Domain\Compliance\Services\PfOptions;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\PfOptions\PfOptionResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PayrollRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListPfOptions extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = PfOptionResource::class;

    public static function registerKey(): string
    {
        return 'pf_options';
    }

    public static function registerReports(): array
    {
        return ['vpf_register'];
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity PO-1: views, report and the register's upload.
            ...$this->registerToolActions(),
            PayrollRegisters::uploads(['pf_options']),
            Action::make('set')->label('Set PF options')->icon(Heroicon::OutlinedBuildingLibrary)
                ->modalDescription('Applies from the date given until a later change. Voluntary PF is deducted on top of the statutory share and counts under 80C.')
                ->schema([
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                    DatePicker::make('effective_from')->label('From')->native(false)->default(now()->startOfMonth())->required(),
                    Select::make('vpf_mode')->label('Voluntary PF')->options(EmployeePfOption::VPF_MODES)->placeholder('None')->live(),
                    TextInput::make('vpf_value')->label(fn (Get $get) => $get('vpf_mode') === 'percent' ? 'Percent of PF wages' : 'Amount a month')
                        ->numeric()->minValue(0.01)->required(fn (Get $get) => filled($get('vpf_mode')))->visible(fn (Get $get) => filled($get('vpf_mode'))),
                    Select::make('wage_basis')->label('Work PF out')->options(EmployeePfOption::WAGE_BASES)->placeholder('As the establishment does'),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        app(PfOptions::class)->set(Employee::query()->findOrFail($data['employee_id']), $data['effective_from'], $data['vpf_mode'] ?: null, isset($data['vpf_value']) ? (float) $data['vpf_value'] : null, $data['wage_basis'] ?: null, $data['reason'], auth()->user());
                        Notification::make()->success()->title('PF options saved')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
