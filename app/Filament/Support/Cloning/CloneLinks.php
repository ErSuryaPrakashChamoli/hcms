<?php

namespace App\Filament\Support\Cloning;

use App\Domain\Configuration\Cloning\CloneLineage;
use App\Domain\Configuration\Cloning\CloneRegistry;
use App\Domain\Configuration\Cloning\Snapshot;
use App\Filament\Pages\PolicyWizard;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Support\PolicySettingsSchema;
use App\Filament\Support\RuleConditionsSchema;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The screens side of each cloneable type (the domain adapters know nothing of pages): where a copy is finished and
 * activated, where a record's own applicability is edited, and a snapshot as label / value rows for the wizard and for
 * "Compare with source". A type without an entry still clones; it just gets the plain rows and no links.
 */
final class CloneLinks
{
    /** Where the person finishes and activates a copy. */
    public static function open(string $adapter, int $id): ?string
    {
        return rescue(fn () => match ($adapter) {
            'policy' => PolicyWizard::getUrl(['policy' => $id]),
            default => self::resourceUrl($adapter, $id),
        }, null, false);
    }

    /** The record's own page (versions, rules, history). */
    public static function record(string $adapter, int $id): ?string
    {
        return rescue(fn () => match ($adapter) {
            'policy' => PolicyResource::getUrl('edit', ['record' => $id]),
            default => self::resourceUrl($adapter, $id),
        }, null, false);
    }

    /** Any other registered type: its resource's edit page when it has one, else its list. */
    private static function resourceUrl(string $adapter, int $id): ?string
    {
        $resource = Filament::getModelResource(app(CloneRegistry::class)->for($adapter)->model());
        if ($resource === null) {
            return null;
        }

        return $resource::hasPage('edit') ? $resource::getUrl('edit', ['record' => $id]) : $resource::getUrl('index');
    }

    /**
     * "Compare with source" (FR-21): the source as it was copied, the source now and the copy now, row by row.
     *
     * @return array{intro: string, rows: list<array{label: string, then: string, source: string, copy: string, changed: bool}>}|null
     */
    public static function compare(string $adapter, Model $copy): ?array
    {
        $origin = app(CloneLineage::class)->origin($adapter, (int) $copy->getKey());
        if ($origin === null || $origin->operation === null || ! app(CloneRegistry::class)->has($adapter)) {
            return null;
        }
        $type = app(CloneRegistry::class)->for($adapter);
        $then = Snapshot::fromArray((array) $origin->operation->source_snapshot);
        $source = $origin->source_id === null ? null : $type->find($origin->source_id);
        $columns = [
            'then' => self::keyed(self::rows($then)),
            'source' => $source === null ? [] : self::keyed(self::rows($type->snapshot($source))),
            'copy' => self::keyed(self::rows($type->snapshot($copy))),
        ];
        $labels = array_values(array_unique([...array_keys($columns['then']), ...array_keys($columns['source']), ...array_keys($columns['copy'])]));
        $rows = array_map(function (string $label) use ($columns, $source) {
            $values = ['then' => $columns['then'][$label] ?? '—', 'source' => $source === null ? 'Deleted' : ($columns['source'][$label] ?? '—'), 'copy' => $columns['copy'][$label] ?? '—'];

            return ['label' => $label] + $values + ['changed' => count(array_unique($values)) > 1];
        }, $labels);

        return [
            'intro' => 'Copied from '.$then->label.' on '.$origin->created_at?->format('d M Y').($origin->operation->initiator ? ' by '.$origin->operation->initiator->name : '')
                .' (source checksum '.Str::limit((string) $origin->source_checksum, 12, '…').').',
            'rows' => $rows,
        ];
    }

    /** @return list<array{0: string, 1: string}> */
    public static function rows(Snapshot $snapshot): array
    {
        if ($snapshot->adapter === 'policy') {
            $type = (string) ($snapshot->identity['type'] ?? '');
            $rows = [
                ['Kind', (string) config("peopleos.policies.types.{$type}.label", $type)],
                ['Name', (string) ($snapshot->identity['name'] ?? '')],
                ['Code', (string) ($snapshot->identity['code'] ?? '')],
                ['Settings of', isset($snapshot->content['version']) ? 'v'.$snapshot->content['version'].' ('.($snapshot->content['version_status'] ?? '—').')' : 'No version yet'],
            ];
            foreach (PolicySettingsSchema::summary($type, (array) ($snapshot->content['settings'] ?? [])) as $group) {
                foreach ($group['rows'] as [$label, $value]) {
                    $rows[] = [$group['label'].' · '.$label, (string) $value];
                }
            }
            foreach (self::rules($snapshot->applicability) as $i => $rule) {
                $rows[] = ['Applicability rule '.($i + 1), $rule];
            }

            return $rows;
        }

        return self::flatten(['identity' => $snapshot->identity, 'content' => $snapshot->content, 'applicability' => $snapshot->applicability]);
    }

    /**
     * Rule-based applicability as sentences: "Standard (priority 100): Company is any of Markedge AND Grade is any of G1".
     *
     * @param  array<string, mixed>  $applicability
     * @return list<string>
     */
    public static function rules(array $applicability): array
    {
        return array_values(array_map(function ($rule) {
            $rule = (array) $rule;
            $conditions = array_values((array) ($rule['conditions'] ?? []));
            $text = $conditions === [] ? 'Everyone' : RuleConditionsSchema::describe($conditions, (string) ($rule['match'] ?? 'all'));

            return ($rule['name'] ?? 'Rule').' (priority '.($rule['priority'] ?? '—').'): '.$text;
        }, (array) ($applicability['rules'] ?? [])));
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     * @return array<string, string>
     */
    private static function keyed(array $rows): array
    {
        $keyed = [];
        foreach ($rows as [$label, $value]) {
            $keyed[$label] = $value;
        }

        return $keyed;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $rows = [];
        foreach ($data as $key => $value) {
            $label = ltrim($prefix.'.'.$key, '.');
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $rows = [...$rows, ...self::flatten($value, $label)];
            } else {
                $rows[] = [$label, is_scalar($value) || $value === null ? (string) ($value ?? '—') : (string) json_encode($value)];
            }
        }

        return $rows;
    }
}
