<?php

namespace App\Filament\Resources\EmployeeContracts\Schemas;

use App\Domain\Partners\Models\Vendor;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\WorkforceBulkWizards;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** The contract of a contract staff member, in two steps: who and through which agency, then the contract. */
class EmployeeContractForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Employee and agency')
                    ->columns(2)
                    ->schema([
                        WorkforceBulkWizards::employeeSelect('employee_id', 'Employee', false)->required()->disabledOn('edit'),
                        Select::make('vendor_id')->label('Agency (vendor)')->searchable()->placeholder('None (contracted directly)')
                            ->options(fn () => Vendor::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn ($value) => Vendor::query()->find((int) $value)?->name)
                            ->helperText('Vendors are kept on Workforce → Vendors.'),
                    ]),
                Section::make('Contract')
                    ->columns(2)
                    ->schema([
                        TextInput::make('contract_number')->label('Contract number')->required()->maxLength(64),
                        DatePicker::make('start_date')->label('Starts on')->native(false)->required(),
                        DatePicker::make('end_date')->label('Ends on')->native(false)->afterOrEqual('start_date')->helperText('Blank while it is open-ended.'),
                        Textarea::make('notes')->rows(2)->maxLength(1000)->columnSpanFull(),
                    ]),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}
