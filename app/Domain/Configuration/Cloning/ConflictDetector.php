<?php

namespace App\Domain\Configuration\Cloning;

/**
 * The checks every clone gets, whatever its type (FR-5, FR-18): each copy has a name and a valid code no other record
 * uses (also not another copy of the same clone), records that look like the copy are pointed out, references with
 * problems are listed, and then the type's own checks (CloneAdapter::conflicts) run for each destination.
 */
final class ConflictDetector
{
    private const CODE = '/^[A-Za-z0-9_-]{1,64}$/';

    /** @return list<Conflict> */
    public function detect(CloneAdapter $adapter, ClonePlan $plan): array
    {
        $conflicts = [];
        $codes = [];
        foreach ($plan->request->destinations as $destination) {
            $company = $destination->companyId;
            if (trim($destination->name) === '') {
                $conflicts[] = Conflict::blocker('name_missing', 'Name the copy.', $company);
            }
            if (! preg_match(self::CODE, $destination->code)) {
                $conflicts[] = Conflict::blocker('code_invalid', "The code \"{$destination->code}\" may use letters, numbers, dashes and underscores only (up to 64).", $company);
            } elseif (in_array(strtolower($destination->code), $codes, true)) {
                $conflicts[] = Conflict::blocker('code_repeated', "Two copies would share the code {$destination->code}; give each its own.", $company);
            } elseif ($adapter->codeTaken($destination->code)) {
                $conflicts[] = Conflict::blocker('code_in_use', "The code {$destination->code} is already in use. Choose another.", $company);
            }
            $codes[] = strtolower($destination->code);
            foreach ($adapter->lookAlikes($plan->snapshot, $destination) as $name) {
                $conflicts[] = Conflict::warning('look_alike', 'A '.strtolower($adapter->label())." named \"{$name}\" already exists. Open it instead if it is the same thing.", $company);
            }
        }
        foreach ($plan->references as $reference) {
            if ($reference->problem !== null) {
                $conflicts[] = Conflict::warning('reference_problem', "{$reference->label}: {$reference->problem}");
            }
        }
        foreach ($plan->request->destinations as $destination) {
            array_push($conflicts, ...$adapter->conflicts($plan->snapshot, $destination, $plan));
        }

        return $conflicts;
    }
}
