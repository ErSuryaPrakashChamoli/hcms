<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Configuration\Models\OptionList;

/**
 * HROne setup parity CA-3 (Employee → Relationship, Document → document type): vocabularies an organisation extends.
 * The options are the built-in list (config) with the organisation's rows on top: a row may rename or hide a built-in
 * option, or add one. Labels of hidden options still resolve, so older records keep their meaning.
 */
final class OptionLists
{
    /** list key => label and the config list it extends */
    public const LISTS = [
        'family_relations' => ['label' => 'Family relations', 'config' => 'peopleos.people.family_relations'],
        'document_categories' => ['label' => 'Document categories', 'config' => 'peopleos.documents.categories'],
    ];

    /** @return array<string, string> the built-in options of a list */
    public static function builtIn(string $list): array
    {
        return (array) config(self::LISTS[$list]['config'] ?? '', []);
    }

    /** @return array<string, string> code => label, the options offered now (built-in first, then the organisation's) */
    public function options(string $list): array
    {
        $rows = OptionList::query()->where('list_key', $list)->orderBy('sort_order')->orderBy('label')->get(['code', 'label', 'status']);
        $options = self::builtIn($list);
        foreach ($rows as $row) {
            if ($row->status === 'active') {
                $options[$row->code] = $row->label;
            } else {
                unset($options[$row->code]);
            }
        }

        return $options;
    }

    /** @return array<string, string> code => label of every option ever offered, hidden ones included (to show old records) */
    public function labels(string $list): array
    {
        return array_replace(self::builtIn($list), OptionList::query()->where('list_key', $list)->pluck('label', 'code')->all());
    }

    public function label(string $list, ?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $renamed = OptionList::query()->where('list_key', $list)->where('code', $code)->value('label');

        return $renamed ?? (self::builtIn($list)[$code] ?? $code);
    }
}
