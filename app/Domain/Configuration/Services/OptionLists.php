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
        // HROne parity MS-4: salutations (letters, lists), the benefits a nomination is for, how a qualification was studied.
        'salutations' => ['label' => 'Salutations', 'config' => 'peopleos.people.salutations'],
        'nomination_benefits' => ['label' => 'Nomination benefits', 'config' => 'peopleos.people.nomination_benefits'],
        'qualification_modes' => ['label' => 'Qualification modes', 'config' => 'peopleos.people.qualification_modes'],
        // HROne parity WO-4: why an upcoming joiner did not join ("Mark as not joining").
        'not_joining_reasons' => ['label' => 'Not-joining reasons', 'config' => 'peopleos.lifecycle.not_joining_reasons'],
        // HROne parity WO-15: the type of a business partner ("User type") and the kind of a vendor.
        'partner_types' => ['label' => 'Business partner types', 'config' => 'peopleos.partners.types'],
        'vendor_kinds' => ['label' => 'Vendor kinds', 'config' => 'peopleos.partners.vendor_kinds'],
        // HROne parity GO-15: the type and sub-type of a company event ("Type of event", "Type of sub event").
        'event_types' => ['label' => 'Event types', 'config' => 'peopleos.engagement.event_types'],
        'event_sub_types' => ['label' => 'Event sub-types', 'config' => 'peopleos.engagement.event_sub_types'],
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
