<?php

namespace App\Filament\Pages;

use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Team;
use App\Filament\Resources\BusinessUnits\BusinessUnitResource;
use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Resources\Designations\DesignationResource;
use App\Filament\Resources\Divisions\DivisionResource;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Teams\TeamResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Master relationships R4: the masters whose optional links are not set where a business process would use them.
 * Designations without a grade (a blocker when the employment data requirements need one on Block), organisation units
 * not placed in the structure, and locations without an establishment in companies with more than one (statutory then
 * uses the head office's). Optional links never count as errors by themselves; each row says what follows and links
 * to where it is fixed. Only what the person may see is listed.
 */
class UnmappedMastersPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLinkSlash;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?string $navigationLabel = 'Unmapped masters';

    protected static ?string $title = 'Unmapped masters';

    protected static ?string $slug = 'organisation/unmapped-masters';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.unmapped-masters';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('organisation.view') || $user->can('people_setup.view'));
    }

    /** @return list<array{name: string, code: string, effect: string, blocker: bool, own: list<string>, url: ?string}> */
    public function designations(): array
    {
        $mine = app(EntityApplicability::class)->companiesFor(auth()->user());
        $query = Designation::query()->where('status', 'active')->whereNull('grade_id')->orderBy('name');
        if ($mine !== null) {
            $query = app(EntityApplicability::class)->constrain($query, $mine);
        }
        $names = Company::query()->withoutGlobalScope(AccessScope::class)->pluck('name', 'id');
        [$effect, $blocker] = $this->gradeNeed();
        $canEdit = auth()->user()?->can('people_setup.update') ?? false;
        $designations = $query->get();
        // The companies (of those the person may see) whose own grade covers a designation without a shared one.
        $own = ConfigurationOverride::query()->where('configuration_type', 'designation')->whereIn('configuration_id', $designations->modelKeys())->where('field', 'grade_id')
            ->where('status', ConfigurationOverride::ACTIVE)->where('effective_from', '<=', today())->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()))
            ->whereNotNull('value')->when($mine !== null, fn ($q) => $q->whereIn('company_id', $mine))->get()
            ->groupBy('configuration_id')->map(fn ($rows) => $rows->map(fn ($o) => (string) ($names[$o->company_id] ?? '#'.$o->company_id))->values()->all());

        return $designations->map(fn (Designation $d) => [
            'name' => $d->name, 'code' => (string) $d->code, 'effect' => $effect, 'blocker' => $blocker,
            'own' => $own->get($d->id, []),
            'url' => $canEdit ? DesignationResource::getUrl('edit', ['record' => $d]) : null,
        ])->all();
    }

    /** @return list<array{kind: string, name: string, code: string, url: ?string, place: ?string}> */
    public function unplaced(): array
    {
        $design = auth()->user()?->can('organisation.design') ?? false;
        $out = [];
        foreach ([['Business unit', BusinessUnit::class, BusinessUnitResource::class], ['Division', Division::class, DivisionResource::class], ['Department', Department::class, DepartmentResource::class],
            ['Team', Team::class, TeamResource::class], ['Location', Location::class, LocationResource::class]] as [$kind, $model, $resource]) {
            foreach ($model::query()->where('status', 'active')->whereDoesntHave('organisationNode')->orderBy('name')->get() as $unit) {
                $out[] = ['kind' => $kind, 'name' => (string) $unit->name, 'code' => (string) $unit->code,
                    'url' => $resource::canEdit($unit) ? $resource::getUrl('edit', ['record' => $unit]) : null,
                    'place' => $design ? OrganisationDesigner::getUrl(['search' => (string) $unit->code]) : null];
            }
        }

        return $out;
    }

    /** @return list<array{name: string, code: string, company: string, url: ?string}> */
    public function locationsWithoutEstablishment(): array
    {
        $several = Establishment::query()->withoutGlobalScope(AccessScope::class)->selectRaw('company_id')->groupBy('company_id')->havingRaw('count(*) > 1')->pluck('company_id');
        if ($several->isEmpty()) {
            return [];
        }
        $names = Company::query()->withoutGlobalScope(AccessScope::class)->pluck('name', 'id');

        return Location::query()->where('status', 'active')->whereNull('establishment_id')->whereIn('company_id', $several)->orderBy('name')->get()
            ->map(fn (Location $l) => ['name' => (string) $l->name, 'code' => (string) $l->code, 'company' => (string) ($names[$l->company_id] ?? ''),
                'url' => LocationResource::canEdit($l) ? LocationResource::getUrl('edit', ['record' => $l]) : null])->all();
    }

    /**
     * What a designation without a grade means today: a blocker when an employment data requirements policy requires a
     * grade on hire on Block, a warning on Warn, otherwise optional.
     *
     * @return array{0: string, 1: bool}
     */
    private function gradeNeed(): array
    {
        $modes = [];
        foreach (Policy::query()->where('type', EmploymentRequirements::TYPE)->where('status', 'active')->get() as $policy) {
            $settings = EmploymentRequirements::normalise((array) ($policy->versionEffectiveOn(today())?->settings ?? []));
            if ($settings['required_mode'] !== EmploymentRequirements::OFF && in_array('grade_id', $settings['required_on_hire'], true)) {
                $modes[$settings['required_mode']][] = $policy->name;
            }
        }

        return match (true) {
            isset($modes[EmploymentRequirements::BLOCK]) => ['Hires into it are refused without a grade ('.implode(', ', $modes[EmploymentRequirements::BLOCK]).', Block).', true],
            isset($modes[EmploymentRequirements::WARN]) => ['Hires into it warn without a grade ('.implode(', ', $modes[EmploymentRequirements::WARN]).').', false],
            default => ['Optional: nothing requires a grade on hire. Set one to prefill hires.', false],
        };
    }
}
