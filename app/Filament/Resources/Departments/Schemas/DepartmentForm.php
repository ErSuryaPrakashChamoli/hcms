<?php

namespace App\Filament\Resources\Departments\Schemas;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Department;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\CustomFieldsSchema;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DepartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        Select::make('company_id')->relationship('company', 'name')->searchable()->preload(),
                        // HROne setup parity CA-2 (Sub department): the department this one sits under.
                        Select::make('parent_department_id')->label('Parent department')->placeholder('None (a top-level department)')->searchable()
                            ->options(fn (?Department $record) => Department::query()->when($record?->exists, fn ($q) => $q->whereKeyNot($record->getKey()))->orderBy('name')->pluck('name', 'id')->all())
                            ->helperText('Makes this a sub-department. Policies and reports can use either level.'),
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('code')->required()->maxLength(32)->alphaDash()->helperText('Short unique code.'),
                        Select::make('cost_centre_id')->relationship('costCentre', 'name')->label('Cost centre')->searchable()->preload(),
                        Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
                        Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                    ]),
                Section::make('Effective dates')
                    ->description('Leave blank for open-ended. History is never overwritten.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('effective_from')->native(false),
                        DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
                    ]),
                CustomFieldsSchema::formSection(Department::class),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}
