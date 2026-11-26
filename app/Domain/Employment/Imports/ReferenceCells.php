<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Employment\Imports\Workbooks\ImportReferences;
use App\Domain\Organisation\Resolution\MasterCatalogue;
use App\Domain\Organisation\Resolution\ResolutionStatus;
use App\Support\Imports\ClearValue;

/**
 * Names first (owner decision 2026-11-26): people fill upload files with the names they see on screen (Finance, Earned
 * leave, General shift, Asha Rao), not with the codes PeopleOS keeps behind them. Before an upload checks or runs a
 * row, each cell that refers to another record is read here:
 *
 * - a code on file is left exactly as typed, so a file of codes works as it always did;
 * - a name, "Name (CODE)" or a value someone confirmed in the review becomes that record's code, so the upload's own
 *   lookups (which find records by code) find it;
 * - a name that several records share, that only resembles one, or whose record does not apply to the row's company is
 *   refused with the resolver's message, and goes to the review on the upload's page;
 * - a name that matches nothing is left as typed: the upload says it does not exist, as it did before.
 *
 * Names are matched for the row's company: the company the row names, else the company of the employee the row is
 * about. People (managers, reviewers, custodians) are matched by employee code, work e-mail or full name; a full name
 * that two people share is refused. Used by every row upload (RowImports::references()) and the salary upload; master
 * uploads resolve their references themselves (MasterImports::lookup()).
 */
final class ReferenceCells
{
    /**
     * @param  array<string, string>  $r  the mapped row (field => value)
     * @param  array<string, string|array{master: string, list?: bool}>  $references  field => master key or model class
     * @param  array<string, array{0: string, 1: bool}>  $fields  the upload's fields, for column labels
     * @return array{0: array<string, string>, 1: list<string>, 2: list<string>} the row with codes, the refusals, the values refused
     */
    public static function translate(array $r, array $references, array $fields, ?string $sheet, ?int $row): array
    {
        $resolver = app(ImportReferences::class);
        if ($references === [] || ! $resolver->active()) {
            return [$r, [], []];
        }
        $catalogue = app(MasterCatalogue::class);
        $refused = [];
        $values = [];

        // The company first: names of the row's other references are matched for it.
        $company = null;
        foreach ($references as $field => $reference) {
            if (self::master($reference) === MasterCatalogue::COMPANY && filled($r[$field] ?? null)) {
                [$r[$field], $problem, $id] = self::one($resolver, MasterCatalogue::COMPANY, $r[$field], null, $fields[$field][0] ?? $field, $sheet, $row);
                $company ??= $id;
                if ($problem !== null) {
                    $refused[] = $problem;
                    $values[] = $r[$field];
                }
            }
        }
        // Otherwise the company of the employee the row is about, looked up only when a cell holds a name (a file of codes
        // costs nothing extra).
        $named = $company !== null;
        $companyFor = function () use (&$company, &$named, $r, $resolver): ?int {
            if (! $named) {
                $named = true;
                $company = filled($r['employee_code'] ?? null) ? $resolver->companyOfEmployee((string) $r['employee_code']) : null;
            }

            return $company;
        };

        foreach ($references as $field => $reference) {
            $master = self::master($reference);
            $value = $r[$field] ?? null;
            if ($master === MasterCatalogue::COMPANY || $value === null || trim($value) === '' || ! $catalogue->has($master)) {
                continue;
            }
            $label = $fields[$field][0] ?? $field;
            if (is_array($reference) && ($reference['list'] ?? false)) {
                // A list (members, guarantors, reasons): each item on its own, the list kept with its separators.
                $parts = preg_split('/([;,])/', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];
                foreach ($parts as $i => $part) {
                    if ($part === ';' || $part === ',' || trim($part) === '') {
                        continue;
                    }
                    [$parts[$i], $problem] = self::one($resolver, $master, trim($part), $companyFor, $label, $sheet, $row);
                    if ($problem !== null) {
                        $refused[] = $problem;
                        $values[] = trim($part);
                    }
                }
                $r[$field] = implode('', $parts);

                continue;
            }
            [$r[$field], $problem] = self::one($resolver, $master, $value, $companyFor, $label, $sheet, $row);
            if ($problem !== null) {
                $refused[] = $problem;
                $values[] = $value;
            }
        }

        return [$r, $refused, $values];
    }

    /**
     * Drop the upload's own complaints about a value the translation already refused (its "does not exist" would only
     * repeat the resolver's clearer message).
     *
     * @param  list<string>  $errors
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function withoutRepeats(array $errors, array $values): array
    {
        return array_values(array_filter($errors, function (string $error) use ($values) {
            foreach ($values as $value) {
                if ($value !== '' && str_contains(mb_strtolower($error), "'".mb_strtolower($value)."'")) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @param  string|array{master: string, list?: bool}  $reference */
    private static function master(string|array $reference): string
    {
        return is_array($reference) ? $reference['master'] : $reference;
    }

    /**
     * One cell: [the value to pass on, the refusal or null, the record's id or null].
     *
     * @return array{0: string, 1: ?string, 2: ?int}
     */
    private static function one(ImportReferences $resolver, string $master, string $value, ?callable $company, string $label, ?string $sheet, ?int $row): array
    {
        $value = trim($value);
        // `(none)` and codes on file are passed on as typed: the upload decides what they mean, exactly as before.
        if (ClearValue::isClear($value) || $resolver->isCode($master, $value)) {
            $resolution = $master === MasterCatalogue::COMPANY && ! ClearValue::isClear($value)
                ? $resolver->resolve($master, $value, null, $sheet, $row, $label) : null;

            return [$value, null, $resolution?->ok() ? $resolution->id : null];
        }
        $resolution = $resolver->resolve($master, $value, $master === MasterCatalogue::COMPANY || $company === null ? null : $company(), $sheet, $row, $label);
        if ($resolution === null) {
            return [$value, null, null];
        }
        if ($resolution->ok()) {
            $code = $resolution->id !== null && $resolution->id > 0 ? $resolver->codeOf($master, $resolution->id) : null;

            return [$code ?? $value, null, $resolution->id !== null && $resolution->id > 0 ? $resolution->id : null];
        }
        if (in_array($resolution->status, [ResolutionStatus::Unmatched, ResolutionStatus::Blank], true) || $resolution->error() === null) {
            return [$value, null, null];
        }

        return [$value, $resolution->error(), null];
    }
}
