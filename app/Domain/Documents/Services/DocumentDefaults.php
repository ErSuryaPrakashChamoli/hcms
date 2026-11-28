<?php

namespace App\Domain\Documents\Services;

use App\Domain\Documents\Models\DocumentType;

/**
 * The document types an organisation starts with (peopleos.documents.defaults: identity, education from 10th to
 * doctorate, previous employment, tax, statutory forms, company letters, training, medical, exit and personal papers).
 * Seeding adds only the types an organisation does not have yet (by code): a type it already has, renamed, switched off
 * or set up its own way, is never changed. Runs for the bound tenant.
 */
final class DocumentDefaults
{
    /** @return int how many types were added */
    public function seed(): int
    {
        $added = 0;
        foreach ((array) config('peopleos.documents.defaults', []) as $row) {
            $type = DocumentType::query()->firstOrCreate(['code' => $row['code']], $row);
            $added += $type->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }
}
