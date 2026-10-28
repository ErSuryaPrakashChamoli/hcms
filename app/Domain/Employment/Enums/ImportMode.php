<?php

namespace App\Domain\Employment\Enums;

use App\Domain\Employment\Imports\EmployeeImport;

/**
 * What a bulk upload is for, chosen by the person uploading: records not on file yet (New), records already
 * on file (Update), or both. A row outside the chosen mode is skipped with its reason, never written.
 */
enum ImportMode: string
{
    case New = 'new';
    case Update = 'update';
    case Both = 'both';

    /** The mode an import was mapped with. Files staged before modes existed carry on_duplicate (skip = new only). */
    public static function of(EmployeeImport $import): self
    {
        $options = $import->options ?? [];

        return self::tryFrom((string) ($options['mode'] ?? '')) ?? (($options['on_duplicate'] ?? null) === 'skip' ? self::New : self::Both);
    }

    public function adds(): bool
    {
        return $this !== self::Update;
    }

    public function updates(): bool
    {
        return $this !== self::New;
    }
}
