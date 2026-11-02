<?php

namespace App\Domain\People\Actions;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Concerns\ChangesProfileData;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Models\PersonNomination;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HROne parity MS-4 (matrix 22 §22.2.3 #10, #12–14): the one write path for a person's nominations. The nominations of
 * one benefit (option list `nomination_benefits`) are saved together, so the rule "the shares of a benefit total 100%"
 * is checked on the whole set when it is saved:
 *
 * - every nominee is one of the person's own family members, at most once per benefit;
 * - each share is more than 0 and at most 100, with two decimals; the shares total exactly 100 (or there are none: the
 *   benefit's nominations are removed);
 * - a minor nominee (younger than peopleos.people.nominee_majority_age on the family member's date of birth) needs the
 *   guardian's name and relation.
 *
 * Needs employee.update within organisation scope (a self-service change passes through ProfileChanges and its grant);
 * audited per nomination; one timeline entry and the event employee.nomination_changed (no values) per saved set.
 */
final class ChangeNominationsAction
{
    use ChangesProfileData;

    public const PERMISSION = 'employee.update';

    public const FIELDS = ['family_member_id', 'share', 'address', 'remarks', 'guardian_name', 'guardian_relation'];

    public function __construct(private readonly ProfileChangeGuard $guard) {}

    /** @return array<string, mixed> the rules of one nomination row */
    public function rowRules(): array
    {
        return [
            'family_member_id' => ['required', 'integer'],
            'share' => ['required', 'numeric', 'gt:0', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_relation' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * Check a benefit's nominations without saving them; returns the clean rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function check(Employee $employee, string $benefit, array $rows): array
    {
        $benefits = app(OptionLists::class)->labels('nomination_benefits');
        if (! array_key_exists($benefit, $benefits)) {
            throw new ProfileChangeRefused('Choose the benefit the nomination is for from the list.');
        }
        $label = (string) $benefits[$benefit];
        $members = PersonFamilyMember::query()->where('person_id', $employee->person_id)->get()->keyBy('id');
        $clean = [];
        $seen = [];
        $total = 0;
        foreach (array_values($rows) as $row) {
            $row = array_intersect_key((array) $row, array_flip(self::FIELDS));
            $row = array_map(fn ($v) => is_string($v) ? (trim(strip_tags($v)) === '' ? null : trim(strip_tags($v))) : $v, $row);
            if (array_filter($row, fn ($v) => $v !== null) === []) {
                continue; // an empty row
            }
            $row = $this->validated($row, $this->rowRules());
            $member = $members->get((int) $row['family_member_id'])
                ?? throw new ProfileChangeRefused('Nominees are chosen from the family members on the profile.');
            if (isset($seen[$member->id])) {
                throw new ProfileChangeRefused("{$member->name} is nominated twice for {$label}.");
            }
            $seen[$member->id] = true;
            if (abs(round((float) $row['share'], 2) - (float) $row['share']) > 0.000001) {
                throw new ProfileChangeRefused("Give {$member->name}'s share for {$label} with at most two decimals.");
            }
            if ($member->isMinor() && (blank($row['guardian_name'] ?? null) || blank($row['guardian_relation'] ?? null))) {
                throw new ProfileChangeRefused("{$member->name} is a minor: give the guardian's name and relation to the nominee for {$label}.");
            }
            $total += (int) round((float) $row['share'] * 100);
            $clean[] = ['family_member_id' => $member->id, 'share' => round((float) $row['share'], 2)] + array_intersect_key($row, array_flip(['address', 'remarks', 'guardian_name', 'guardian_relation']));
        }
        if ($clean !== [] && $total !== 10000) {
            throw new ProfileChangeRefused('The shares for '.$label.' total '.rtrim(rtrim(number_format($total / 100, 2, '.', ''), '0'), '.').'%: they must total 100%.');
        }

        return $clean;
    }

    /**
     * Replace the nominations of one benefit with $rows (an empty list removes them).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, PersonNomination>
     */
    public function replace(Employee $employee, string $benefit, array $rows, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): Collection
    {
        $this->guard->authorize($actor, $employee, self::PERMISSION, $origin);
        $clean = $this->check($employee, $benefit, $rows);

        return DB::transaction(function () use ($employee, $benefit, $clean, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            $existing = PersonNomination::query()->where('person_id', $employee->person_id)->where('benefit', $benefit)->lockForUpdate()->get()->keyBy('family_member_id');
            $kept = [];
            $changed = false;
            foreach ($clean as $row) {
                $nomination = $existing->get($row['family_member_id']) ?? new PersonNomination(['person_id' => $employee->person_id, 'benefit' => $benefit]);
                $nomination->fill(array_replace(array_fill_keys(['address', 'remarks', 'guardian_name', 'guardian_relation'], null), $row));
                if (! $nomination->exists || $nomination->isDirty()) {
                    $nomination->withAuditReason($reason, $origin?->reference)->save();
                    $changed = true;
                }
                $kept[] = $nomination->getKey();
            }
            foreach ($existing as $nomination) {
                if (! in_array($nomination->getKey(), $kept, true)) {
                    $nomination->withAuditReason($reason, $origin?->reference)->delete();
                    $changed = true;
                }
            }
            if ($changed) {
                $label = (string) app(OptionLists::class)->label('nomination_benefits', $benefit);
                $person = Person::query()->findOrFail($employee->person_id);
                $this->recordChange($employee, 'employee.nomination_changed', 'personal', "Nominations for {$label} ".($clean === [] ? 'removed' : 'saved'), $person, $clean === [] ? 'removed' : 'updated', $origin);
            }

            return PersonNomination::query()->where('person_id', $employee->person_id)->where('benefit', $benefit)->orderBy('id')->get();
        });
    }

    /** @return array<string, float> benefit => total share on file (to show sets that do not total 100, e.g. after a family member left) */
    public static function totals(Employee $employee): array
    {
        return PersonNomination::query()->where('person_id', $employee->person_id)->get()
            ->groupBy('benefit')->map(fn ($rows) => round((float) $rows->sum('share'), 2))->all();
    }
}
