<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\PersonWorkspace;
use App\Filament\Support\CustomFieldsSchema;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Employee 360 (blueprint §17; UX.15): the person workspace, every recorded field, custom fields and applicable policies. */
class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // UX.15: the person workspace (Now, Journey, Work, Growth, Rewards, Documents), from PersonWorkspace —
                // every block gated by the rule that already governs it. The records (relation tabs) follow below.
                ViewEntry::make('workspace')->hiddenLabel()->columnSpanFull()->view('filament.employees.workspace')
                    ->state(fn (Employee $record, $livewire) => method_exists($livewire, 'workspace') ? $livewire->workspace : app(PersonWorkspace::class)->for(auth()->user(), $record)),
                // Every recorded field, one click away (same entries and visibility as before).
                // UX.15 closure: deep detail lives in the Records view (pos-360-deep is shown only there).
                Section::make('All details')
                    ->description('Every field on this person’s record.')
                    ->extraAttributes(['class' => 'pos-360-deep'])
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed()
                    ->columns(['default' => 2, 'lg' => 4])
                    ->schema([
                        TextEntry::make('person.display_name')->label('Name'),
                        TextEntry::make('employee_code')->label('Employee ID')->copyable(),
                        TextEntry::make('lifecycle_state')->badge(),
                        TextEntry::make('work_email')->copyable()->placeholder('—'),
                        TextEntry::make('work_phone')->placeholder('—'),
                        TextEntry::make('person.date_of_birth')->label('Date of birth')->date()->placeholder('—'),
                        TextEntry::make('source')->badge()->color('gray')->formatStateUsing(fn (?string $state) => strtoupper((string) $state)),
                        TextEntry::make('person.gender')->label('Gender')->formatStateUsing(fn (?string $state) => config("peopleos.people.genders.{$state}", $state))->placeholder('—'),
                        TextEntry::make('currentPosition.company.name')->label('Company')->placeholder('—'),
                        TextEntry::make('currentPosition.businessUnit.name')->label('Business unit')->placeholder('—'),
                        TextEntry::make('currentPosition.team.name')->label('Team')->placeholder('—'),
                        TextEntry::make('currentPosition.level.name')->label('Level')->placeholder('—'),
                        TextEntry::make('currentPosition.grade.name')->label('Grade')->placeholder('—'),
                        TextEntry::make('currentPosition.employeeCategory.name')->label('Category')->placeholder('—'),
                        TextEntry::make('currentPosition.effective_from')->label('In role since')->date()->placeholder('—'),
                        TextEntry::make('probation_end_date')->date()->placeholder('—'),
                        TextEntry::make('confirmation_date')->date()->placeholder('Not confirmed'),
                    ]),
                CustomFieldsSchema::infolistSection(Employee::class)->extraAttributes(['class' => 'pos-360-deep']),
                Section::make('Applicable policies')
                    ->description('Resolved today from the tenant\'s assignment rules.')
                    ->extraAttributes(['class' => 'pos-360-deep'])
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
