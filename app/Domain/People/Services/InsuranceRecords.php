<?php

namespace App\Domain\People\Services;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Concerns\ChangesProfileData;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\People\Models\InsurancePolicy;
use App\Domain\People\Models\InsuredMember;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonFamilyMember;
use App\Support\Storage\FileSafety;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * HROne parity MS-5 (matrix 22 §22.2.3 #3, §22.2.5 #1–7): the one write path for an employee's health insurance —
 * policies (insurer name and code, policy number, sum assured, issue date, valid until, e-card) and the people each covers
 * (the employee or one of the person's own family members, with the insurer's member id, relation, date of birth, gender
 * and the parents' cover flag).
 *
 * Like the bank and identity tabs this is sensitive data: every change needs employee.sensitive.update within
 * organisation scope, whatever the caller (Employee 360, the insurance upload, an approved or self-service profile change
 * — the last through ProfileChanges and its SelfServiceGrant). The member id is encrypted at rest and masked in the audit;
 * the timeline entry ("personal") and the event employee.insurance_changed carry no values.
 *
 * Rules: one policy number per employee; the cover ends no earlier than it starts; a member is the employee ("self") or a
 * family member of the same person, once per policy; name and relation come from that person / family member, a date of
 * birth or gender given here wins over theirs; the parents' cover is for family members only. No premium, tax or
 * statutory amount is computed here.
 */
final class InsuranceRecords
{
    use ChangesProfileData;

    public const PERMISSION = 'employee.sensitive.update';

    public const EVENT = 'employee.insurance_changed';

    /** The upload area on the documents disk a form puts an e-card in before attachEcard() moves it. */
    public const UPLOAD_AREA = 'insurance-ecards';

    public const POLICY_FIELDS = ['insurer_name', 'insurer_code', 'policy_number', 'sum_assured', 'issued_on', 'valid_until'];

    public const MEMBER_FIELDS = ['family_member_id', 'member_id', 'date_of_birth', 'gender', 'is_parents_cover'];

    public function __construct(private readonly ProfileChangeGuard $guard) {}

    /** @return array<string, mixed> */
    public function policyRules(): array
    {
        return [
            'insurer_name' => ['required', 'string', 'max:255'],
            'insurer_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \-\/._]*$/'],
            'policy_number' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \-\/._]*$/'],
            'sum_assured' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'valid_until' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, mixed> */
    public function memberRules(): array
    {
        return [
            'family_member_id' => ['nullable', 'integer'],
            'member_id' => ['nullable', 'string', 'min:2', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \-\/._]*$/'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', 'in:'.implode(',', array_keys((array) config('peopleos.people.genders', [])))],
            'is_parents_cover' => ['nullable', 'boolean'],
        ];
    }

    // -- policies ---------------------------------------------------------------------------------------------------

    /**
     * Add a policy with the members it covers, or change one (null $policy adds). $members are rows of MEMBER_FIELDS plus
     * an optional `record_id` (a member of this policy to change; a blank member id keeps the one on file). With
     * $removeOthers, members of the policy not in $members are removed (the HR form); without, they stay (self-service).
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $members
     */
    public function save(Employee $employee, ?InsurancePolicy $policy, array $data, array $members, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null, bool $removeOthers = false): InsurancePolicy
    {
        $policy !== null && $this->assertPolicyBelongs($employee, $policy);
        $this->guard->authorize($actor, $employee, self::PERMISSION, $origin);
        $clean = $this->checkPolicy($data, $policy);
        $rows = $this->checkMembers($employee, $policy, $members);

        return DB::transaction(function () use ($employee, $policy, $clean, $rows, $reason, $origin, $removeOthers) {
            $employee = $this->lockEmployee($employee);
            $adding = $policy === null;
            $duplicate = InsurancePolicy::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())
                ->where('policy_number', $clean['policy_number'] ?? $policy?->policy_number)->when($policy, fn ($q) => $q->whereKeyNot($policy->getKey()))->lockForUpdate()->exists();
            if ($duplicate) {
                throw new ProfileChangeRefused('Policy '.($clean['policy_number'] ?? $policy?->policy_number).' is already on file for this employee: change that one instead.');
            }
            $policy ??= new InsurancePolicy(['employee_id' => $employee->getKey()]);
            $policy->fill($clean);
            $changed = $adding || $policy->isDirty();
            if ($changed) {
                $policy->withAuditReason($reason, $origin?->reference)->save();
            }
            $kept = [];
            foreach ($rows as $row) {
                $member = $this->writeMember($employee, $policy, $row, $reason, $origin);
                $kept[] = $member->getKey();
                $changed = $changed || $member->wasRecentlyCreated || $member->wasChanged();
            }
            if ($removeOthers) {
                InsuredMember::query()->withoutGlobalScope(AccessScope::class)->where('policy_id', $policy->getKey())->whereNotIn('id', $kept ?: [0])->get()
                    ->each(function (InsuredMember $m) use ($reason, $origin, &$changed): void {
                        $m->withAuditReason($reason, $origin?->reference)->delete();
                        $changed = true;
                    });
            }
            if ($changed) {
                $this->recordChange($employee, self::EVENT, 'personal', 'Health insurance '.($adding ? 'added' : 'updated').': '.$policy->insurer_name, $policy, $adding ? 'added' : 'updated', $origin);
            }

            return $policy;
        });
    }

    /** Remove a policy, its members (each audited) and its e-card file. */
    public function remove(Employee $employee, InsurancePolicy $policy, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): void
    {
        $this->assertPolicyBelongs($employee, $policy);
        $this->guard->authorize($actor, $employee, self::PERMISSION);
        $file = $policy->getAttributes()['ecard_path'] ?? null;

        DB::transaction(function () use ($employee, $policy, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            InsuredMember::query()->withoutGlobalScope(AccessScope::class)->where('policy_id', $policy->getKey())->get()
                ->each(fn (InsuredMember $m) => $m->withAuditReason($reason, $origin?->reference)->delete());
            $policy->withAuditReason($reason, $origin?->reference)->delete();
            $this->recordChange($employee, self::EVENT, 'personal', 'Health insurance removed: '.$policy->insurer_name, $policy, 'removed', $origin);
        });
        $this->deleteFile($file);
    }

    /** Remove one member from a policy. */
    public function removeMember(Employee $employee, InsuredMember $member, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): void
    {
        if ((int) $member->employee_id !== (int) $employee->getKey()) {
            throw new ProfileChangeRefused('That record does not belong to this employee.');
        }
        $this->guard->authorize($actor, $employee, self::PERMISSION);
        DB::transaction(function () use ($employee, $member, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            $member->withAuditReason($reason, $origin?->reference)->delete();
            $policy = InsurancePolicy::query()->withoutGlobalScope(AccessScope::class)->findOrFail($member->policy_id);
            $this->recordChange($employee, self::EVENT, 'personal', 'Health insurance updated: '.$policy->insurer_name, $policy, 'updated', $origin);
        });
    }

    /**
     * Attach (or replace) the policy's e-card. The form's upload sits in UPLOAD_AREA on the documents disk; it is checked
     * like every user upload (allowed type, size, content sniffing — FileSafety) and moved under the employee's folder
     * (tenants/{tenant}/employees/{employee}/insurance/). The earlier file is removed; the change is audited.
     */
    public function attachEcard(Employee $employee, InsurancePolicy $policy, string $upload, ?string $originalName, User $actor, ?string $reason = null): InsurancePolicy
    {
        $this->assertPolicyBelongs($employee, $policy);
        $this->guard->authorize($actor, $employee, self::PERMISSION);
        $disk = Storage::disk(self::disk());
        $source = ltrim(str_replace(['\\', '..'], ['/', ''], $upload), '/');
        if (! str_starts_with($source, self::UPLOAD_AREA.'/') || ! $disk->exists($source)) {
            throw new ProfileChangeRefused('The e-card upload was not found.');
        }
        $original = filled($originalName) ? (string) $originalName : basename($source);
        try {
            $extension = FileSafety::assertAllowed($original, (int) $disk->size($source), FileSafety::sniff((string) $disk->get($source)));
        } catch (RuntimeException $e) {
            $disk->delete($source);
            throw new ProfileChangeRefused($e->getMessage());
        }
        $path = "tenants/{$employee->tenant_id}/employees/{$employee->getKey()}/insurance/".Str::ulid().'.'.$extension;
        $disk->move($source, $path);
        $earlier = $policy->getAttributes()['ecard_path'] ?? null;
        $policy->withAuditReason($reason ?? 'E-card attached')->update(['ecard_path' => $path, 'ecard_name' => Str::limit(basename($original), 250, '')]);
        if (is_string($earlier) && $earlier !== $path) {
            $this->deleteFile($earlier);
        }

        return $policy;
    }

    public static function disk(): string
    {
        return (string) config('peopleos.documents.disk', 'local');
    }

    // -- MS-3 self-service ("Health insurance" on My profile) -------------------------------------------------------

    /**
     * The operation a "Health insurance" form makes: one policy (an own one by `policy_id`, or a new one) with its policy
     * fields and member rows. Nothing changed = no operation. Members are added or changed, never removed (HR removes).
     *
     * @param  array<string, mixed>  $input
     * @return list<array{kind: string, record_id: ?int, values: array<string, mixed>, changed: list<string>}>
     */
    public function operations(Employee $employee, array $input): array
    {
        $policyId = filled($input['policy_id'] ?? null) ? (int) $input['policy_id'] : null;
        $policy = $policyId === null ? null : $this->ownPolicy($employee, $policyId);
        $values = $this->checkPolicy(array_intersect_key($input, array_flip(self::POLICY_FIELDS)), $policy);
        $rows = $this->checkMembers($employee, $policy, array_values((array) ($input['members'] ?? [])));
        $current = $this->current($employee, $policyId);
        $changed = array_values(array_filter(self::POLICY_FIELDS, fn (string $f) => array_key_exists($f, $values) && self::norm($values[$f]) !== self::norm($current[$f] ?? null)));
        $memberChanges = array_filter($rows, fn (array $row) => $row['changed']);
        if ($memberChanges !== []) {
            $changed[] = 'members';
        }
        if ($changed === []) {
            return [];
        }
        $rows = array_values(array_map(fn (array $r) => array_diff_key($r, ['changed' => true]), $memberChanges));

        return [['kind' => 'insurance', 'record_id' => $policyId, 'values' => [...($values + array_intersect_key($current, array_flip(self::POLICY_FIELDS))), 'rows' => $rows,
            'members' => $this->summary($employee, $policyId, $rows)], 'changed' => $changed]];
    }

    /** Apply a self-service operation (in the approver's name, or the employee's under the request's grant). */
    public function apply(array $op, Employee $employee, User $actor, string $reason, ChangeOrigin $origin): InsurancePolicy
    {
        $policy = filled($op['record_id'] ?? null) ? $this->ownPolicy($employee, (int) $op['record_id']) : null;
        $values = (array) $op['values'];

        return $this->save($employee, $policy, array_intersect_key($values, array_flip(self::POLICY_FIELDS)), (array) ($values['rows'] ?? []), $actor, $reason, $origin);
    }

    /**
     * The policy as it is now, for "earlier" values: the policy fields and the members summary (member ids masked).
     *
     * @return array<string, mixed>
     */
    public function current(Employee $employee, ?int $policyId): array
    {
        if ($policyId === null) {
            return [];
        }
        $policy = InsurancePolicy::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->find($policyId);
        if ($policy === null) {
            return [];
        }

        return ['insurer_name' => $policy->insurer_name, 'insurer_code' => $policy->insurer_code, 'policy_number' => $policy->policy_number,
            'sum_assured' => $policy->sum_assured === null ? null : self::amount($policy->sum_assured), 'issued_on' => $policy->issued_on?->toDateString(),
            'valid_until' => $policy->valid_until?->toDateString(), 'members' => $this->summary($employee, $policyId, [])];
    }

    /**
     * "Asha Rao (self, ••••1234), Kiran Rao (spouse), Ravi Rao (father, ••••4321, parents' cover)" — the members of a policy
     * after $rows are applied; member ids only ever masked.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function summary(Employee $employee, ?int $policyId, array $rows): ?string
    {
        $members = $policyId === null ? collect() : InsuredMember::query()->withoutGlobalScope(AccessScope::class)->where('policy_id', $policyId)->orderBy('id')->get()->keyBy('id');
        $person = Person::query()->find($employee->person_id);
        $family = PersonFamilyMember::query()->where('person_id', $employee->person_id)->get()->keyBy('id');
        $lines = $members->map(fn (InsuredMember $m) => ['name' => $m->name, 'relation' => $m->relationLabel(), 'masked' => $m->maskedMemberId(), 'parents' => (bool) $m->is_parents_cover])->all();
        foreach ($rows as $row) {
            $source = filled($row['family_member_id'] ?? null) ? $family->get((int) $row['family_member_id']) : null;
            $existing = filled($row['record_id'] ?? null) ? $members->get((int) $row['record_id']) : null;
            $line = ['name' => $source?->name ?? (string) $person?->full_name,
                'relation' => $source ? (string) (app(OptionLists::class)->label('family_relations', (string) $source->relation) ?? $source->relation) : 'Self',
                'masked' => filled($row['member_id'] ?? null) ? '••••'.mb_substr((string) $row['member_id'], -4) : $existing?->maskedMemberId(),
                'parents' => (bool) ($row['is_parents_cover'] ?? false)];
            if ($existing !== null) {
                $lines[$existing->getKey()] = $line;
            } else {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            return null;
        }

        return collect($lines)->map(fn (array $l) => $l['name'].' ('.collect([mb_strtolower($l['relation']), $l['masked'], $l['parents'] ? 'parents’ cover' : null])->filter()->implode(', ').')')->implode(', ');
    }

    // -- checks -----------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the clean policy fields (what an update leaves out keeps what is on file) */
    private function checkPolicy(array $data, ?InsurancePolicy $policy): array
    {
        $data = self::clean(array_intersect_key($data, array_flip(self::POLICY_FIELDS)));
        foreach (['insurer_code', 'policy_number'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = mb_strtoupper((string) $data[$field]);
            }
        }
        $merged = $data + ($policy === null ? [] : ['insurer_name' => $policy->insurer_name, 'policy_number' => $policy->policy_number]);
        $clean = $this->validated($merged, $this->policyRules());
        $issued = array_key_exists('issued_on', $data) ? $data['issued_on'] : $policy?->issued_on?->toDateString();
        $until = array_key_exists('valid_until', $data) ? $data['valid_until'] : $policy?->valid_until?->toDateString();
        if (filled($issued) && filled($until) && strtotime((string) $until) < strtotime((string) $issued)) {
            throw new ProfileChangeRefused('The policy is valid until a date before it was issued.');
        }

        return array_intersect_key($clean, $data + ($policy === null ? $clean : []));
    }

    /**
     * Each member row: validated, the person (self) or one of the person's own family members, once per policy; name and
     * relation from the source; `changed` = it adds a member or differs from the one on file.
     *
     * @param  list<array<string, mixed>>  $members
     * @return list<array<string, mixed>>
     */
    private function checkMembers(Employee $employee, ?InsurancePolicy $policy, array $members): array
    {
        $family = PersonFamilyMember::query()->where('person_id', $employee->person_id)->get()->keyBy('id');
        $person = Person::query()->find($employee->person_id);
        $onFile = $policy === null ? collect() : InsuredMember::query()->withoutGlobalScope(AccessScope::class)->where('policy_id', $policy->getKey())->get()->keyBy('id');
        $out = [];
        $seen = [];
        foreach (array_values($members) as $row) {
            $row = (array) $row;
            $recordId = filled($row['record_id'] ?? null) ? (int) $row['record_id'] : null;
            if (($row['family_member_id'] ?? null) === InsuredMember::SELF) {
                $row['family_member_id'] = null;
            }
            $values = self::clean(array_intersect_key($row, array_flip(self::MEMBER_FIELDS)));
            if (isset($values['member_id'])) {
                $values['member_id'] = mb_strtoupper((string) preg_replace('/\s+/', '', (string) $values['member_id']));
            }
            $values = $this->validated($values, $this->memberRules());
            $existing = null;
            if ($recordId !== null) {
                $existing = $onFile->get($recordId) ?? throw new ProfileChangeRefused('That insured member is not on this policy.');
                if (! array_key_exists('family_member_id', $row)) {
                    $values['family_member_id'] = $existing->family_member_id;
                }
            }
            $familyId = filled($values['family_member_id'] ?? null) ? (int) $values['family_member_id'] : null;
            $source = null;
            if ($familyId !== null) {
                $source = $family->get($familyId) ?? throw new ProfileChangeRefused('An insured family member must be one of the employee’s own family members.');
            }
            $key = InsuredMember::keyFor($familyId);
            if (isset($seen[$key])) {
                throw new ProfileChangeRefused(($source?->name ?? 'The employee').' appears twice on the policy.');
            }
            $seen[$key] = true;
            $clash = $onFile->first(fn (InsuredMember $m) => $m->member_key === $key && $m->getKey() !== $recordId);
            if ($clash !== null) {
                throw new ProfileChangeRefused($clash->name.' is already on this policy.');
            }
            if ($familyId === null && ! empty($values['is_parents_cover'])) {
                throw new ProfileChangeRefused('The parents’ cover is for family members, not the employee.');
            }
            $values['family_member_id'] = $familyId;
            $values['is_parents_cover'] = (bool) ($values['is_parents_cover'] ?? false);
            if ($existing !== null && blank($values['member_id'] ?? null)) {
                unset($values['member_id']); // blank keeps the member id on file
            }
            $effective = self::memberValues($source, $source === null ? $person : null, $employee, $values);
            $changed = $existing === null || collect($effective)->contains(fn ($v, $f) => $f === 'member_id'
                ? (string) $v !== (string) $existing->member_id
                : self::norm($v) !== self::norm($existing->getAttribute($f)));
            $out[] = ['record_id' => $recordId, ...$values, 'changed' => $changed];
        }

        return $out;
    }

    /**
     * What a member row writes: name and relation from the person (self) or the family member; a date of birth or gender
     * given on the row wins over theirs; the member id only when one is given (blank keeps the one on file).
     *
     * @return array<string, mixed>
     */
    private static function memberValues(?PersonFamilyMember $source, ?Person $person, Employee $employee, array $row): array
    {
        $familyId = $source?->getKey();
        $values = [
            'family_member_id' => $familyId,
            'member_key' => InsuredMember::keyFor($familyId),
            'name' => (string) ($source?->name ?? $person?->full_name ?? $employee->employee_code),
            'relation' => $source?->relation ?? InsuredMember::SELF,
            'date_of_birth' => $row['date_of_birth'] ?? ($source ?? $person)?->date_of_birth?->toDateString(),
            'gender' => $row['gender'] ?? ($source ?? $person)?->gender,
            'is_parents_cover' => (bool) ($row['is_parents_cover'] ?? false),
        ];
        if (filled($row['member_id'] ?? null)) {
            $values['member_id'] = (string) $row['member_id'];
        }

        return $values;
    }

    private function writeMember(Employee $employee, InsurancePolicy $policy, array $row, ?string $reason, ?ChangeOrigin $origin): InsuredMember
    {
        $familyId = $row['family_member_id'] ?? null;
        $source = $familyId === null ? null : PersonFamilyMember::query()->where('person_id', $employee->person_id)->findOrFail($familyId);
        $person = $source === null ? Person::query()->findOrFail($employee->person_id) : null;
        $member = filled($row['record_id'] ?? null)
            ? InsuredMember::query()->withoutGlobalScope(AccessScope::class)->where('policy_id', $policy->getKey())->findOrFail((int) $row['record_id'])
            : new InsuredMember(['policy_id' => $policy->getKey(), 'employee_id' => $employee->getKey()]);
        $member->fill(self::memberValues($source, $person, $employee, $row));
        if (! $member->exists || $member->isDirty()) {
            $member->withAuditReason($reason, $origin?->reference)->save();
        }

        return $member;
    }

    private function ownPolicy(Employee $employee, int $id): InsurancePolicy
    {
        return InsurancePolicy::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->find($id)
            ?? throw new ProfileChangeRefused('That policy is not on the profile.');
    }

    private function assertPolicyBelongs(Employee $employee, InsurancePolicy $policy): void
    {
        if ((int) $policy->employee_id !== (int) $employee->getKey()) {
            throw new ProfileChangeRefused('That record does not belong to this employee.');
        }
    }

    private function deleteFile(mixed $path): void
    {
        if (is_string($path) && $path !== '') {
            try {
                Storage::disk(self::disk())->delete($path);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Trim strings, blank → null, dates as Y-m-d. @return array<string, mixed> */
    private static function clean(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if ($value instanceof CarbonInterface) {
                $value = $value->toDateString();
            } elseif (is_string($value)) {
                $value = trim(strip_tags($value));
            }
            $out[(string) $key] = $value === '' ? null : $value;
        }

        return $out;
    }

    private static function norm(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof CarbonInterface => $value->toDateString(),
            is_bool($value) => $value ? '1' : '0',
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( 00:00:00)?$/', $value) === 1 => substr($value, 0, 10),
            is_numeric($value) => self::amount($value),
            default => (string) $value,
        };
    }

    private static function amount(mixed $value): string
    {
        $text = number_format((float) $value, 2, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** @return Collection<int, InsurancePolicy> an employee's policies with their members (newest cover first) */
    public static function policiesOf(Employee $employee): Collection
    {
        return InsurancePolicy::query()->withoutGlobalScope(AccessScope::class)->with(['members' => fn ($q) => $q->withoutGlobalScope(AccessScope::class)->orderBy('id')])
            ->where('employee_id', $employee->getKey())->orderByDesc('valid_until')->orderBy('id')->get()->toBase();
    }

    /** Whole days from $today to the date (for reminders). */
    public static function daysLeft(CarbonInterface $today, mixed $date): int
    {
        return (int) $today->diffInDays(Carbon::parse($date)->startOfDay());
    }
}
