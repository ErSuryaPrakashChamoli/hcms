<?php

namespace App\Filament\Support\Forms;

use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Model;

/**
 * Filament's file upload, bound in its place (AppServiceProvider), with one change to the path-tampering guard the
 * project turns on for every upload (QA audit 2026-11-12): the guard compares a submitted path with the files the
 * form's record already holds, read from the record's attribute of the field's name. An action's upload on a table
 * row (supporting documents when regularising an attendance day, an attachment when replying on a ticket) has a
 * record without that column, and reading a missing attribute fails on a strict model (2026-11-20). Such a record
 * holds no files for the field, so nothing submitted is pre-approved: the guard still refuses any path not uploaded
 * in the form itself.
 */
class PeopleFileUpload extends FileUpload
{
    /** @return array<string> */
    public function getOriginalFilePaths(): array
    {
        $record = $this->getRecord();
        if ($record instanceof Model && ! $record->hasAttribute($this->getName())) {
            return [];
        }

        return parent::getOriginalFilePaths();
    }
}
