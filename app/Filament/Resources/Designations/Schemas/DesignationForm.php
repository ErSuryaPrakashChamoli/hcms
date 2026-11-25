<?php

namespace App\Filament\Resources\Designations\Schemas;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Services\LevelGradePairs;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\CustomFieldsSchema;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                        Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
                        Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                    ]),
                // Master relationships R4: a designation is created with its name and code alone; links are optional and can
                // be set now or later. Each company may have its own level, grade and job family ("Company values…").
                Section::make('Links')
                    ->description('Optional: set them now or later. Choosing this designation for someone prefills their level and grade from here. Designations without a grade are listed in Organisation → Unmapped masters.')
                    ->columns(2)
                    ->schema([
                        Select::make('level_id')->relationship('level', 'name')->searchable()->preload(),
                        Select::make('grade_id')->relationship('grade', 'name')->searchable()->preload()
                            // Remediation I.6: the grade agrees with the level (shared, and for each company that follows them).
                            ->rule(fn (Get $get, ?Designation $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                if (($problem = app(LevelGradePairs::class)->designationFormProblem($record, $get('level_id'), $value)) !== null) {
                                    $fail($problem);
                                }
                            }),
                        Select::make('job_family_id')->relationship('jobFamily', 'name')->label('Job family')->searchable()->preload(),
                        // OD-1: information only. A person's department comes from their own position; this never places anyone.
                        Select::make('department_id')->relationship('department', 'name')->label('Usual department (information only)')
                            ->helperText('Where people with this designation usually work. It never places anyone: each person\'s department comes from their own position.')->searchable()->preload(),
                        Select::make('default_reporting_level_id')->relationship('defaultReportingLevel', 'name')->label('Default reporting level')->searchable()->preload(),
                        Select::make('employmentTypes')->relationship('employmentTypes', 'name')->multiple()->preload()->label('Employment types')->columnSpanFull(),
                    ]),
                // HROne setup parity CA-2 (Designation): experience range, job description, hazardous work, minimum-wage class.
                Section::make('Role profile')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('min_experience_years')->label('Minimum experience (years)')->numeric()->minValue(0)->maxValue(99)->step(0.5),
                        TextInput::make('max_experience_years')->label('Maximum experience (years)')->numeric()->minValue(0)->maxValue(99)->step(0.5)->gte('min_experience_years'),
                        Select::make('wage_skill_category')->label('Minimum-wage skill category')->options(config('peopleos.compliance.wage_skill_categories'))->placeholder('—')
                            ->helperText('The skill class minimum wage checks and the wages register use. Rates come from the state rules.'),
                        Toggle::make('is_hazardous')->label('Hazardous work')->inline(false),
                        RichEditor::make('job_description')->label('Job description')->columnSpanFull()
                            ->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList'], ['undo', 'redo']]),
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
