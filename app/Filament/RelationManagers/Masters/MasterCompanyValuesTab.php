<?php

namespace App\Filament\RelationManagers\Masters;

use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * Master relationships R4: the companies that have their own values of a shared master (a designation's grade at
 * FynnEdge), and when; set or end them with "Company values…" on the General tab.
 */
final class MasterCompanyValuesTab extends MasterTab
{
    protected static ?string $title = 'Company values';

    protected static string $contentView = 'filament.masters.company-values';

    public static function appliesTo(Model $record): bool
    {
        return MasterTabs::type($record)?->supportsOverrides() ?? false;
    }

    protected function tabData(): array
    {
        $record = $this->getOwnerRecord();
        $type = MasterTabs::type($record);
        $overrides = app(ConfigurationOverrides::class);
        $fields = $overrides->fields($record);
        $shared = [];
        foreach ($fields as $path => $field) {
            $shared[$field->label] = $field->display($record->getAttribute($path));
        }
        $mine = app(EntityApplicability::class)->companiesFor(auth()->user());
        $names = Company::query()->withoutGlobalScope(AccessScope::class)->pluck('name', 'id');
        $rows = ConfigurationOverride::query()->where('configuration_type', $type->key)->where('configuration_id', $record->getKey())
            ->whereIn('status', [ConfigurationOverride::ACTIVE, ConfigurationOverride::ENDED])
            ->when($mine !== null, fn ($q) => $q->whereIn('company_id', $mine))
            ->orderBy('company_id')->orderBy('field')->orderByDesc('effective_from')->get()
            ->map(fn (ConfigurationOverride $row) => [
                'company' => $names[$row->company_id] ?? '#'.$row->company_id,
                'field' => $fields[$row->field]->label ?? $row->field,
                'value' => isset($fields[$row->field]) ? $fields[$row->field]->display($row->value) : (string) json_encode($row->value),
                'from' => $row->effective_from?->format('j M Y'),
                'to' => $row->effective_to?->format('j M Y'),
                'current' => $row->effective_from?->lte(today()) && ($row->effective_to === null || $row->effective_to->gte(today())),
            ])->values()->all();

        return ['shared' => $shared, 'rows' => $rows];
    }
}
