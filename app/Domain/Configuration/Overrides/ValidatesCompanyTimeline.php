<?php

namespace App\Domain\Configuration\Overrides;

use Illuminate\Database\Eloquent\Model;

/**
 * An OverrideFields provider whose fields must agree with each other on every date (remediation I.6: a designation's
 * level and grade). ConfigurationOverrides takes a snapshot before a change and checks the stored result after it,
 * inside the same transaction, so a refusal writes nothing. Inside ConfigurationOverrides::together() the check runs
 * once, after every change of the group (no refusal of a valid change made in two steps).
 */
interface ValidatesCompanyTimeline
{
    /** The fields this check is about: changing any other field needs no check. @return list<string> */
    public function timelineFields(): array;

    /** What must hold for the company from $from, before the change. */
    public function timelineSnapshot(Model $record, int $companyId, string $from): array;

    /** Refuses (ApplicabilityRefusedException) when the stored values now disagree on a date from $from (and did not before). */
    public function validateCompanyTimeline(Model $record, int $companyId, string $from, array $before): void;
}
