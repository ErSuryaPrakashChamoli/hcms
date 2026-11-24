<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Imports\Concerns\SetsApplicability;
use App\Domain\Organisation\Models\Company;
use App\Support\Imports\ClearValue;
use Illuminate\Database\Eloquent\Model;

/**
 * Master uploads: the plain organisation masters — a code, a name, a description and a status, optionally owned by a
 * company (divisions, teams, profit centres) — with where they apply (SetsApplicability). One base, so each master is a
 * few lines: its model, its names, the right that changes it and two example rows.
 */
abstract class SimpleMasterImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission, SetsApplicability;

    /** Whether the master may belong to one company (company_id). */
    abstract protected static function companyOwned(): bool;

    /** "division", for messages and the format guide. */
    abstract protected static function noun(): string;

    /** @return list<list<string>> two example rows: code, name, (company code), description */
    abstract protected function exampleValues(): array;

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true]]
            + (static::companyOwned() ? ['company_code' => ['Company code', false]] : [])
            + ['description' => ['Description', false], 'status' => ['Status', false]]
            + self::applicabilityFields();
    }

    protected function module(): string
    {
        return 'organisation';
    }

    protected function aliases(): array
    {
        $noun = str_replace(' ', '_', static::noun());

        return ["{$noun}_code" => 'code', "{$noun}_name" => 'name', $noun => 'name', 'company' => 'company_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        return array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'company_id' => static::companyOwned() ? $this->lookup($context, Company::class, $r['company_code'] ?? null, 'Company code', $errors) : null,
            'description' => $this->text($r['description'] ?? null, 1000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        $noun = static::noun();
        $example = $this->exampleValues()[0];

        return [
            'columns' => [
                ['Code', 'Yes', "The {$noun} code (up to 32 characters). A code on file updates that {$noun}.", $example[0]],
                ['Name', 'Yes', "The {$noun} name.", $example[1]],
                ...(static::companyOwned() ? [['Company code', 'No', 'The company it belongs to: its code or name. Blank: every company may use it. '.ClearValue::hint(), $example[2]]] : []),
                ['Description', 'No', 'A short description.', $example[array_key_last($example)]],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
                ...self::applicabilityColumns(),
            ],
            'notes' => [
                "Rows are matched on Code (any case). A new code adds a {$noun}; a code on file updates it; a row whose values are already on file is skipped.",
                'Blank cells keep what is on file. Other records may be named by code or by their exact name.',
                "Changes to an existing {$noun} follow the Configuration Change Centre when your organisation approves configuration changes.",
            ],
        ];
    }

    protected function examples(): array
    {
        return array_map(fn (array $row) => [...$row, 'active', '', ''], $this->exampleValues());
    }

    /** The first company's code, for example rows. */
    protected static function firstCompany(): string
    {
        return (string) (Company::query()->orderBy('id')->value('code') ?? 'MKT');
    }
}
