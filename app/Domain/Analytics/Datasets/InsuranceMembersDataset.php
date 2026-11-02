<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\People\Models\InsuredMember;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity MS-5 (HROne's health insurance list): one row per person a health insurance policy covers — the employee
 * or a family member — with the policy (insurer, code, policy number, sum assured, issued, valid until, days left, e-card
 * on file) and the member (name, relation, date of birth, age, gender, parents' cover, whether a member id is recorded).
 *
 * For HR who may see sensitive employee data (employee.sensitive.view, as the 360's Health insurance tab), in the reader's
 * access scope (the members' own AccessScope). The policy number, sum assured and the member's date of birth and age are
 * sensitive fields of that same right, so a run without a reader (the warehouse feed) leaves them out. The insurer's
 * member id itself is never a field: the report says only whether one is on file.
 */
class InsuranceMembersDataset extends Dataset
{
    public function key(): string
    {
        return 'insurance_members';
    }

    public function label(): string
    {
        return 'Health insurance members';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['employee.sensitive.view'];
    }

    public function sensitivePermission(): ?string
    {
        return 'employee.sensitive.view';
    }

    public function fields(): array
    {
        return [
            ...$this->employeeFields(fn (InsuredMember $m) => $m->employee),
            'insurer' => ['label' => 'Insurance company', 'type' => 'string', 'value' => fn (InsuredMember $m) => $m->policy?->insurer_name],
            'insurer_code' => ['label' => 'Insurance company code', 'type' => 'string', 'value' => fn (InsuredMember $m) => $m->policy?->insurer_code],
            'policy_number' => ['label' => 'Policy number', 'type' => 'string', 'sensitive' => true, 'value' => fn (InsuredMember $m) => $m->policy?->policy_number],
            'sum_assured' => ['label' => 'Sum assured', 'type' => 'number', 'sensitive' => true, 'value' => fn (InsuredMember $m) => $m->policy?->sum_assured === null ? null : round((float) $m->policy->sum_assured, 2)],
            'issued_on' => ['label' => 'Issue date', 'type' => 'date', 'value' => fn (InsuredMember $m) => $m->policy?->issued_on],
            'valid_until' => ['label' => 'Valid until', 'type' => 'date', 'value' => fn (InsuredMember $m) => $m->policy?->valid_until],
            'days_to_expiry' => ['label' => 'Days until the cover ends', 'type' => 'number', 'value' => fn (InsuredMember $m) => self::daysFromToday($m->policy?->valid_until)],
            'has_ecard' => ['label' => 'E-card on file', 'type' => 'boolean', 'value' => fn (InsuredMember $m) => (bool) $m->policy?->hasEcard()],
            'member' => ['label' => 'Insured person', 'type' => 'string', 'value' => fn (InsuredMember $m) => $m->name],
            'relation' => ['label' => 'Relation', 'type' => 'string', 'value' => fn (InsuredMember $m) => $m->relationLabel()],
            'is_self' => ['label' => 'The employee', 'type' => 'boolean', 'value' => fn (InsuredMember $m) => $m->isSelf()],
            'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date', 'sensitive' => true, 'value' => fn (InsuredMember $m) => $m->date_of_birth],
            'age' => ['label' => 'Age', 'type' => 'number', 'sensitive' => true, 'value' => fn (InsuredMember $m) => $m->date_of_birth ? (int) $m->date_of_birth->diffInYears(now()) : null],
            'gender' => ['label' => 'Gender', 'type' => 'string', 'value' => fn (InsuredMember $m) => $m->genderLabel()],
            'is_parents_cover' => ['label' => 'Parents’ cover', 'type' => 'boolean', 'value' => fn (InsuredMember $m) => (bool) $m->is_parents_cover],
            'has_member_id' => ['label' => 'Member id on file', 'type' => 'boolean', 'value' => fn (InsuredMember $m) => filled($m->getAttributes()['member_id_last4'] ?? null)],
            'count' => ['label' => 'Insured people (1 per row)', 'type' => 'number', 'value' => fn (InsuredMember $m) => 1],
        ];
    }

    public function query(): Builder
    {
        // The members' own access scope (employee_id) applies the reader's organisation scope.
        return InsuredMember::query()->with(['policy', ...self::employeeWith('employee')])->orderBy('employee_id')->orderBy('policy_id')->orderBy('id');
    }
}
