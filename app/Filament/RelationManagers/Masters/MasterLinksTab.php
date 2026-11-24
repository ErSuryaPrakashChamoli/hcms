<?php

namespace App\Filament\RelationManagers\Masters;

use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Filament\Pages\OrganisationDesigner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Master relationships R4: the master's links, one row each: what it is set to (or "Not set": optional, set it on the
 * General tab), the companies with their own value, and what a business process needs of it (the employment data
 * requirements). Organisation units show where they sit in the structure, locations their establishment.
 */
final class MasterLinksTab extends MasterTab
{
    protected static ?string $title = 'Links';

    protected static string $contentView = 'filament.masters.links';

    /** Designation links that a hire's position takes from the designation (R3 defaults) => the position field. */
    private const POSITION_FIELD = ['level_id' => 'level_id', 'grade_id' => 'grade_id'];

    public static function appliesTo(Model $record): bool
    {
        return (MasterTabs::type($record)?->references ?? []) !== [] || self::treeType($record) !== null || $record instanceof Location;
    }

    protected function tabData(): array
    {
        $record = $this->getOwnerRecord();
        $type = MasterTabs::type($record);
        $types = app(ConfigurationTypes::class);
        $companies = Company::query()->withoutGlobalScope(AccessScope::class)->pluck('name', 'id');
        $links = [];
        foreach (($type?->references ?? []) as $column => $targetKey) {
            if (str_contains($column, '.') || ! $types->has($targetKey)) {
                continue;
            }
            $target = $types->for($targetKey);
            $id = $record->getAttribute($column);
            $value = filled($id) && ! is_array($id) ? $target->model::query()->withoutGlobalScope(AccessScope::class)->whereKey($id)->value($target->nameColumn) : null;
            $own = $type->supportsOverrides() ? ConfigurationOverride::query()->where('configuration_type', $type->key)->where('configuration_id', $record->getKey())
                ->where('field', $column)->where('status', ConfigurationOverride::ACTIVE)->where('effective_from', '<=', today())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()))->get()
                ->map(fn ($row) => ($companies[$row->company_id] ?? '#'.$row->company_id).': '.(filled($row->value) ? ($target->model::query()->withoutGlobalScope(AccessScope::class)->whereKey($row->value)->value($target->nameColumn) ?? '#'.$row->value) : 'none'))
                ->all() : [];
            $links[] = [
                'label' => $record instanceof Designation && $column === 'department_id' ? 'Usual department (information only)' : Str::of($column)->beforeLast('_id')->replace('_', ' ')->ucfirst()->toString(),
                'value' => $value, 'own' => $own,
                'needed' => $record instanceof Designation ? $this->neededBy($column, $value === null) : [],
            ];
        }

        return ['links' => $links, 'placement' => $this->placement($record), 'establishment' => $record instanceof Location ? $this->establishment($record) : null];
    }

    private static function treeType(Model $record): ?string
    {
        try {
            $key = app(OrganisationTree::class)->typeKeyForClass($record::class);
        } catch (\Throwable) {
            return null;
        }

        return $key;
    }

    /** @return array{placed: bool, path: list<string>, url: ?string}|null */
    private function placement(Model $record): ?array
    {
        if (self::treeType($record) === null || ! method_exists($record, 'organisationNode')) {
            return null;
        }
        $node = $record->organisationNode()->first();
        $path = [];
        if ($node !== null) {
            $ids = array_values(array_filter(explode('/', (string) $node->path)));
            $nodes = OrganisationNode::query()->whereKey($ids)->with('nodeable')->get()->keyBy('id');
            foreach ($ids as $id) {
                $path[] = (string) ($nodes[(int) $id]?->nodeable?->name ?? '#'.$id);
            }
        }

        return ['placed' => $node !== null, 'path' => $path,
            'url' => (auth()->user()?->can('organisation.design') ?? false) ? OrganisationDesigner::getUrl(['search' => (string) ($record->getAttribute('code') ?? $record->getAttribute('name'))]) : null];
    }

    /** @return array{name: ?string, several: bool, company: ?string} */
    private function establishment(Location $location): array
    {
        $name = $location->establishment_id ? Establishment::query()->withoutGlobalScope(AccessScope::class)->whereKey($location->establishment_id)->value('name') : null;
        $count = $location->company_id ? Establishment::query()->withoutGlobalScope(AccessScope::class)->where('company_id', $location->company_id)->count() : 0;

        return ['name' => $name, 'several' => $count > 1, 'company' => $location->company_id ? Company::query()->withoutGlobalScope(AccessScope::class)->whereKey($location->company_id)->value('name') : null];
    }

    /**
     * What the employment data requirements need of a designation's link: hires into it need a grade when a policy
     * requires one, and the designation prefills it when it has one.
     *
     * @return list<string>
     */
    private function neededBy(string $column, bool $missing): array
    {
        $field = self::POSITION_FIELD[$column] ?? null;
        if ($field === null) {
            return [];
        }
        $out = [];
        foreach (Policy::query()->where('type', EmploymentRequirements::TYPE)->where('status', 'active')->get() as $policy) {
            $settings = EmploymentRequirements::normalise((array) ($policy->versionEffectiveOn(today())?->settings ?? []));
            if ($settings['required_mode'] !== EmploymentRequirements::OFF && in_array($field, $settings['required_on_hire'], true)) {
                $out[] = "Required on hire by {$policy->name} (".EmploymentRequirements::MODES[$settings['required_mode']].')'
                    .($missing ? ': this designation has none to prefill, so each hire must be given one.' : '.');
            }
        }

        return $out;
    }
}
