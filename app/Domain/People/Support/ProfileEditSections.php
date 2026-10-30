<?php

namespace App\Domain\People\Support;

/**
 * HROne parity MS-3: the parts of a profile an employee may ask to change on My profile, each with its own "Edit".
 *
 * - `tab`: the My profile section it is shown on; `visible_in`: the CA-6 profile section (profile.section_visibility)
 *   that must show it to the employee (null = Snapshot, always shown). An employee only edits what the matrix shows them.
 * - `always_approval`: changed only after someone approves it, whatever the setting says (bank account, statutory
 *   identifiers, legal name and date of birth: owner decisions D-MS2 / D-MS11 open; safest default).
 * - `document`: `always` = a supporting document must be attached (legal identity); `linked` = the CA-4 document types
 *   "required with" the fields it changes must be on file or attached (DocumentRules).
 *
 * Which sections are editable, and which apply without approval, is the setting `profile.self_edit.sections` (rows =
 * config peopleos.profile.self_edit_sections; default: nothing editable = as before). Legal identity is not a row: it
 * is switched on by `profile.self_edit.legal_identity` (default off).
 */
final class ProfileEditSections
{
    public const SECTIONS = [
        'blood_group' => ['tab' => 'snapshot', 'visible_in' => null, 'always_approval' => false, 'document' => null],
        'legal_identity' => ['tab' => 'snapshot', 'visible_in' => null, 'always_approval' => true, 'document' => 'always'],
        'contact' => ['tab' => 'personal', 'visible_in' => 'personal', 'always_approval' => false, 'document' => null],
        'addresses' => ['tab' => 'personal', 'visible_in' => 'personal', 'always_approval' => false, 'document' => 'linked'],
        'emergency_contacts' => ['tab' => 'personal', 'visible_in' => 'personal', 'always_approval' => false, 'document' => null],
        'identifiers' => ['tab' => 'personal', 'visible_in' => 'personal', 'always_approval' => true, 'document' => 'linked'],
        'family' => ['tab' => 'family', 'visible_in' => 'family', 'always_approval' => false, 'document' => null],
        'education' => ['tab' => 'professional', 'visible_in' => 'professional', 'always_approval' => false, 'document' => null],
        'experience' => ['tab' => 'professional', 'visible_in' => 'professional', 'always_approval' => false, 'document' => null],
        'skills' => ['tab' => 'skills', 'visible_in' => 'skills', 'always_approval' => false, 'document' => null],
        'other_details' => ['tab' => 'other_details', 'visible_in' => 'other_details', 'always_approval' => false, 'document' => null],
        'bank' => ['tab' => 'bank', 'visible_in' => 'bank', 'always_approval' => true, 'document' => 'linked'],
    ];

    public const LEGAL_IDENTITY = 'legal_identity';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::SECTIONS);
    }

    public static function exists(string $section): bool
    {
        return array_key_exists($section, self::SECTIONS);
    }

    public static function label(string $section): string
    {
        return $section === self::LEGAL_IDENTITY ? 'Legal name and date of birth'
            : (string) (config('peopleos.profile.self_edit_sections.'.$section) ?? ucfirst(str_replace('_', ' ', $section)));
    }

    public static function tab(string $section): ?string
    {
        return self::SECTIONS[$section]['tab'] ?? null;
    }

    public static function visibleIn(string $section): ?string
    {
        return self::SECTIONS[$section]['visible_in'] ?? null;
    }

    public static function alwaysApproval(string $section): bool
    {
        return (bool) (self::SECTIONS[$section]['always_approval'] ?? true);
    }

    public static function document(string $section): ?string
    {
        return self::SECTIONS[$section]['document'] ?? null;
    }

    /** @return list<string> the sections shown on one My profile tab, in order */
    public static function onTab(string $tab): array
    {
        return array_keys(array_filter(self::SECTIONS, fn (array $s) => $s['tab'] === $tab));
    }
}
