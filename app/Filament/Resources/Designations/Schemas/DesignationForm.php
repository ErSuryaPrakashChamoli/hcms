<?php

namespace App\Filament\Resources\Designations\Schemas;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Designation;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\CustomFieldsSchema;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DesignationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('code')->required()->maxLength(32)->alphaDash()->helperText('Short unique code.'),
                        Select::make('level_id')->relationship('level', 'name')->searchable()->preload(),
                        Select::make('grade_id')->relationship('grade', 'name')->searchable()->preload(),
                        Select::make('job_family_id')->relationship('jobFamily', 'name')->label('Job family')->searchable()->preload(),
                        Select::make('department_id')->relationship('department', 'name')->searchable()->preload(),
                        Select::make('default_reporting_level_id')->relationship('defaultReportingLevel', 'name')->label('Default reporting level')->searchable()->preload(),
                        Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
                        Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                        Select::make('employmentTypes')->relationship('employmentTypes', 'name')->multiple()->preload()->label('Employment types')->columnSpanFull(),
                    ]),
                Section::make('Effective dates')
                    ->description('Leave blank for open-ended. History is never overwritten.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('effective_from')->native(false),
                        DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
                    ]),
                CustomFieldsSchema::formSection(Designation::class),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}
