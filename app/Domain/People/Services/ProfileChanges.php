<?php

namespace App\Domain\People\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\DocumentRules;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Employment\Support\SelfServiceGrant;
use App\Domain\Experience\Services\ProfileSectionVisibility;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\People\Models\ProfileChangeRequest;
use App\Domain\People\Support\ProfileEditSections;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\RequestReturn;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\RequestReturns;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\Support\RequestWorkflows;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * HROne parity MS-3 (matrix 22 §22.2.0 #5–8, #12; §22.2.12): self-service profile changes — the one way an employee
 * changes their own profile on My profile. It builds on the Phase 12 profile-change flow instead of beside it: the same
 * People / Employment actions apply every change (their authorisation, validation, linked-document rule, timeline,
 * event and audit), the values are encrypted while they wait and purged once the request is decided, and the reference
 * of the request rides on every change (ChangeOrigin `self_service`, the audit's approval reference). HR services (the
 * service desk's profile services) keep working exactly as before.
 *
 * **Submitting** (own record only): the section must be editable (setting `profile.self_edit.sections`; legal name and
 * date of birth only with `profile.self_edit.legal_identity`) and shown to the employee by the CA-6 matrix; the employee
 * request lock (P4, kind `profile_change`) and, when switched on, an open background verification refuse it; one open
 * request per section (row lock + a unique `open_key`); a reason is required (≤ 500, it becomes the audit reason); a
 * document is attached through DocumentRules::employeeUpload (pending, HR verifies) — always for legal identity, and when
 * a CA-4 document type is required with a field that changes and none is on file.
 *
 * **Route:** the request raises `profile.change_requested`. A workflow run that decides it (P5: one running on profile
 * changes, or on any record started by that trigger) decides it; else, when the section needs approval (always for bank,
 * identifiers and legal identity), it waits in the Approval Center (ProfileChangeApprovalSource); else it is applied at
 * once, in the employee's name, under a SelfServiceGrant (the guard's only exception, for that request alone).
 *
 * **Deciding:** approve applies the operations in the approver's name through the normal authorisation — the approver
 * holds the rights the actions ask (approver_permissions), in scope, and is neither the requester nor the employee.
 * A refusal by an action leaves the request pending. Reject needs a note. The requester may withdraw while pending.
 * Sent back (P3), the requester corrects it (revise()) or resubmits it as it is.
 */
final class ProfileChanges
{
    public const COMMENT_MAX = 500;

    public function __construct(
        private readonly ProfileChangeOperations $operations,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
    ) {}

    // -- what the employee may change ------------------------------------------------------------------------------

    /** @return array{editable: bool, needs_approval: bool} the organisation's choice for a section */
    public function setting(string $section): array
    {
        if ($section === ProfileEditSections::LEGAL_IDENTITY) {
            return ['editable' => (bool) $this->settings->get('profile.self_edit.legal_identity', false), 'needs_approval' => true];
        }
        $stored = (array) $this->settings->get('profile.self_edit.sections', []);
        $row = (array) ($stored[$section] ?? (config('peopleos.settings')['profile.self_edit.sections'][$section] ?? []));

        return ['editable' => (bool) ($row['editable'] ?? false), 'needs_approval' => ProfileEditSections::alwaysApproval($section) || (bool) ($row['needs_approval'] ?? true)];
    }

    public function needsApproval(string $section): bool
    {
        return $this->setting($section)['needs_approval'];
    }

    /** Whether this person may change this section of their own profile (not counting locks or a pending request). */
    public function editable(User $user, Employee $employee, string $section): bool
    {
        if (! ProfileEditSections::exists($section) || ! self::isOwn($user, $employee) || ! $user->isActive() || ! $user->can('view', $employee)) {
            return false;
        }
        if (! $this->setting($section)['editable']) {
            return false;
        }
        $visibleIn = ProfileEditSections::visibleIn($section);
        if ($visibleIn !== null && ! app(ProfileSectionVisibility::class)->allowsAudience('employee', $visibleIn)) {
            return false;
        }

        return $section !== 'other_details' || ProfileChangeOperations::editableCustomFields()->isNotEmpty();
    }

    /** @return list<string> the sections this person may change now (setting + matrix) */
    public function editableSections(User $user, Employee $employee): array
    {
        return array_values(array_filter(ProfileEditSections::keys(), fn (string $s) => $this->editable($user, $employee, $s)));
    }

    /** Why a change cannot be submitted now (lock, background verification), or null. */
    public function blocked(User $user, Employee $employee): ?string
    {
        $lock = app(SelfServiceLock::class)->refusal($employee, 'profile_change', $user);
        if ($lock !== null) {
            return $lock;
        }
        if ((bool) $this->settings->get('profile.self_edit.lock_during_bgv', false)
            && BgvCase::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->whereIn('status', ['initiated', 'in_progress'])->exists()) {
            return 'Your background verification is in progress: profile changes wait until it is complete. Ask HR if it cannot wait.';
        }

        return null;
    }

    /** @return Collection<string, ProfileChangeRequest> the pending request of each section */
    public function openRequests(Employee $employee): Collection
    {
        return ProfileChangeRequest::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->where('status', 'pending')
            ->with(['decider'])->orderBy('id')->get()->keyBy('section');
    }

    // -- submitting -------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input  the section form's data
     * @param  array{type_id?: int|string|null, file?: UploadedFile|null, expires_on?: ?string, issued_on?: ?string, comment?: ?string}|null  $document
     */
    public function submit(Employee $employee, User $requester, string $section, array $input, string $comment, ?array $document = null): ProfileChangeRequest
    {
        $this->assertMaySubmit($requester, $employee, $section);
        $comment = $this->comment($comment);
        if (($open = $this->openRequests($employee)->get($section)) !== null) {
            throw new ProfileChangeRefused('A change to '.mb_strtolower(ProfileEditSections::label($section)).' is already waiting (since '.$open->created_at?->format('j M Y').'). Withdraw it, or wait for the decision.');
        }
        $operations = $this->operations->build($employee, $section, $input);
        if ($operations === []) {
            throw new ProfileChangeRefused('Nothing changed.');
        }
        $documentType = $this->documentType($employee, $section, $operations, $document);
        $stored = null;

        try {
            return DB::transaction(function () use ($employee, $requester, $section, $comment, $operations, $documentType, $document, &$stored) {
                $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
                if (ProfileChangeRequest::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->where('section', $section)->where('status', 'pending')->lockForUpdate()->exists()) {
                    throw new ProfileChangeRefused('A change to '.mb_strtolower(ProfileEditSections::label($section)).' is already waiting.');
                }
                $stored = $documentType !== null ? $this->storeDocument($requester, $employee, $documentType, $document ?? []) : null;
                $request = new ProfileChangeRequest([
                    'employee_id' => $employee->getKey(), 'requested_by' => $requester->getKey(), 'section' => $section, 'status' => 'pending',
                    'comment' => $comment, 'payload' => ['operations' => $operations], 'fields' => $this->operations->maskedSummary($employee, $operations),
                    'approver_permissions' => $this->operations->permissions($operations), 'document_ids' => $stored ? [(int) $stored->getKey()] : null,
                    'open_key' => $employee->getKey().':'.$section, 'operation_id' => (string) Str::ulid(),
                ]);
                $request->withAuditReason($comment)->save();

                return $this->route($request, $employee, $requester);
            });
        } catch (Throwable $e) {
            $this->discardFile($stored);

            throw $e;
        }
    }

    /**
     * After a request was sent back to its requester (P3): replace what was asked with the corrected values and resubmit
     * it (the return closes as resubmitted; a workflow run resumes at its step).
     */
    public function revise(ProfileChangeRequest $request, User $requester, array $input, string $comment, ?array $document = null): ProfileChangeRequest
    {
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->findOrFail($request->employee_id);
        $this->assertMaySubmit($requester, $employee, (string) $request->section);
        $comment = $this->comment($comment);
        $return = $this->openReturn($request);
        if (! $request->isPending() || $return === null || (int) $return->requester_id !== (int) $requester->getKey()) {
            throw new ProfileChangeRefused('This change is not waiting for you.');
        }
        $operations = $this->operations->build($employee, (string) $request->section, $input);
        if ($operations === []) {
            throw new ProfileChangeRefused('Nothing changed.');
        }
        $documentType = $this->documentType($employee, (string) $request->section, $operations, $document, (array) ($request->document_ids ?? []));
        $stored = null;

        try {
            return DB::transaction(function () use ($request, $requester, $employee, $comment, $operations, $documentType, $document, $return, &$stored) {
                $locked = ProfileChangeRequest::query()->withoutGlobalScope(AccessScope::class)->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
                if (! $locked->isPending()) {
                    throw new ProfileChangeRefused('This change was decided or withdrawn meanwhile.');
                }
                $stored = $documentType !== null && ($document['file'] ?? null) !== null ? $this->storeDocument($requester, $employee, $documentType, $document) : null;
                $locked->withAuditReason('Corrected by the requester: '.$comment)->update([
                    'comment' => $comment, 'payload' => ['operations' => $operations], 'fields' => $this->operations->maskedSummary($employee, $operations),
                    'approver_permissions' => $this->operations->permissions($operations),
                    'document_ids' => array_values(array_unique([...(array) ($locked->document_ids ?? []), ...($stored ? [(int) $stored->getKey()] : [])])) ?: null,
                ]);
                app(RequestReturns::class)->resubmit($return, $requester, 'Corrected: '.$comment);

                return $locked->refresh();
            });
        } catch (Throwable $e) {
            $this->discardFile($stored);

            throw $e;
        }
    }

    /** The send-back of this request still waiting for its requester: on the request itself, or on its workflow run. */
    public function openReturn(ProfileChangeRequest $request): ?RequestReturn
    {
        $direct = RequestReturn::query()->where('subject_type', $request->getMorphClass())->where('subject_id', $request->getKey())->where('status', 'open')->latest('id')->first();
        if ($direct !== null) {
            return $direct;
        }
        $run = WorkflowInstance::query()->where('subject_type', $request->getMorphClass())->where('subject_id', $request->getKey())->where('status', InstanceStatus::Returned)->latest('id')->first();

        return $run === null ? null : RequestReturn::query()->where('subject_type', $run->getMorphClass())->where('subject_id', $run->getKey())->where('status', 'open')->latest('id')->first();
    }

    // -- deciding ---------------------------------------------------------------------------------------------------

    /** Whether this person may decide the request now (rights of its actions, scope, never one's own). */
    public function mayDecide(User $user, ProfileChangeRequest $request): bool
    {
        if (! $user->isActive() || (int) $request->requested_by === (int) $user->getKey()) {
            return false;
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($request->employee_id);
        if ($employee === null || ($employee->user_id !== null && (int) $employee->user_id === (int) $user->getKey())) {
            return false;
        }
        foreach ((array) ($request->approver_permissions ?? ['employee.update']) as $permission) {
            if (! $user->hasPermission((string) $permission)) {
                return false;
            }
        }

        return app(AccessScopes::class)->allows($user, $employee);
    }

    /** Approve and apply (in the approver's name). $viaWorkflow: called by the workflow bridge for the run that decided it. */
    public function approve(ProfileChangeRequest $request, User $actor, ?string $note = null, bool $viaWorkflow = false): ProfileChangeRequest
    {
        return DB::transaction(function () use ($request, $actor, $note, $viaWorkflow) {
            $locked = $this->lockPending($request);
            $this->assertDecidable($locked, $actor, $viaWorkflow);

            return $this->apply($locked, $actor, false, $note);
        });
    }

    public function reject(ProfileChangeRequest $request, User $actor, string $note, bool $viaWorkflow = false): ProfileChangeRequest
    {
        $note = trim(strip_tags($note));
        if ($note === '') {
            throw new ProfileChangeRefused('Say why the change is not accepted.');
        }

        return DB::transaction(function () use ($request, $actor, $note, $viaWorkflow) {
            $locked = $this->lockPending($request);
            $this->assertDecidable($locked, $actor, $viaWorkflow);
            $locked->withAuditReason($note)->update(['status' => 'rejected', 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => mb_substr($note, 0, 1000)] + $this->closed());
            $this->event('profile.change_rejected', $locked);

            return $locked;
        });
    }

    /** The requester withdraws a pending request (its workflow run, if any, is cancelled; nothing is applied). */
    public function withdraw(ProfileChangeRequest $request, User $requester, ?string $reason = null): ProfileChangeRequest
    {
        return DB::transaction(function () use ($request, $requester, $reason) {
            $locked = $this->lockPending($request);
            $owner = Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($locked->employee_id)->value('user_id');
            if ((int) $locked->requested_by !== (int) $requester->getKey() && (int) $owner !== (int) $requester->getKey()) {
                throw new ProfileChangeRefused('Only the person who asked for the change withdraws it.');
            }
            $reason = trim(strip_tags((string) $reason)) ?: 'Withdrawn by the requester';
            $locked->withAuditReason($reason)->update(['status' => 'withdrawn', 'decided_by' => $requester->getKey(), 'decided_at' => now()] + $this->closed());
            WorkflowInstance::query()->where('subject_type', $locked->getMorphClass())->where('subject_id', $locked->getKey())
                ->whereIn('status', InstanceStatus::inFlight())->get()
                ->each(fn (WorkflowInstance $run) => app(WorkflowEngine::class)->cancel($run, 'Profile change withdrawn'));

            return $locked;
        });
    }

    // -- reading ----------------------------------------------------------------------------------------------------

    /**
     * Earlier / new per field for someone deciding (or the requester): earlier read from the record now, new from the
     * request; sensitive values masked unless the viewer holds employee.sensitive.view. Once decided, only the masked
     * summary kept at submit is left.
     *
     * @return list<array{label: string, before: ?string, after: string}>
     */
    public function diff(ProfileChangeRequest $request, User $viewer): array
    {
        if (! $request->isPending() || $request->purged_at !== null) {
            return array_map(fn (array $f) => ['label' => (string) $f['label'], 'before' => $f['earlier'] ?? null, 'after' => (string) ($f['new'] ?? '—')], (array) ($request->fields ?? []));
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->findOrFail($request->employee_id);
        $rows = $this->operations->describe($employee, (array) (($request->payload ?? [])['operations'] ?? []), $viewer->hasPermission('employee.sensitive.view'));

        return array_map(fn (array $r) => ['label' => $r['label'], 'before' => $r['before'], 'after' => $r['after']], $rows);
    }

    /** @return Collection<int, ProfileChangeRequest> the person's own requests, newest first */
    public function history(User $viewer, Employee $employee, int $limit = 30): Collection
    {
        if (! self::isOwn($viewer, $employee)) {
            return collect();
        }

        return ProfileChangeRequest::query()->withoutGlobalScope(AccessScope::class)->with(['decider', 'requester'])->where('employee_id', $employee->getKey())
            ->orderByDesc('id')->limit($limit)->get()->toBase();
    }

    public static function isOwn(User $user, Employee $employee): bool
    {
        return $employee->getAttribute('user_id') !== null && (int) $employee->getAttribute('user_id') === (int) $user->getKey()
            && (int) $employee->getAttribute('tenant_id') === (int) $user->getAttribute('tenant_id');
    }

    // -- internals --------------------------------------------------------------------------------------------------

    private function assertMaySubmit(User $requester, Employee $employee, string $section): void
    {
        if (! self::isOwn($requester, $employee)) {
            throw new ProfileChangeRefused('Profile changes are asked for your own record only.');
        }
        if (! $this->editable($requester, $employee, $section)) {
            throw new ProfileChangeRefused('This part of your profile cannot be changed here. Ask HR.');
        }
        // HROne setup parity P4: the employee request lock (kind profile_change), then the background verification lock.
        app(SelfServiceLock::class)->assertOpen($employee, 'profile_change', $requester, ProfileChangeRefused::class);
        if (($blocked = $this->blocked($requester, $employee)) !== null) {
            throw new ProfileChangeRefused($blocked);
        }
    }

    private function comment(string $comment): string
    {
        $comment = trim(strip_tags($comment));
        if ($comment === '') {
            throw new ProfileChangeRefused('Give a reason for the change.');
        }
        if (mb_strlen($comment) > self::COMMENT_MAX) {
            throw new ProfileChangeRefused('Keep the reason to '.self::COMMENT_MAX.' characters.');
        }

        return $comment;
    }

    /**
     * The document type to store with the change, or null when none is attached; refuses when one is required and
     * missing (legal identity always; otherwise a CA-4 type "required with" a field that changes, with none on file).
     *
     * @param  list<array<string, mixed>>  $operations
     * @param  list<int>  $alreadyAttached
     */
    private function documentType(Employee $employee, string $section, array $operations, ?array $document, array $alreadyAttached = []): ?DocumentType
    {
        $rules = app(DocumentRules::class);
        $required = [];
        foreach ($this->linkedFields($operations) as $field) {
            if (($missing = $rules->missingForField($employee, $field)) !== null) {
                $required[] = $missing;
            }
        }
        $file = $document['file'] ?? null;
        $typeId = filled($document['type_id'] ?? null) ? (int) $document['type_id'] : null;
        $always = ProfileEditSections::document($section) === 'always' && $alreadyAttached === [];
        if ($file === null) {
            if ($required !== []) {
                throw new ProfileChangeRefused('Attach your '.$required[0].': it is required with this change.');
            }
            if ($always) {
                throw new ProfileChangeRefused('Attach a supporting document (for example your passport or a gazette notification).');
            }

            return null;
        }
        $type = $typeId !== null ? $rules->selfUploadTypes()->firstWhere('id', $typeId) : null;
        if ($type === null) {
            throw new ProfileChangeRefused('Choose the kind of document you are attaching.');
        }
        if ($required !== [] && ! in_array($type->name, $required, true)) {
            throw new ProfileChangeRefused('Attach your '.$required[0].': it is required with this change.');
        }

        return $type;
    }

    /** @return list<string> CA-4 linked fields (peopleos.documents.linked_fields) the operations change */
    private function linkedFields(array $operations): array
    {
        $fields = [];
        foreach ($operations as $op) {
            $changed = (array) $op['changed'];
            match ($op['kind']) {
                'address' => $fields[] = 'address',
                'statutory' => array_push($fields, ...array_values(array_intersect_key(['pan' => 'statutory.pan', 'aadhaar_reference' => 'statutory.aadhaar', 'uan' => 'statutory.uan'], array_flip($changed)))),
                'bank_account' => $op['record_id'] === null || in_array('account_number', $changed, true) ? $fields[] = 'bank_account' : null,
                default => null,
            };
        }

        return array_values(array_unique($fields));
    }

    private function storeDocument(User $requester, Employee $employee, DocumentType $type, array $document): EmployeeDocument
    {
        $comment = filled($document['comment'] ?? null) ? mb_substr(trim(strip_tags((string) $document['comment'])), 0, 500) : null;

        try {
            return app(DocumentRules::class)->employeeUpload($requester, $employee, $type, $document['file'], $document['expires_on'] ?? null, $document['issued_on'] ?? null, $comment);
        } catch (\RuntimeException $e) {
            throw new ProfileChangeRefused($e->getMessage(), previous: $e);
        }
    }

    /** A document stored before the change failed: its row rolled back, its file goes too. */
    private function discardFile(?EmployeeDocument $document): void
    {
        if ($document !== null && filled($document->path)) {
            try {
                Storage::disk((string) $document->disk)->delete((string) $document->path);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Raise profile.change_requested (workflows start on it), then route: workflow, Approval Center, or at once. */
    private function route(ProfileChangeRequest $request, Employee $employee, User $requester): ProfileChangeRequest
    {
        $this->event('profile.change_requested', $request, $employee);
        $request->refresh();
        if (! $request->isPending()) {
            return $request; // a workflow decided it already
        }
        if (RequestWorkflows::inFlight($request, [$request->getKey()]) !== []) {
            $request->withAuditReason('Waiting for its approval workflow')->update(['route' => 'workflow']);

            return $request;
        }
        if ($this->needsApproval((string) $request->section)) {
            $request->withAuditReason('Waiting for approval')->update(['route' => 'approval']);

            return $request;
        }
        $request->route = 'direct';

        return $this->apply($request, $requester, true, null);
    }

    /**
     * Apply every operation through its action, in one transaction, then close the request and purge its values.
     * $self: the employee applies their own change (no approval) under the request's SelfServiceGrant.
     */
    private function apply(ProfileChangeRequest $request, User $actor, bool $self, ?string $note): ProfileChangeRequest
    {
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->findOrFail($request->employee_id);
        $operations = (array) (($request->payload ?? [])['operations'] ?? []);
        $reference = $request->reference();
        $origin = ChangeOrigin::selfService($reference, $request->operation_id);
        $reason = 'Profile change '.$reference.': '.$request->comment;
        $run = fn () => $this->audit->withinOperation((string) $request->operation_id, function () use ($operations, $employee, $actor, $reason, $origin) {
            $records = [];
            foreach ($operations as $op) {
                $record = $this->operations->apply($op, $employee, $actor, $reason, $origin);
                $records[] = ['type' => $record->getMorphClass(), 'id' => $record->getKey()];
            }

            return $records;
        });
        $records = $self ? SelfServiceGrant::run($reference, $employee, $actor, (array) ($request->approver_permissions ?? []), $run) : $run();

        $request->withAuditReason($note ?? ($self ? 'Applied at once (no approval needed)' : 'Approved'))->update([
            'status' => 'approved', 'route' => $request->route ?? 'approval', 'decided_by' => $self ? null : $actor->getKey(), 'decided_at' => now(),
            'decision_note' => $note !== null ? mb_substr(trim(strip_tags($note)), 0, 1000) : null, 'applied_at' => now(), 'applied_records' => $records,
        ] + $this->closed());
        $this->event('profile.change_approved', $request, $employee);

        return $request;
    }

    /** @return array<string, mixed> what closing a request clears: its open slot and its values */
    private function closed(): array
    {
        return ['open_key' => null, 'payload' => null, 'purged_at' => now()];
    }

    private function lockPending(ProfileChangeRequest $request): ProfileChangeRequest
    {
        $locked = ProfileChangeRequest::query()->withoutGlobalScope(AccessScope::class)->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
        if (! $locked->isPending()) {
            throw new ProfileChangeRefused('This change is no longer waiting ('.mb_strtolower(ProfileChangeRequest::STATUSES[$locked->status] ?? $locked->status).').');
        }

        return $locked;
    }

    private function assertDecidable(ProfileChangeRequest $request, User $actor, bool $viaWorkflow): void
    {
        if (! $viaWorkflow && RequestWorkflows::inFlight($request, [$request->getKey()]) !== []) {
            throw new ProfileChangeRefused('An approval workflow decides this change.');
        }
        if (! $this->mayDecide($actor, $request)) {
            throw new ProfileChangeRefused('You cannot decide this change: it needs '.implode(', ', (array) $request->approver_permissions).' for this employee, and never your own.');
        }
    }

    private function event(string $name, ProfileChangeRequest $request, ?Employee $employee = null): void
    {
        $employee ??= Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($request->employee_id);
        EmploymentEvent::dispatch($name, $employee, $request, [
            'request' => $request->reference(), 'section' => (string) $request->section, 'section_label' => ProfileEditSections::label((string) $request->section),
            'route' => (string) ($request->route ?? ''), 'status' => (string) $request->status,
        ]);
    }
}
