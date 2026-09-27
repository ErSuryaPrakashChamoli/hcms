<?php

namespace App\Domain\Alumni\Services;

use App\Domain\Alumni\Models\AlumniProfile;
use App\Domain\Alumni\Models\AlumniRequest;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Services\Letters;
use RuntimeException;

/** Alumni platform (§62): document and reference requests from former employees. */
final class Alumni
{
    public function __construct(private readonly Letters $letters, private readonly AuditRecorder $audit) {}

    public function request(AlumniProfile $profile, string $type, ?string $details = null, ?User $requester = null): AlumniRequest
    {
        if (! $profile->portal_enabled) {
            throw new RuntimeException('The alumni portal is disabled for this profile.');
        }
        if (! array_key_exists($type, config('peopleos.alumni.request_types'))) {
            throw new RuntimeException('Unknown request type.');
        }

        $request = AlumniRequest::create(['number' => $this->nextNumber(), 'alumni_profile_id' => $profile->id, 'type' => $type, 'details' => $details, 'status' => 'submitted']);
        $this->audit->record(AuditAction::Create, 'alumni', $request, [], null, actor: $requester, metadata: ['type' => $type]);
        ExitEvent::dispatch('alumni.request.created', $profile->employee()->first(), $request, ['number' => $request->number, 'type' => config("peopleos.alumni.request_types.{$type}")], User::forCurrentTenant()->get()->filter(fn (User $u) => $u->hasPermission('alumni.manage'))->pluck('id')->all());

        return $request;
    }

    public function verify(AlumniRequest $request, User $handler, ?string $note = null): AlumniRequest
    {
        if ($request->status !== 'submitted') {
            throw new RuntimeException('The request is not awaiting verification.');
        }
        $request->update(['status' => 'verified', 'handled_by' => $handler->id, 'response' => $note]);

        return $request;
    }

    /** Approve and, where a letter type maps to the request, generate + issue it immediately. */
    public function approve(AlumniRequest $request, User $handler, ?string $note = null): AlumniRequest
    {
        if (! in_array($request->status, ['submitted', 'verified'], true)) {
            throw new RuntimeException('The request cannot be approved now.');
        }
        $request->update(['status' => 'approved', 'handled_by' => $handler->id, 'response' => $note ?? $request->response]);

        $letterType = config("peopleos.alumni.letter_for_request.{$request->type}");
        if ($letterType) {
            $employee = $request->profile()->firstOrFail()->employee()->firstOrFail();
            $letter = $this->letters->generate($letterType, $employee, ['purpose' => $request->details ?? 'alumni request'], $handler, $request);
            if ($letter->status === 'pending_approval') {
                $this->letters->approve($letter, $handler, 'Alumni request '.$request->number);
            }
            $this->letters->issue($letter->refresh(), $handler);
            $request->update(['status' => 'generated', 'letter_id' => $letter->id]);
        }

        return $request->refresh();
    }

    public function deliver(AlumniRequest $request, User $handler, ?string $response = null): AlumniRequest
    {
        if (! in_array($request->status, ['approved', 'generated'], true)) {
            throw new RuntimeException('Approve or generate the request first.');
        }
        $request->update(['status' => 'delivered', 'handled_by' => $handler->id, 'delivered_at' => now(), 'response' => $response ?? $request->response]);
        $this->audit->record(AuditAction::Update, 'alumni', $request, [['field' => 'status', 'before' => 'approved', 'after' => 'delivered']], $response, actor: $handler);
        ExitEvent::dispatch('alumni.request.handled', $request->profile()->firstOrFail()->employee()->first(), $request, ['number' => $request->number, 'status' => 'delivered'], array_filter([$request->profile()->firstOrFail()->employee()->value('user_id')]));

        return $request;
    }

    public function reject(AlumniRequest $request, User $handler, string $reason): AlumniRequest
    {
        if (! $request->isOpen()) {
            throw new RuntimeException('The request is closed.');
        }
        $request->update(['status' => 'rejected', 'handled_by' => $handler->id, 'response' => $reason]);
        $this->audit->record(AuditAction::Rejected, 'alumni', $request, [], $reason, actor: $handler);
        ExitEvent::dispatch('alumni.request.handled', $request->profile()->firstOrFail()->employee()->first(), $request, ['number' => $request->number, 'status' => 'rejected'], array_filter([$request->profile()->firstOrFail()->employee()->value('user_id')]));

        return $request;
    }

    public function nextNumber(): string
    {
        $year = now()->format('Y');
        $last = AlumniRequest::query()->where('number', 'like', "ALR-{$year}-%")->orderByDesc('id')->value('number');

        return sprintf('ALR-%s-%05d', $year, $last ? ((int) substr($last, -5)) + 1 : 1);
    }
}
