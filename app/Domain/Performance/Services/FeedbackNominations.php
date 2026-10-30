<?php

namespace App\Domain\Performance\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Feedback\Support\FeedbackQuestions;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Models\FeedbackResponse;
use App\Domain\Performance\Models\FeedbackRound;
use App\Domain\Performance\Notifications\Feedback360Invitation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * HROne setup parity HP-8h (PF-2 … PF-5): who is asked for 360 feedback in a round and how they answer.
 *
 * - When a round starts, each assessed employee gets a nomination set (FeedbackParticipant): the manager and the functional
 *   manager are asked by the system when the policy collects their feedback; peers, subordinates and clients are chosen
 *   by the nominating role (the employee, the reporting manager or a role's holders) — peers and subordinates among the
 *   employees who share the policy's criteria with the assessed employee, from across the organisation or from the direct
 *   reportees (peers: the same reporting manager; subordinates: the employee's own reports), up to each source's maximum.
 * - Submitting the set asks the reviewers at once, or waits for approval (the reporting manager or HR, in the Approval
 *   Center; never the person who submitted it, never the assessed employee); sending it back returns it to the nominator.
 * - A reviewer answers once, on the round's copy of their source's feedback template, while the window is open; a client
 *   answers through a signed link (tenant and token; the token is stored hashed, expires with the window and works once).
 *   An anonymous source's answer is stored without any link to the nomination (FeedbackResponse).
 */
final class FeedbackNominations
{
    public function __construct(private readonly PerformanceRelationships $relationships, private readonly AccessScopes $scopes) {}

    // --- Nomination sets ---------------------------------------------------------------------------------------------

    /** A round's participant: who nominates, and the system-asked managers. */
    public function seedParticipant(FeedbackRound $round, Employee $employee, ?User $actor): FeedbackParticipant
    {
        $settings = (array) $round->settings;
        $nominator = Feedback360Rules::nominator($settings);
        $employee->loadMissing('currentManager.manager');
        $nominatorUser = match ($nominator) {
            'employee' => $employee->user_id,
            'manager' => $employee->currentManager?->manager?->user_id,
            default => null,
        };
        $roleId = $nominator === 'role' ? Role::query()->where('slug', (string) ($settings['nominator_role'] ?? ''))->value('id') : null;
        $nominated = array_intersect(Feedback360Rules::relations($settings), Feedback360Rules::NOMINATED);

        $participant = FeedbackParticipant::query()->create([
            'feedback_round_id' => $round->id, 'employee_id' => $employee->id, 'nominator' => $nominator,
            'nominator_user_id' => $nominatorUser, 'nominator_role_id' => $roleId,
            'nomination_status' => $nominated === [] ? 'not_needed' : 'draft',
        ]);

        $asked = [];
        foreach (['manager', 'functional_manager'] as $relation) {
            if (! Feedback360Rules::enabled($settings, $relation)) {
                continue;
            }
            foreach ($this->managersOf($employee, $relation) as $manager) {
                $asked[] = FeedbackNomination::query()->create([
                    'feedback_round_id' => $round->id, 'feedback_participant_id' => $participant->id, 'employee_id' => $employee->id,
                    'relation' => $relation, 'reviewer_employee_id' => $manager->id, 'reviewer_user_id' => $manager->user_id,
                    'is_anonymous' => Feedback360Rules::anonymous($settings, $relation), 'source' => 'system', 'status' => 'draft', 'nominated_by' => $actor?->id,
                ]);
            }
        }
        $this->ask($participant, collect($asked));

        return $participant;
    }

    /**
     * Employees the nominating role may choose for a source: employed colleagues with a login, not the assessed employee,
     * sharing the policy's criteria, from the policy's pool.
     *
     * @return Collection<int, Employee>
     */
    public function candidates(FeedbackParticipant $participant, string $relation): Collection
    {
        $participant->loadMissing(['round', 'employee.currentPosition', 'employee.currentManager']);
        $settings = (array) $participant->round->settings;
        if (! in_array($relation, ['peer', 'subordinate'], true) || ! Feedback360Rules::enabled($settings, $relation)) {
            return collect();
        }
        $employee = $participant->employee;
        // Colleagues the system already asks (the manager, functional managers) are not nominated again.
        $asked = FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_participant_id', $participant->id)->where('source', 'system')->pluck('reviewer_employee_id')->filter()->all();
        $query = Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->employed()->whereNotNull('user_id')->whereKeyNot($employee->id)
            ->whereNotIn('id', $asked ?: [0]);
        $direct = Feedback360Rules::scope($settings) === 'direct_reportees';
        if ($relation === 'subordinate') {
            $ids = $direct
                ? ReportingRelationship::query()->where('manager_id', $employee->id)->where('is_primary', true)->currentlyEffective()->pluck('employee_id')->all()
                : $this->relationships->reportIds($employee)->all();
            $query->whereIn('id', $ids ?: [0]);
        } elseif ($direct) {
            $manager = $employee->currentManager?->manager_id;
            $query->whereIn('id', $manager === null ? [0] : ReportingRelationship::query()->where('manager_id', $manager)->where('is_primary', true)->currentlyEffective()->pluck('employee_id')->all());
        }
        $dimensions = Feedback360Rules::match($settings, $relation);
        if ($dimensions !== []) {
            $position = $employee->currentPosition;
            $same = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->select('employee_id')->effectiveOn();
            foreach ($dimensions as $dimension) {
                $value = $position?->getAttribute($dimension);
                $value === null ? $same->whereRaw('1 = 0') : $same->where($dimension, $value);
            }
            $query->whereIn('id', $same);
        }

        return $query->orderBy('employee_code')->get();
    }

    /**
     * The nominating role's choice for a set: internal reviewers per source and clients ({name, email}). Replaces the set's
     * earlier choice (reviewers left out are cancelled); checked against the policy's sources, criteria, pool and maximums.
     *
     * @param  array<string, list<int|string>>  $reviewers  relation (peer / subordinate) => employee ids
     * @param  list<array{name?: string, email?: string}>  $clients
     */
    public function nominate(FeedbackParticipant $participant, array $reviewers, array $clients, User $actor): FeedbackParticipant
    {
        $participant->loadMissing('round');
        $this->assertMayNominate($participant, $actor);
        $round = $participant->round;
        $settings = (array) $round->settings;
        $labels = (array) config('peopleos.performance.feedback_360.relations');

        $chosen = [];
        $seen = [];
        foreach (['peer', 'subordinate'] as $relation) {
            $ids = array_values(array_unique(array_map('intval', array_filter((array) ($reviewers[$relation] ?? []), 'is_numeric'))));
            if ($ids === []) {
                continue;
            }
            if (! Feedback360Rules::enabled($settings, $relation)) {
                throw new RuntimeException("This 360 policy does not collect {$labels[$relation]} feedback.");
            }
            $max = Feedback360Rules::max($settings, $relation);
            if ($max !== null && count($ids) > $max) {
                throw new RuntimeException("Nominate at most {$max} ".mb_strtolower($labels[$relation]).'(s).');
            }
            $allowed = $this->candidates($participant, $relation)->keyBy('id');
            foreach ($ids as $id) {
                if (! $allowed->has($id)) {
                    throw new RuntimeException('Someone nominated as '.mb_strtolower($labels[$relation]).' does not meet the policy\'s criteria.');
                }
                if (isset($seen[$id])) {
                    throw new RuntimeException('A colleague can be nominated for one source only.');
                }
                $seen[$id] = true;
                $chosen[] = ['relation' => $relation, 'employee' => $allowed[$id]];
            }
        }
        $emails = [];
        $clientRows = array_values(array_filter($clients, fn ($c) => is_array($c) && (filled($c['name'] ?? null) || filled($c['email'] ?? null))));
        if ($clientRows !== []) {
            if (! Feedback360Rules::enabled($settings, 'client')) {
                throw new RuntimeException('This 360 policy does not collect client feedback.');
            }
            $max = Feedback360Rules::max($settings, 'client');
            if ($max !== null && count($clientRows) > $max) {
                throw new RuntimeException("Nominate at most {$max} client(s).");
            }
            foreach ($clientRows as $client) {
                $name = trim((string) ($client['name'] ?? ''));
                $email = mb_strtolower(trim((string) ($client['email'] ?? '')));
                if ($name === '' || mb_strlen($name) > 160 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
                    throw new RuntimeException('Each client needs a name and a valid e-mail.');
                }
                if (isset($emails[$email])) {
                    throw new RuntimeException('Duplicate email: '.$email);
                }
                $emails[$email] = $name;
            }
        }

        DB::transaction(function () use ($participant, $chosen, $emails, $settings, $actor) {
            $locked = FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->whereKey($participant->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->nomination_status, ['draft', 'returned'], true)) {
                throw new RuntimeException('These nominations were already submitted.');
            }
            $existing = FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_participant_id', $participant->id)->where('source', 'nominated')->get();
            $keep = [];
            foreach ($chosen as $row) {
                $nomination = $existing->first(fn (FeedbackNomination $n) => (int) $n->reviewer_employee_id === (int) $row['employee']->id)
                    ?? new FeedbackNomination(['feedback_round_id' => $participant->feedback_round_id, 'feedback_participant_id' => $participant->id, 'employee_id' => $participant->employee_id, 'reviewer_employee_id' => $row['employee']->id, 'source' => 'nominated']);
                $nomination->fill(['relation' => $row['relation'], 'reviewer_user_id' => $row['employee']->user_id, 'is_anonymous' => Feedback360Rules::anonymous($settings, $row['relation']), 'status' => 'draft', 'nominated_by' => $actor->id])->save();
                $keep[] = $nomination->id;
            }
            foreach ($emails as $email => $name) {
                $nomination = $existing->first(fn (FeedbackNomination $n) => $n->external_email === $email)
                    ?? new FeedbackNomination(['feedback_round_id' => $participant->feedback_round_id, 'feedback_participant_id' => $participant->id, 'employee_id' => $participant->employee_id, 'external_email' => $email, 'source' => 'nominated']);
                $nomination->fill(['relation' => 'client', 'external_name' => $name, 'is_anonymous' => Feedback360Rules::anonymous($settings, 'client'), 'status' => 'draft', 'nominated_by' => $actor->id])->save();
                $keep[] = $nomination->id;
            }
            $existing->reject(fn (FeedbackNomination $n) => in_array($n->id, $keep, true) || $n->status === 'cancelled')
                ->each(fn (FeedbackNomination $n) => $n->update(['status' => 'cancelled']));
        });

        return $participant->refresh();
    }

    /** Submit the set: asked at once without approval, else waiting in the Approval Center. */
    public function submit(FeedbackParticipant $participant, User $actor): FeedbackParticipant
    {
        $participant->loadMissing(['round', 'employee.person', 'employee.currentManager']);
        $this->assertMayNominate($participant, $actor);
        $settings = (array) $participant->round->settings;
        $count = $this->activeNominated($participant)->count();
        $min = $settings['min_nominations'] ?? null;
        if (is_numeric($min) && $count < (int) $min) {
            throw new RuntimeException('Nominate at least '.(int) $min.' reviewer(s).');
        }
        if ($count === 0) {
            throw new RuntimeException('Nominate at least one reviewer.');
        }
        $approval = Feedback360Rules::approval($settings);

        $submitted = DB::transaction(function () use ($participant, $actor, $approval) {
            $locked = FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->whereKey($participant->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->nomination_status, ['draft', 'returned'], true)) {
                throw new RuntimeException('These nominations were already submitted.');
            }
            $locked->update(['nomination_status' => $approval === 'none' ? 'approved' : 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id,
                'decided_by' => null, 'decided_at' => $approval === 'none' ? now() : null, 'decision_note' => null]);

            return $locked;
        });
        if ($approval === 'none') {
            $this->ask($submitted, $this->activeNominated($submitted));
        } else {
            $manager = $participant->employee->currentManager?->manager_id;
            PerformanceEvent::dispatch('performance.feedback360.nominations_submitted', $participant->employee, $submitted,
                ['for' => $participant->employee->person?->full_name, 'round' => $participant->round->name], $approval === 'manager' && $manager ? [(int) $manager] : []);
        }

        return $submitted->refresh();
    }

    /** Who decides a submitted set: the reporting manager (approval "manager") or HR with performance.feedback_360 in scope. */
    public function mayDecide(User $user, FeedbackParticipant $participant): bool
    {
        $participant->loadMissing(['round', 'employee']);
        if ($participant->nomination_status !== 'submitted' || (int) $participant->submitted_by === (int) $user->id || (int) $participant->employee?->user_id === (int) $user->id) {
            return false;
        }
        $hr = $user->hasPermission('performance.feedback_360') && $this->scopes->allows($user, $participant);
        if (Feedback360Rules::approval((array) $participant->round->settings) === 'manager') {
            $me = $this->relationships->forUser($user);
            $reportingManager = ReportingRelationship::query()->where('employee_id', $participant->employee_id)->where('is_primary', true)->currentlyEffective()->value('manager_id');

            return $hr || ($me !== null && $reportingManager !== null && (int) $reportingManager === (int) $me->id);
        }

        return $hr;
    }

    public function approve(FeedbackParticipant $participant, ?string $note, User $actor): FeedbackParticipant
    {
        $approved = DB::transaction(function () use ($participant, $note, $actor) {
            $locked = FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->with(['round', 'employee'])->whereKey($participant->id)->lockForUpdate()->firstOrFail();
            if (! $this->mayDecide($actor, $locked)) {
                throw new RuntimeException('You cannot decide these nominations.');
            }
            if (! $locked->round->isOpen()) {
                throw new RuntimeException('The 360 feedback round is no longer open.');
            }
            $locked->update(['nomination_status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note === null ? null : mb_strimwidth($note, 0, 500)]);

            return $locked;
        });
        $this->ask($approved, $this->activeNominated($approved));

        return $approved->refresh();
    }

    public function sendBack(FeedbackParticipant $participant, string $note, User $actor): FeedbackParticipant
    {
        if (trim($note) === '') {
            throw new RuntimeException('Say why the nominations are sent back.');
        }
        $returned = DB::transaction(function () use ($participant, $note, $actor) {
            $locked = FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->with(['round', 'employee.person'])->whereKey($participant->id)->lockForUpdate()->firstOrFail();
            if (! $this->mayDecide($actor, $locked)) {
                throw new RuntimeException('You cannot decide these nominations.');
            }
            $locked->update(['nomination_status' => 'returned', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => mb_strimwidth($note, 0, 500)]);

            return $locked;
        });
        $nominator = $returned->nominator_user_id ? Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $returned->nominator_user_id)->value('id') : null;
        PerformanceEvent::dispatch('performance.feedback360.nominations_returned', $returned->employee, $returned,
            ['for' => $returned->employee?->person?->full_name, 'note' => mb_strimwidth($note, 0, 120, '…')], $nominator ? [(int) $nominator] : []);

        return $returned->refresh();
    }

    // --- Answers ---------------------------------------------------------------------------------------------------

    /**
     * An answer to a nomination, on the round's copy of the source's template: by the reviewer it names (a signed-in user)
     * or, for a client, through the verified link ($actor null). Once, while the window is open. An anonymous source's answer
     * keeps no link to the nomination.
     *
     * @param  array<string, mixed>  $answers
     */
    public function respond(FeedbackNomination $nomination, array $answers, ?User $actor): FeedbackResponse
    {
        return DB::transaction(function () use ($nomination, $answers, $actor) {
            $locked = FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->with('round')->whereKey($nomination->id)->lockForUpdate()->firstOrFail();
            $this->assertMayAnswer($locked, $actor);
            $template = $locked->round->templateFor($locked->relation) ?? throw new RuntimeException('This round has no feedback form for this source.');
            $clean = FeedbackQuestions::validate((array) $template['questions'], $answers);
            $response = FeedbackResponse::query()->create([
                'feedback_round_id' => $locked->feedback_round_id, 'feedback_participant_id' => $locked->feedback_participant_id, 'employee_id' => $locked->employee_id,
                'relation' => $locked->relation, 'feedback_nomination_id' => $locked->is_anonymous ? null : $locked->id,
                'answers' => $clean, 'score' => FeedbackRoundResults::scoreOf((array) $template['questions'], $clean),
            ]);
            $locked->update(['status' => 'submitted', 'responded_on' => today()->toDateString(), 'token_hash' => null, 'token_expires_at' => null]);

            return $response;
        });
    }

    /** HROne's "Chose not to participate". */
    public function decline(FeedbackNomination $nomination, ?User $actor): FeedbackNomination
    {
        return DB::transaction(function () use ($nomination, $actor) {
            $locked = FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->with('round')->whereKey($nomination->id)->lockForUpdate()->firstOrFail();
            $this->assertMayAnswer($locked, $actor);
            $locked->update(['status' => 'declined', 'responded_on' => today()->toDateString(), 'token_hash' => null, 'token_expires_at' => null]);

            return $locked;
        });
    }

    /** The client nomination a link's token opens, inside the tenant the signed link names; null when unknown, used or expired. */
    public function byToken(string $token): ?FeedbackNomination
    {
        if (! preg_match('/^[A-Za-z0-9]{40,80}$/', $token)) {
            return null;
        }
        $nomination = FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->with('round')
            ->where('token_hash', hash('sha256', $token))->where('relation', 'client')->where('status', 'requested')->first();
        if ($nomination === null || $nomination->token_expires_at === null || $nomination->token_expires_at->lte(now()) || ! $nomination->round->windowOpen()) {
            return null;
        }

        return $nomination;
    }

    /** HR sends a client a new link (the old one stops working). */
    public function resendLink(FeedbackNomination $nomination, User $actor): void
    {
        if (! $actor->hasPermission('performance.feedback_360') || ! $this->scopes->allows($actor, $nomination)) {
            throw new RuntimeException('You cannot send 360 feedback links.');
        }
        $nomination->loadMissing('round');
        if (! $nomination->isExternal() || $nomination->status !== 'requested' || ! $nomination->round->isOpen()) {
            throw new RuntimeException('Only a client still asked in an open round gets a new link.');
        }
        $this->sendLink($nomination);
    }

    /** Clients' links follow the window: a new closing date moves their expiry. */
    public function refreshLinkExpiry(FeedbackRound $round): void
    {
        FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $round->id)->where('status', 'requested')->whereNotNull('token_hash')
            ->get()->each(fn (FeedbackNomination $n) => $n->update(['token_expires_at' => $round->window_closes_on->copy()->endOfDay()]));
    }

    // --- Lists for screens and My tasks --------------------------------------------------------------------------

    /** @return Collection<int, FeedbackNomination> feedback asked of the user that they can give now */
    public function pendingFor(User $user): Collection
    {
        return FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->with(['round', 'employee.person'])
            ->where('reviewer_user_id', $user->id)->where('status', 'requested')
            ->whereHas('round', fn ($q) => $q->where('status', 'open')->where('window_opens_on', '<=', today()->toDateString().' 23:59:59')->where('window_closes_on', '>=', today()->toDateString()))
            ->orderBy('id')->get();
    }

    /** @return Collection<int, FeedbackParticipant> nomination sets the user still has to make */
    public function toNominateFor(User $user): Collection
    {
        $roleIds = $user->roles()->pluck('roles.id')->all();

        return FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->with(['round', 'employee.person'])
            ->whereIn('nomination_status', ['draft', 'returned'])
            ->where(fn ($q) => $q->where('nominator_user_id', $user->id)->orWhere(fn ($r) => $r->whereNull('nominator_user_id')->whereIn('nominator_role_id', $roleIds ?: [0])))
            ->whereHas('round', fn ($q) => $q->where('status', 'open')->where('window_closes_on', '>=', today()->toDateString()))
            ->orderBy('id')->get();
    }

    /** Whether the user may make or change this set (the nominating person or role holder, or HR in scope). */
    public function mayNominate(User $user, FeedbackParticipant $participant): bool
    {
        if ($participant->isNominator($user)) {
            return $participant->nominator !== 'role' || (int) $participant->employee()->withoutGlobalScope(AccessScope::class)->value('user_id') !== (int) $user->id;
        }

        return $user->hasPermission('performance.feedback_360') && $this->scopes->allows($user, $participant);
    }

    /** @return Collection<int, FeedbackNomination> the set's peers, subordinates and clients still in it */
    public function activeNominated(FeedbackParticipant $participant): Collection
    {
        return FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_participant_id', $participant->id)
            ->where('source', 'nominated')->where('status', '!=', 'cancelled')->orderBy('id')->get();
    }

    // --- Internals -----------------------------------------------------------------------------------------------------

    /** @return list<Employee> the primary reporting manager, or the current functional managers */
    private function managersOf(Employee $employee, string $relation): array
    {
        $query = ReportingRelationship::query()->where('employee_id', $employee->id)->currentlyEffective();
        $relation === 'manager' ? $query->where('is_primary', true) : $query->where('type', 'functional');
        $ids = $query->pluck('manager_id')->unique()->reject(fn ($id) => (int) $id === (int) $employee->id)->all();

        return Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $ids ?: [0])->whereNotNull('user_id')->orderBy('id')->get()->all();
    }

    /** Draft nominations are asked: colleagues are told, clients get their link. */
    private function ask(FeedbackParticipant $participant, Collection $nominations): void
    {
        $participant->loadMissing(['round', 'employee.person']);
        $round = $participant->round;
        foreach ($nominations->where('status', 'draft') as $nomination) {
            $nomination->setRelation('round', $round);
            if ($nomination->isExternal()) {
                $nomination->update(['status' => 'requested', 'requested_at' => now()]);
                $this->sendLink($nomination);

                continue;
            }
            $nomination->update(['status' => 'requested', 'requested_at' => now()]);
            PerformanceEvent::dispatch('performance.feedback360.requested', $participant->employee, $nomination,
                ['for' => $participant->employee?->person?->full_name, 'round' => $round->name, 'due' => $round->window_closes_on->format('d M Y')], array_filter([(int) $nomination->reviewer_employee_id]));
        }
    }

    /** A new one-time token (stored hashed), valid until the window closes, e-mailed to the client. A mail failure is reported, never thrown. */
    private function sendLink(FeedbackNomination $nomination): void
    {
        $token = Str::random(64);
        $nomination->update(['token_hash' => hash('sha256', $token), 'token_expires_at' => $nomination->round->window_closes_on->copy()->endOfDay()]);
        $url = URL::signedRoute('feedback360.external', ['tenant' => $nomination->tenant_id, 'token' => $token]);
        try {
            $nomination->loadMissing('employee.person');
            Notification::route('mail', $nomination->external_email)->notify(new Feedback360Invitation(
                (string) $nomination->external_name, (string) ($nomination->employee?->person?->full_name ?? ''), $nomination->round->window_closes_on->format('d M Y'), $url,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function assertMayNominate(FeedbackParticipant $participant, User $actor): void
    {
        $participant->loadMissing('round');
        if (! $this->mayNominate($actor, $participant)) {
            throw new RuntimeException('You do not nominate reviewers for this employee.');
        }
        if (! $participant->round->isOpen() || $participant->round->window_closes_on->toDateString() < today()->toDateString()) {
            throw new RuntimeException('The 360 feedback round is no longer open.');
        }
        if (! in_array($participant->nomination_status, ['draft', 'returned'], true)) {
            throw new RuntimeException('These nominations were already submitted.');
        }
    }

    private function assertMayAnswer(FeedbackNomination $nomination, ?User $actor): void
    {
        if ($nomination->isExternal() ? $actor !== null : ($actor === null || (int) $nomination->reviewer_user_id !== (int) $actor->id)) {
            throw new RuntimeException('This 360 feedback is not asked of you.');
        }
        if ($nomination->status !== 'requested') {
            throw new RuntimeException($nomination->status === 'submitted' ? 'This 360 feedback was already given.' : 'This 360 feedback is no longer asked.');
        }
        if (! $nomination->round->windowOpen()) {
            throw new RuntimeException('The 360 feedback window is closed.');
        }
    }
}
