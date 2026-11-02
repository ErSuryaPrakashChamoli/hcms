<?php

namespace App\Domain\Employment\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\People\Services\PersonMatcher;
use Illuminate\Support\Facades\Gate;

/**
 * HROne parity WO-3 (matrix 23 §23.2.3 #8): "It seems this employee has worked in past with your organisation". Before a
 * hire, the person being hired is compared with the employees already on record — on PAN and Aadhaar reference (lookup
 * hashes, never decrypting anyone's identifiers; an Aadhaar reference shorter than 12 characters is never compared) and on
 * the existing PersonMatcher criteria (personal e-mail / phone, work e-mail, name + date of birth) — and each earlier
 * employee is shown with their employment, exit and rehire eligibility (CA-3 / WO-11: `is_rehire_eligible`, the blacklist
 * reason).
 *
 * The rule: a match the organisation marked "not eligible for rehire" needs an explicit confirmation with a reason (the
 * hire records it in the audit trail); nothing here blocks a hire by itself (PersonMatcher's definite duplicates still do,
 * as before). The matching runs across the whole tenant, so the rule cannot be dodged by a scoped HR user, but the actor
 * only sees what they may (PRV-1):
 * - an earlier employee outside the actor's organisation scope, or one the actor may not open, is "a person on record";
 * - a match on an identifier is named only for someone holding `employee.sensitive.view` (otherwise naming them would
 *   reveal their PAN / Aadhaar): an identifier-only match is "a person on record", and a match on other details too is
 *   shown as a match on those details only;
 * - the exit (last working day, separation reasons, rehire eligibility and the blacklist reason) needs `exit.view`;
 * - identifier values are never shown, stored or logged here — the actor typed them.
 * A hidden match that is not eligible still asks for the confirmation, with a message that names nobody.
 */
final class ReturningEmployees
{
    public const CONFIRMATION_REQUIRED = 'This person matches an earlier employee who may not be rehired. Confirm that you want to hire them, with a reason.';

    public function __construct(private readonly PersonMatcher $matcher, private readonly AccessScopes $scopes) {}

    /**
     * @param  array<string, mixed>  $person  first_name, last_name, date_of_birth, personal_email, personal_phone
     * @param  array<string, mixed>  $employee  work_email
     * @param  array<string, mixed>  $identifiers  pan, aadhaar_reference
     * @return list<array{employee_id: ?int, visible: bool, name: string, code: ?string, status: ?string, joining_date: ?string, left_on: ?string, matched_on: list<string>, exit_visible: bool, separation_reason: ?string, organisation_reason: ?string, rehire: ?string, block_reason: ?string, not_eligible: bool, currently_employed: bool}>
     */
    public function matches(User $actor, array $person, array $employee = [], array $identifiers = []): array
    {
        /** @var array<int, array{identifier: list<string>, other: list<string>}> $hits */
        $hits = [];
        $panHash = EmployeeStatutoryDetail::panHash(isset($identifiers['pan']) ? (string) $identifiers['pan'] : null);
        $aadhaarHash = EmployeeStatutoryDetail::aadhaarHash(isset($identifiers['aadhaar_reference']) ? (string) $identifiers['aadhaar_reference'] : null);
        if ($panHash !== null || $aadhaarHash !== null) {
            EmployeeStatutoryDetail::query()->withoutGlobalScope(AccessScope::class)
                ->where(fn ($q) => $q->when($panHash, fn ($q) => $q->orWhere('pan_hash', $panHash))->when($aadhaarHash, fn ($q) => $q->orWhere('aadhaar_hash', $aadhaarHash)))
                ->toBase()->get(['employee_id', 'pan_hash', 'aadhaar_hash'])
                ->each(function ($row) use (&$hits, $panHash, $aadhaarHash): void {
                    $id = (int) $row->employee_id;
                    $hits[$id] ??= ['identifier' => [], 'other' => []];
                    if ($panHash !== null && $row->pan_hash === $panHash) {
                        $hits[$id]['identifier'][] = 'PAN';
                    }
                    if ($aadhaarHash !== null && $row->aadhaar_hash === $aadhaarHash) {
                        $hits[$id]['identifier'][] = 'Aadhaar reference';
                    }
                });
        }

        $personCriteria = array_filter(array_intersect_key($person, array_flip(['first_name', 'last_name', 'date_of_birth', 'personal_email', 'personal_phone'])), fn ($v) => filled($v));
        $employeeCriteria = array_filter(array_intersect_key($employee, array_flip(['work_email'])), fn ($v) => filled($v));
        if ($personCriteria !== [] || $employeeCriteria !== []) {
            foreach ($this->matcher->undisclosedCandidates($personCriteria, $employeeCriteria) as $hit) {
                if ($hit['employee_id'] === null) {
                    continue; // a person who was never employed here is not a returning employee (the Person step names them)
                }
                $id = (int) $hit['employee_id'];
                $hits[$id] ??= ['identifier' => [], 'other' => []];
                $hits[$id]['other'] = array_values(array_unique([...$hits[$id]['other'], ...$hit['matched_on']]));
            }
        }
        if ($hits === []) {
            return [];
        }

        $employees = Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->whereKey(array_keys($hits))->get()->keyBy('id');
        $exits = ExitCase::query()->withoutGlobalScope(AccessScope::class)->with(['separationReason', 'approverReason', 'rehireBlockReason'])
            ->whereIn('employee_id', array_keys($hits))->whereIn('status', [...ExitCase::OPEN, 'completed'])
            ->orderByDesc('last_working_day')->orderByDesc('id')->get()->groupBy('employee_id')->map->first();

        $rows = [];
        foreach ($hits as $id => $on) {
            $earlier = $employees->get($id);
            if ($earlier === null) {
                continue;
            }
            $rows[] = $this->row($actor, $earlier, $exits->get($id), $on['identifier'], $on['other']);
        }

        return $rows;
    }

    /** @param  list<array<string, mixed>>  $matches */
    public function needsConfirmation(array $matches): bool
    {
        return collect($matches)->contains(fn (array $m) => $m['not_eligible']);
    }

    /**
     * What the audit trail keeps of a confirmed override: the earlier employees the actor could see, how many were hidden
     * and what matched — never an identifier value.
     *
     * @param  list<array<string, mixed>>  $matches
     * @return array<string, mixed>
     */
    public function auditMetadata(array $matches): array
    {
        $flagged = collect($matches)->filter(fn (array $m) => $m['not_eligible']);

        return [
            'check' => 'returning_employee',
            'not_eligible_employee_ids' => $flagged->pluck('employee_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
            'not_eligible_hidden' => $flagged->whereNull('employee_id')->count(),
            'matched_on' => collect($matches)->pluck('matched_on')->flatten()->unique()->values()->all(),
        ];
    }

    /** One line for a match, as the wizard and notices show it. @param  array<string, mixed>  $m */
    public static function describe(array $m): string
    {
        $on = $m['matched_on'] === [] ? '' : ' — same '.implode(', ', $m['matched_on']);
        if (! $m['visible']) {
            return $m['name'].$on.($m['not_eligible'] ? '. Marked not eligible for rehire: confirm with a reason to continue.' : '.');
        }
        $parts = array_filter([
            $m['status'],
            $m['joining_date'] ? 'joined '.$m['joining_date'] : null,
            $m['left_on'] ? 'last working day '.$m['left_on'] : null,
            $m['separation_reason'] ? 'separation reason: '.$m['separation_reason'] : null,
            $m['organisation_reason'] ? "organisation's reason: ".$m['organisation_reason'] : null,
            match ($m['rehire']) {
                'eligible' => 'eligible for rehire',
                'not_eligible' => 'NOT eligible for rehire'.($m['block_reason'] ? ' ('.$m['block_reason'].')' : ''),
                default => $m['not_eligible'] ? 'needs a confirmation before rehire' : null,
            },
        ]);

        return $m['name'].($m['code'] ? ' ('.$m['code'].')' : '').$on.($parts === [] ? '' : ': '.implode(' · ', $parts)).'.';
    }

    /**
     * @param  list<string>  $byIdentifier
     * @param  list<string>  $byOther
     * @return array<string, mixed>
     */
    private function row(User $actor, Employee $earlier, ?ExitCase $exit, array $byIdentifier, array $byOther): array
    {
        $notEligible = $exit !== null && $exit->is_rehire_eligible === false;
        $state = $earlier->lifecycle_state;
        // HROne parity WO-4: someone who did not join was never employed (their planned joining date is not a joining date).
        $currentlyEmployed = ! in_array($state, [LifecycleState::Exited, LifecycleState::Alumni, LifecycleState::NotJoined], true);
        $inScope = $this->scopes->allows($actor, $earlier) && Gate::forUser($actor)->allows('view', $earlier);
        $mayName = $inScope && ($byIdentifier === [] || $byOther !== [] || $actor->hasPermission('employee.sensitive.view'));
        $matchedOn = $actor->hasPermission('employee.sensitive.view') || ! $mayName ? [...$byIdentifier, ...$byOther] : $byOther;

        if (! $mayName) {
            return ['employee_id' => null, 'visible' => false, 'name' => 'A person on record whose details you may not see', 'code' => null, 'status' => null,
                'joining_date' => null, 'left_on' => null, 'matched_on' => array_values(array_unique($matchedOn)), 'exit_visible' => false, 'separation_reason' => null,
                'organisation_reason' => null, 'rehire' => null, 'block_reason' => null, 'not_eligible' => $notEligible, 'currently_employed' => false];
        }

        $exitVisible = $actor->hasPermission('exit.view') || $actor->hasPermission('exit.manage');

        return [
            'employee_id' => (int) $earlier->getKey(),
            'visible' => true,
            'name' => (string) $earlier->person?->display_name,
            'code' => $earlier->employee_code,
            'status' => $state?->getLabel(),
            'joining_date' => $earlier->joining_date?->toDateString(),
            'left_on' => ($exitVisible ? $exit?->last_working_day : null)?->toDateString() ?? $earlier->exit_date?->toDateString(),
            'matched_on' => array_values(array_unique($matchedOn)),
            'exit_visible' => $exitVisible && $exit !== null,
            'separation_reason' => $exitVisible ? $exit?->separationReason?->name : null,
            'organisation_reason' => $exitVisible ? $exit?->approverReason?->name : null,
            'rehire' => $exitVisible && $exit !== null ? ($notEligible ? 'not_eligible' : 'eligible') : null,
            'block_reason' => $exitVisible && $notEligible ? $exit->rehireBlockReason?->name : null,
            'not_eligible' => $notEligible,
            'currently_employed' => $currentlyEmployed,
        ];
    }
}
