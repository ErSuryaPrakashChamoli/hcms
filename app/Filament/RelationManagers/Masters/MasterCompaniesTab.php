<?php

namespace App\Filament\RelationManagers\Masters;

use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Models\ConfigurationApplicability;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use Illuminate\Database\Eloquent\Model;

/** Master relationships R4: which companies use the master, since when, and how that was chosen. */
final class MasterCompaniesTab extends MasterTab
{
    protected static ?string $title = 'Companies';

    protected static string $contentView = 'filament.masters.companies';

    public static function appliesTo(Model $record): bool
    {
        return MasterTabs::type($record) !== null;
    }

    protected function tabData(): array
    {
        $record = $this->getOwnerRecord();
        $type = MasterTabs::type($record);
        $applicability = app(EntityApplicability::class);
        $names = Company::query()->withoutGlobalScope(AccessScope::class)->orderBy('name')->pluck('name', 'id');
        $header = ConfigurationApplicability::query()->where('configuration_type', $type->key)->where('configuration_id', $record->getKey())->with('companies')->first();
        $reach = $applicability->reach($record);

        return [
            'summary' => $applicability->summary($record),
            'reach' => $reach === null ? null : collect($reach)->map(fn ($id) => $names[$id] ?? '#'.$id)->values()->all(),
            'owner' => $type->ownerColumn !== null ? ($names[$record->getAttribute($type->ownerColumn)] ?? null) : null,
            'future' => (bool) ($header?->include_future ?? false),
            'rows' => collect($header?->companies ?? [])->sortBy(fn ($row) => ($names[$row->company_id] ?? '').'|'.$row->effective_from)->map(fn ($row) => [
                'company' => $names[$row->company_id] ?? '#'.$row->company_id,
                'from' => $row->effective_from?->format('j M Y'),
                'to' => $row->effective_to?->format('j M Y'),
                'source' => str_replace('_', ' ', (string) $row->source),
            ])->values()->all(),
            'manageable' => auth()->user()?->can('configuration.share') ?? false,
        ];
    }
}
