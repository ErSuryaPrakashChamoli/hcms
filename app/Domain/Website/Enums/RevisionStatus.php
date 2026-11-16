<?php

namespace App\Domain\Website\Enums;

/** A website revision: the one working draft, the one live copy, or an earlier live copy kept as history. */
enum RevisionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Live',
            self::Archived => 'Earlier version',
        };
    }
}
