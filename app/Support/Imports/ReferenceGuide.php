<?php

namespace App\Support\Imports;

/**
 * Names first (owner decision 2026-11-26): the plain-language wording every upload's format guide, template comment and
 * Read me uses for columns that name another record, for a record's own code, and for the people a row names. One
 * place, so all uploads say the same thing.
 *
 * Rule behind the wording: people type the names they see on screen; PeopleOS keeps codes behind them. A code still
 * works wherever a name does (files made before keep working), and "Name (CODE)" settles two records of one name.
 */
final class ReferenceGuide
{
    /** The note every upload with reference columns carries. */
    public const NOTE = 'Type names as they appear on screen (Finance, Earned leave, General shift). A code works too, and so does "Name (CODE)" when two records share a name; a name that matches several records, or only resembles one, is shown for you to decide before anything is saved.';

    /** The note master uploads carry about their own code. */
    public const OWN_CODE_NOTE = 'The Code column may be left blank: the row then changes the record of that name, or adds one with a code made from the name. Give the code to rename a record (the code finds it) or to choose a code of your own.';

    /**
     * A column naming a record of another list: "The department's name (Organisation → Departments); its code works too."
     *
     * @param  string  $what  the record, lower case: "department", "leave type"
     * @param  string|null  $where  where it is set up: "Organisation → Departments"
     */
    public static function name(string $what, ?string $where = null, ?string $extra = null): string
    {
        return self::join("The {$what}'s name".($where !== null ? " ({$where})" : '').'; its code works too.', $extra);
    }

    /** As name(), for an optional link that `(none)` removes. */
    public static function link(string $what, ?string $where = null, ?string $extra = null): string
    {
        return self::join(self::name($what, $where), $extra, ClearValue::hint());
    }

    /** Several records of one list in one cell, separated by commas. */
    public static function names(string $what, ?string $where = null, ?string $extra = null): string
    {
        return self::join("Names of the {$what}".($where !== null ? " ({$where})" : '').', separated by commas; codes work too.', $extra);
    }

    /** A column naming a person: "The reporting manager: employee code, work e-mail or full name." */
    public static function person(string $who, ?string $extra = null): string
    {
        return self::join("The {$who}: employee code, work e-mail or full name (a full name two people share is refused; use the code).", $extra);
    }

    /** Several people in one cell. */
    public static function people(string $who, ?string $extra = null): string
    {
        return self::join("The {$who}, separated by commas: employee code, work e-mail or full name of each.", $extra);
    }

    /** A master upload's own Code column. */
    public static function ownCode(string $what): string
    {
        return "Optional. Blank: the {$what} is found by its name, and a new one gets a code made from the name. Give the code to rename a {$what} or to choose its code (up to 32 characters).";
    }

    private static function join(?string ...$parts): string
    {
        return implode(' ', array_filter($parts, fn (?string $p) => $p !== null && $p !== ''));
    }
}
