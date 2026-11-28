<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\PersonWorkspace;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Employee 360 (blueprint §17; UX.15): the person workspace (every detail is in its Profile view) and the applicable policies (Work). */
class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // UX.15: the person workspace (Now, Journey, Work, Growth, Rewards, Documents), from PersonWorkspace —
                // every block gated by the rule that already governs it. Each view's lists follow it (ViewEmployee::content).
                ViewEntry::make('workspace')->hiddenLabel()->columnSpanFull()->view('filament.employees.workspace')
                    ->state(fn (Employee $record, $livewire) => method_exists($livewire, 'workspace') ? $livewire->workspace : app(PersonWorkspace::class)->for(auth()->user(), $record)),
                // Owner, 2026-11-27: every detail in one place. Each recorded field and the additional fields are read in
                // Profile (EmployeeProfileDetails); the policies that apply belong to the Work view.
                Group::make([
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
                ])->columnSpanFull()->extraAttributes(['class' => 'pos-360-lists', 'data-pos360-of' => 'work', 'x-data' => '', 'x-cloak' => '',
                    'x-show' => "\$store.pos360 ? \$store.pos360.view === 'work' : true"]),
            ]);
    }
}
