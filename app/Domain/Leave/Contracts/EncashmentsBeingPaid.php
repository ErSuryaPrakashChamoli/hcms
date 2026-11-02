<?php

namespace App\Domain\Leave\Contracts;

/**
 * HROne parity PO-8: which approved encashments of an employee a pay run that is not final yet has already taken (it
 * marks them paid when it becomes final). Undoing such an encashment would leave the run paying days that are back in
 * the balance, so a batch unprocess leaves them as they are. Bound by the paying module; Leave never reads its records.
 */
interface EncashmentsBeingPaid
{
    /** @return array<int, string> encashment id => where it is being paid ("the run of Nov 2026") */
    public function for(int $employeeId): array;
}
