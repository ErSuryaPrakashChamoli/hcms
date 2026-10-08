<?php

namespace App\Domain\Learning\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningCompletion;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Learning\Models\LearningProgram;
use App\Domain\Learning\Models\LearningProgramParticipant;
use App\Domain\Learning\Models\LearningProgramVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 8 programs: versioned bundles of courses and paths with eligibility (rule engine), required
 * and optional items and a completion rule. Completion of a program is evaluated from finalized
 * completions only and recorded as its own immutable completion (with an optional certificate).
 */
final class Programs
{
    public function __construct(
        private readonly Learning $learning,
        private readonly Completions $completions,
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{starts_on?: ?string, ends_on?: ?string, eligibility?: ?array, items: list<array{type: string, id: int, required?: bool}>, min_optional?: int, issues_certificate?: bool, validity_months?: ?int}  $content
     */
    public function publish(LearningProgram $program, array $content, ?User $actor = null): LearningProgramVersion
    {
        $items = array_values($content['items'] ?? []);
        if ($items === []) {
            throw new RuntimeException('A program needs at least one item.');
        }
        $seen = [];
        foreach ($items as $item) {
            $key = ($item['type'] ?? '').':'.($item['id'] ?? '');
            if (! in_array($item['type'] ?? null, ['course', 'path'], true) || isset($seen[$key])) {
                throw new RuntimeException('Program items are distinct courses or learning paths.');
            }
            $seen[$key] = true;
            $exists = $item['type'] === 'course' ? Course::query()->whereKey($item['id'])->exists() : LearningPath::query()->whereKey($item['id'])->exists();
            if (! $exists) {
                throw new RuntimeException("Program item {$key} does not exist.");
            }
        }
        $optional = count(array_filter($items, fn ($i) => empty($i['required'])));
        $minOptional = (int) ($content['min_optional'] ?? 0);
        if ($minOptional < 0 || $minOptional > $optional) {
            throw new RuntimeException("The completion rule asks for {$minOptional} optional item(s) but the program has {$optional}.");
        }
        if (! empty($content['starts_on']) && ! empty($content['ends_on']) && Carbon::parse($content['ends_on'])->lt(Carbon::parse($content['starts_on']))) {
            throw new RuntimeException('A program cannot end before it starts.');
        }

        return DB::transaction(function () use ($program, $content, $items, $minOptional, $actor) {
            LearningProgram::query()->whereKey($program->id)->lockForUpdate()->first();
            $version = LearningProgramVersion::query()->create([
                'learning_program_id' => $program->id,
                'version' => (int) LearningProgramVersion::query()->where('learning_program_id', $program->id)->max('version') + 1,
                'starts_on' => $content['starts_on'] ?? null,
                'ends_on' => $content['ends_on'] ?? null,
                'eligibility' => $content['eligibility'] ?? null,
                'items' => array_map(fn ($i) => ['type' => $i['type'], 'id' => (int) $i['id'], 'required' => (bool) ($i['required'] ?? false)], $items),
                'completion_rule' => ['min_optional' => $minOptional],
                'issues_certificate' => (bool) ($content['issues_certificate'] ?? false),
                'validity_months' => $content['validity_months'] ?? null,
                'status' => 'published',
                'published_by' => $actor?->id ?? auth()->id(),
                'published_at' => now(),
            ]);
            $program->update(['current_version_id' => $version->id, 'status' => 'published']);
            $this->audit->record(AuditAction::Create, 'learning', $version, [], null, actor: $actor, metadata: ['event' => 'program_version_published', 'program' => $program->code]);

            return $version;
        });
    }

    public function isEligible(LearningProgramVersion $version, Employee $employee, ?CarbonInterface $on = null): bool
    {
        $on ??= now();
        // Enrolment is open until the program ends (participants may join before it starts).
        if ($version->ends_on && $on->gt($version->ends_on->copy()->endOfDay())) {
            return false;
        }

        return empty($version->eligibility) || $this->rules->matches($version->eligibility, $this->context->build($employee));
    }

    public function enrol(LearningProgram $program, Employee $employee, ?User $actor = null): LearningProgramParticipant
    {
        $version = $program->current_version_id ? LearningProgramVersion::query()->findOrFail($program->current_version_id) : throw new RuntimeException('Publish the program first.');
        if (! $this->isEligible($version, $employee)) {
            throw new RuntimeException('This employee is not eligible for the program (eligibility or dates).');
        }

        return DB::transaction(function () use ($version, $employee, $actor) {
            $participant = LearningProgramParticipant::query()->firstOrCreate(
                ['learning_program_version_id' => $version->id, 'employee_id' => $employee->id],
                ['status' => 'enrolled', 'enrolled_by' => $actor?->id ?? auth()->id(), 'enrolled_at' => now()],
            );
            if (! $participant->wasRecentlyCreated) {
                return $participant;
            }
            $due = $version->ends_on ? Carbon::parse($version->ends_on) : null;
            foreach ($version->items as $item) {
                $enrolments = $item['type'] === 'course'
                    ? collect([$this->learning->enrol($employee, Course::query()->findOrFail($item['id']), $due, null, null, $actor, (bool) $item['required'])])
                    : $this->learning->enrolPath($employee, LearningPath::query()->findOrFail($item['id']), $due, null, $actor);
                $enrolments->each(fn (LearningEnrolment $e) => $e->learning_program_participant_id ? null : $e->forceFill(['learning_program_participant_id' => $participant->id])->saveQuietly());
            }
            LearningEvent::dispatch('learning.program.enrolled', $employee, $participant, ['program' => $version->program?->name]);

            return $participant;
        });
    }

    /** Complete the program when every required item and the required number of optional items are complete. */
    public function evaluate(LearningProgramParticipant $participant, ?User $actor = null): bool
    {
        if ($participant->status !== 'enrolled') {
            return false;
        }
        $version = $participant->version()->firstOrFail();
        $done = fn (array $item) => $item['type'] === 'course'
            ? $this->courseCompleted($participant->employee_id, (int) $item['id'])
            : collect(LearningPath::query()->findOrFail($item['id'])->items()->where('is_required', true)->pluck('course_id'))->every(fn ($id) => $this->courseCompleted($participant->employee_id, (int) $id));

        $required = collect($version->requiredItems())->every($done);
        $optional = collect($version->optionalItems())->filter($done)->count();
        if (! $required || $optional < (int) ($version->completion_rule['min_optional'] ?? 0)) {
            return false;
        }
        $this->completions->finalizeProgram($participant, $actor);

        return true;
    }

    private function courseCompleted(int $employeeId, int $courseId): bool
    {
        return LearningCompletion::query()->where('employee_id', $employeeId)->where('course_id', $courseId)->where('status', 'final')->exists();
    }
}
