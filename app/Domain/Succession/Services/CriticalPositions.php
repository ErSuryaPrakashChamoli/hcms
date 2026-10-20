<?php

namespace App\Domain\Succession\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Domain\Succession\Events\SuccessionEvent;
use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Succession\Models\CriticalPositionAssessment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9 critical positions: a role (Designation, optionally within an organisation unit)
 * designated critical by an authorised person (succession.manage), with criticality, impact,
 * scarcity, replacement difficulty and operational dependency recorded as immutable assessments —
 * configured judgements, never computed. Incumbents and upcoming exits are read from employment.
 */
final class CriticalPositions
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  array{criticality: string, business_impact: string, scarcity: string, replacement_difficulty: string, operational_dependency: string, reason: string}  $assessment */
    public function designate(Designation $designation, ?int $organisationNodeId, string $title, array $assessment, int $reviewFrequencyMonths, User $actor): CriticalPosition
    {
        $this->authorise($actor);
        if ($reviewFrequencyMonths < 1) {
            throw new RuntimeException('Review frequency is at least one month.');
        }

        try {
            return DB::transaction(function () use ($designation, $organisationNodeId, $title, $assessment, $reviewFrequencyMonths, $actor) {
                $position = CriticalPosition::query()->create([
                    'designation_id' => $designation->id, 'organisation_node_id' => $organisationNodeId, 'title' => $title,
                    'review_frequency_months' => $reviewFrequencyMonths, 'next_review_on' => now()->addMonths($reviewFrequencyMonths)->toDateString(),
                    'effective_from' => now()->toDateString(), 'created_by' => $actor->id,
                ]);
                $this->assess($position, $assessment, $actor);
                SuccessionEvent::dispatch('succession.critical_position.created', null, $position, ['title' => $title]);

                return $position->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('This role is already designated critical for that scope.');
        }
    }

    /** @param  array{criticality: string, business_impact: string, scarcity: string, replacement_difficulty: string, operational_dependency: string, reason: string}  $data */
    public function assess(CriticalPosition $position, array $data, User $actor): CriticalPositionAssessment
    {
        $this->authorise($actor);

        return DB::transaction(function () use ($position, $data, $actor) {
            $locked = CriticalPosition::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new RuntimeException('A retired critical position is not reassessed.');
            }
            $assessment = CriticalPositionAssessment::query()->create([
                'critical_position_id' => $locked->id, 'assessed_by' => $actor->id, 'assessed_at' => now(), 'effective_from' => now()->toDateString(),
                ...array_intersect_key($data, array_flip(['criticality', 'business_impact', 'scarcity', 'replacement_difficulty', 'operational_dependency', 'reason'])),
            ]);
            $position->setRawAttributes($locked->getAttributes(), true);
            $position->update(['current_assessment_id' => $assessment->id, 'next_review_on' => now()->addMonths($locked->review_frequency_months)->toDateString()]);
            $this->audit->record(AuditAction::Create, 'succession', $position, [['field' => 'criticality', 'before' => null, 'after' => $assessment->criticality]], $assessment->reason, actor: $actor, metadata: ['event' => 'criticality_assessed', 'assessment_id' => $assessment->id]);

            return $assessment;
        });
    }

    public function retire(CriticalPosition $position, string $reason, User $actor): CriticalPosition
    {
        $this->authorise($actor);
        if (trim($reason) === '') {
            throw new RuntimeException('Retiring a critical position needs a reason.');
        }
        $position->withAuditReason($reason)->update(['status' => 'retired', 'active_key' => null, 'effective_to' => now()->toDateString()]);

        return $position;
    }

    /** Employees currently in the role (and unit) — read from employment, not stored. */
    public function incumbents(CriticalPosition $position): Collection
    {
        $query = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->effectiveOn()->where('designation_id', $position->designation_id);
        if ($position->organisation_node_id) {
            $node = OrganisationNode::query()->find($position->organisation_node_id);
            $column = $node ? app(OrganisationTree::class)->typeKeyForClass($node->nodeable_type).'_id' : null;
            if ($column && in_array($column, (new EmployeePosition)->getFillable(), true)) {
                $query->where($column, $node->nodeable_id);
            }
        }

        return Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->employed()->whereIn('id', $query->select('employee_id'))->get();
    }

    /** Factual: the earliest recorded last working day among incumbents (an exit already initiated). */
    public function upcomingIncumbentExit(CriticalPosition $position): ?string
    {
        $ids = $this->incumbents($position)->pluck('id');

        return $ids->isEmpty() ? null : ExitCase::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $ids)->whereIn('status', ExitCase::OPEN)
            ->whereNotNull('last_working_day')->whereDate('last_working_day', '>=', now()->toDateString())->min('last_working_day');
    }

    private function authorise(User $actor): void
    {
        if (! $actor->hasPermission('succession.manage')) {
            throw new RuntimeException('Critical positions are designated with succession.manage.');
        }
    }
}
