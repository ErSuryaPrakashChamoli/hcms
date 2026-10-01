<?php

namespace App\Domain\Career\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Career\Events\CareerEvent;
use App\Domain\Career\Models\CareerAspirationEntry;
use App\Domain\Career\Models\CareerGoal;
use App\Domain\Career\Models\CareerProfile;
use App\Domain\Career\Models\MobilityInterest;
use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\CareerAspiration;
use App\Domain\Talent\Services\TalentAccess;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9 career self-service: the career profile (optimistic lock), effective-dated aspirations
 * (a new one supersedes the current one of the same term — never overwritten), career goals and
 * mobility interests. Written by the employee (career.self) or career.manage within scope. Nothing
 * here promotes, transfers, changes pay or creates a recruitment record.
 */
final class CareerProfiles
{
    public function __construct(private readonly TalentAccess $access, private readonly AuditRecorder $audit) {}

    public function profileFor(Employee $employee): CareerProfile
    {
        return CareerProfile::query()->withoutGlobalScope(AccessScope::class)->firstOrCreate(['employee_id' => $employee->id]);
    }

    /** @param  array<string, mixed>  $data */
    public function updateProfile(Employee $employee, array $data, ?int $expectedVersion, User $actor): CareerProfile
    {
        $this->authorise($actor, $employee);
        $data = array_intersect_key($data, array_flip(['career_track_id', 'preferred_job_family_ids', 'preferred_location_ids', 'target_designation_ids', 'mobility', 'development_priorities', 'share_aspirations_with_manager', 'share_goals_with_manager', 'share_mobility_with_manager']));
        if (isset($data['mobility'])) {
            $unknown = array_diff(array_keys(array_filter((array) $data['mobility'])), array_keys(config('peopleos.career.mobility_options')));
            if ($unknown !== []) {
                throw new RuntimeException('Unknown mobility option: '.implode(', ', $unknown).'.');
            }
        }
        if (! $this->access->self($actor, $employee->id)) {
            // Sharing choices belong to the employee.
            unset($data['share_aspirations_with_manager'], $data['share_goals_with_manager'], $data['share_mobility_with_manager']);
        }

        return DB::transaction(function () use ($employee, $data, $expectedVersion, $actor) {
            $this->profileFor($employee);
            $profile = CareerProfile::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && (int) $profile->lock_version !== $expectedVersion) {
                throw new RuntimeException('The career profile was changed meanwhile. Reload and try again.');
            }
            $profile->update([...$data, 'updated_by' => $actor->id]);
            CareerEvent::dispatch('career.profile.updated', $employee, $profile, []);

            return $profile;
        });
    }

    /** @param  array{target_designation_id?: ?int, target_job_family_id?: ?int, target_location_id?: ?int, career_track_id?: ?int, aspiration?: ?string, direction?: ?string, effective_from?: ?string}  $data */
    public function recordAspiration(Employee $employee, string $term, array $data, User $actor): CareerAspirationEntry
    {
        $this->authorise($actor, $employee);
        if (blank($data['aspiration'] ?? null) && empty($data['target_designation_id']) && empty($data['target_job_family_id'])) {
            throw new RuntimeException('Describe the aspiration or name a target role or job family.');
        }
        $from = now()->parse($data['effective_from'] ?? now())->toDateString();

        return DB::transaction(function () use ($employee, $term, $data, $actor, $from) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->id)->lockForUpdate()->first();
            CareerAspirationEntry::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('term', $term)->where('status', 'current')->lockForUpdate()->get()
                ->each(fn (CareerAspirationEntry $e) => $e->update(['status' => 'superseded', 'effective_to' => now()->parse($from)->subDay()->max($e->effective_from)->toDateString(), 'updated_by' => $actor->id]));
            $entry = CareerAspirationEntry::query()->create([
                'employee_id' => $employee->id, 'term' => $term, 'effective_from' => $from, 'created_by' => $actor->id, 'updated_by' => $actor->id,
                ...array_intersect_key($data, array_flip(['target_designation_id', 'target_job_family_id', 'target_location_id', 'career_track_id', 'aspiration', 'direction'])),
            ]);
            // The legacy one-row aspiration (Career Passport) mirrors the newest entry; it is derived, not a second source.
            CareerAspiration::query()->withoutGlobalScope(AccessScope::class)->updateOrCreate(['employee_id' => $employee->id], [
                'target_designation_id' => $entry->target_designation_id, 'aspirations' => $entry->aspiration,
                'open_to_relocation' => (bool) ($this->profileFor($employee)->mobility['relocation'] ?? false),
            ]);
            CareerEvent::dispatch('career.aspiration.updated', $employee, $entry, ['term' => $term], [], []);

            return $entry;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function createGoal(Employee $employee, string $title, string $type, array $data, User $actor): CareerGoal
    {
        $this->authorise($actor, $employee);
        if (trim($title) === '') {
            throw new RuntimeException('A career goal needs a title.');
        }
        if (isset($data['target_level']) && $data['target_level'] !== null && empty($data['skill_id'])) {
            throw new RuntimeException('A target level belongs to a skill.');
        }
        if (! empty($data['development_plan_id']) && ! DevelopmentPlan::query()->withoutGlobalScope(AccessScope::class)->whereKey($data['development_plan_id'])->where('employee_id', $employee->id)->exists()) {
            throw new RuntimeException('That development plan belongs to another employee.');
        }
        $goal = CareerGoal::query()->create([
            'employee_id' => $employee->id, 'title' => $title, 'goal_type' => $type, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ...array_intersect_key($data, array_flip(['description', 'target_designation_id', 'skill_id', 'target_level', 'competency_id', 'course_id', 'learning_path_id', 'development_plan_id', 'target_date'])),
        ]);
        CareerEvent::dispatch('career.goal.created', $employee, $goal, ['title' => $title], [], []);

        return $goal;
    }

    public function transitionGoal(CareerGoal $goal, string $to, ?string $note, User $actor): CareerGoal
    {
        $this->authorise($actor, $goal->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail());

        return DB::transaction(function () use ($goal, $to, $note, $actor) {
            $current = CareerGoal::query()->withoutGlobalScope(AccessScope::class)->whereKey($goal->id)->lockForUpdate()->firstOrFail();
            $goal->setRawAttributes($current->getAttributes(), true);
            $goal->update(['status' => $to, 'updated_by' => $actor->id, ...(in_array($to, ['achieved', 'abandoned'], true) ? ['closed_at' => now(), 'closure_note' => $note] : [])]);

            return $goal;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function addMobilityInterest(Employee $employee, string $type, array $data, User $actor): MobilityInterest
    {
        $this->authorise($actor, $employee);

        return MobilityInterest::query()->create([
            'employee_id' => $employee->id, 'interest_type' => $type, 'effective_from' => now()->toDateString(), 'created_by' => $actor->id,
            ...array_intersect_key($data, array_flip(['designation_id', 'job_family_id', 'department_id', 'location_id', 'career_track_id', 'notes'])),
        ]);
    }

    public function withdrawMobilityInterest(MobilityInterest $interest, User $actor): MobilityInterest
    {
        $this->authorise($actor, $interest->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail());
        $fresh = MobilityInterest::query()->withoutGlobalScope(AccessScope::class)->whereKey($interest->id)->firstOrFail();
        $fresh->update(['status' => 'withdrawn', 'effective_to' => now()->toDateString()]);
        $interest->setRawAttributes($fresh->getAttributes(), true);

        return $interest;
    }

    private function authorise(User $actor, Employee $employee): void
    {
        if (! $this->access->mayEditCareer($actor, $employee->id)) {
            throw new RuntimeException('Career information is kept by the employee (or career administrators within scope).');
        }
    }
}
