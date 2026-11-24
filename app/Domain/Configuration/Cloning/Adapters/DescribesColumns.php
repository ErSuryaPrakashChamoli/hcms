<?php

namespace App\Domain\Configuration\Cloning\Adapters;

/** A clone adapter whose type names and codes its records in columns other than `name` and `code`. */
interface DescribesColumns
{
    public function nameColumn(): string;

    public function codeColumn(): string;
}
