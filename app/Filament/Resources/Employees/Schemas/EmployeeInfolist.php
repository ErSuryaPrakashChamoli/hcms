<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\Employee360;
use App\Filament\Support\CustomFieldsSchema;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Employee 360 overview (blueprint §17): who, where, since when, reporting to whom. */
class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)->schema([
                    Section::make('Identity')
                        ->columnSpan(1)
                        ->schema([
                            TextEntry::make('person.display_name')->label('Name')->weight('bold')->size('lg'),
                            TextEntry::make('employee_code')->label('Employee ID')->copyable(),
                            TextEntry::make('lifecycle_state')->badge(),
                            TextEntry::make('work_email')->copyable()->placeholder('—'),
                            TextEntry::make('work_phone')->placeholder('—'),
                            TextEntry::make('person.date_of_birth')->label('Date of birth')->date()->placeholder('—'),
                            TextEntry::make('source')->badge()->color('gray')->formatStateUsing(fn (?string $state) => strtoupper((string) $state)),
                            TextEntry::make('person.gender')->label('Gender')->formatStateUsing(fn (?string $state) => config("peopleos.people.genders.{$state}", $state))->placeholder('—'),
                        ]),
                    Section::make('Current position')
                        ->columnSpan(2)
                        ->columns(3)
                        ->schema([
                            TextEntry::make('currentPosition.designation.name')->label('Designation')->placeholder('—'),
                            TextEntry::make('currentPosition.department.name')->label('Department')->placeholder('—'),
                            TextEntry::make('currentPosition.company.name')->label('Company')->placeholder('—'),
                            TextEntry::make('currentPosition.location.name')->label('Location')->placeholder('—'),
                            TextEntry::make('currentPosition.businessUnit.name')->label('Business unit')->placeholder('—'),
                            TextEntry::make('currentPosition.team.name')->label('Team')->placeholder('—'),
                            TextEntry::make('currentPosition.level.name')->label('Level')->placeholder('—'),
                            TextEntry::make('currentPosition.grade.name')->label('Grade')->placeholder('—'),
                            TextEntry::make('currentPosition.employmentType.name')->label('Employment type')->placeholder('—'),
                            TextEntry::make('currentPosition.employeeCategory.name')->label('Category')->placeholder('—'),
                            TextEntry::make('currentPosition.workMode.name')->label('Work mode')->placeholder('—'),
                            TextEntry::make('currentPosition.effective_from')->label('In role since')->date()->placeholder('—'),
                            TextEntry::make('currentManager.manager.person.display_name')
                                ->label('Reports to')
                                ->state(fn (Employee $record) => $record->currentManager?->manager?->auditLabel())
                                ->placeholder('No line manager'),
                            TextEntry::make('joining_date')->date()->placeholder(fn (Employee $record) => $record->expected_joining_date ? 'Expected '.$record->expected_joining_date->toFormattedDateString() : '—'),
                            TextEntry::make('probation_end_date')->date()->placeholder('—'),
                            TextEntry::make('tenure')->state(fn (Employee $record) => $record->joining_date && $record->lifecycle_state->isEmployed() ? $record->joining_date->diffForHumans(now(), ['parts' => 2, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]) : '—'),
                            TextEntry::make('confirmation_date')->date()->placeholder('Not confirmed'),
                        ]),
                ]),
                // Phase 14: the 360 overview, one permission-aware summary per domain (Employee360). Each domain
                // still owns its data; the tabs below are the detail.
                Section::make('360 overview')
                    ->description('A summary from each domain you are allowed to see for this employee.')
                    ->collapsible()
                    ->schema([
                        ViewEntry::make('overview_360')->hiddenLabel()->view('filament.employees.overview-360')
                            ->state(fn (Employee $record) => app(Employee360::class)->for(auth()->user(), $record)),
                    ]),
                CustomFieldsSchema::infolistSection(Employee::class),
                Section::make('Applicable policies')
                    ->description('Resolved today from the tenant\'s assignment rules.')
                    ->columns(3)
                    ->collapsed()
                    ->schema(fn () => collect(config('peopleos.policies.types'))
                        ->map(fn (array $type, string $key) => TextEntry::make("policy_{$key}")
                            ->label($type['label'])
                            ->state(fn (Employee $record) => ($v = app(PolicyResolver::class)->resolve($key, $record)) ? $v->policy->name." (v{$v->version})" : null)
                            ->placeholder('None assigned'))
                        ->values()
                        ->all()),
            ]);
    }
}
