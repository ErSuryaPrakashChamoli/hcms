<?php

namespace App\Domain\Organisation\Resolution;

use Illuminate\Database\Eloquent\Model;

/** One master a typed value can refer to: where its names and code live and how companies and access narrow it. */
final class MasterSpec
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $nameColumns  the first is the record's name; later ones are accepted too (a company's legal name)
     * @param  list<string>  $hintColumns  columns a caller may narrow by (`within`), e.g. parent_department_id, level_id
     * @param  array<string, string>  $where  a fixed filter for lists that hold several kinds of record (performance lookups: ['kind' => 'perspective'])
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $model,
        public readonly array $nameColumns,
        public readonly ?string $codeColumn,
        public readonly ?string $ownerColumn,
        public readonly bool $applicability,
        public readonly array $hintColumns,
        public readonly ?string $permission,
        public readonly bool $creatable,
        public readonly array $where = [],
    ) {}

    public function isEmployee(): bool
    {
        return $this->key === MasterCatalogue::EMPLOYEE;
    }

    public function isCompany(): bool
    {
        return $this->key === MasterCatalogue::COMPANY;
    }

    public function lower(): string
    {
        return mb_strtolower($this->label);
    }
}
