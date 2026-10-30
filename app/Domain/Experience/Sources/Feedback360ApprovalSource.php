<?php

namespace App\Domain\Experience\Sources;

use App\Domain\Experience\Contracts\ApprovalSource;
use App\Domain\Experience\Contracts\DescribesApprovalKinds;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Services\FeedbackNominations;
use App\Filament\Resources\FeedbackParticipants\FeedbackParticipantResource;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * HROne setup parity HP-8h (PF-5; Inbox → "360 nomination" approval): 360 feedback nominations submitted for approval
 * under a 360 policy that asks for it. Listed for whoever FeedbackNominations::mayDecide allows (the reporting manager or
 * HR with performance.feedback_360 in scope; never the person who submitted them or the assessed employee), who approve
 * them (the reviewers are asked) or send them back to the nominator with a note. The service checks again when deciding.
 */
final class Feedback360ApprovalSource implements ApprovalSource, DescribesApprovalKinds
{
    private const SCAN = 200;

    public function types(): array
    {
        return ['feedback_360_nomination'];
    }

    public function kindLabels(): array
    {
        return ['feedback_360_nomination' => '360 nomination'];
    }

    public function pending(User $user, ?int $about = null): array
    {
        $service = app(FeedbackNominations::class);
        $rows = FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->with(['round', 'employee.person', 'submitter'])->where('nomination_status', 'submitted')
            ->whereHas('round', fn ($q) => $q->where('status', 'open'))
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(self::SCAN)->get();

        return $rows->filter(fn (FeedbackParticipant $p) => $service->mayDecide($user, $p))
            ->map(function (FeedbackParticipant $p) {
                $nominated = FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_participant_id', $p->id)->where('source', 'nominated')->where('status', '!=', 'cancelled')
                    ->get(['relation'])->countBy('relation');
                $labels = (array) config('peopleos.performance.feedback_360.relations');

                return new ApprovalItem(
                    id: 'feedback_360_nomination:'.$p->id, type: 'feedback_360_nomination', typeLabel: 'Performance',
                    title: '360 nomination · '.$p->round->name, subject: $p->employee?->display_name, subjectEmployeeId: $p->employee_id, reason: null,
                    impact: 'Approving asks '.$nominated->map(fn ($n, $r) => $n.' '.mb_strtolower($labels[$r] ?? $r).($n > 1 ? 's' : ''))->implode(', ').' for feedback until '.$p->round->window_closes_on->format('d M Y').'; sending back returns the nominations to the nominator.',
                    effectiveOn: null, dueAt: $p->round->window_closes_on, requestedAt: $p->submitted_at, requestedBy: $p->submitter?->name,
                    risk: 'normal', riskReason: null, decisions: ['approve', 'reject'], url: $this->url(), record: $p,
                    decisionLabels: ['reject' => 'Send back'],
                );
            })->values()->all();
    }

    public function decide(ApprovalItem $item, User $user, string $decision, ?string $note): string
    {
        $record = $item->record;
        if (! $record instanceof FeedbackParticipant) {
            throw new AuthorizationException('Unsupported item.');
        }

        return $decision === 'approve'
            ? tap('360 nominations approved; the reviewers are asked', fn () => app(FeedbackNominations::class)->approve($record, $note, $user))
            : tap('360 nominations sent back', fn () => app(FeedbackNominations::class)->sendBack($record, (string) $note, $user));
    }

    private function url(): ?string
    {
        try {
            return FeedbackParticipantResource::getUrl('index', ['tab' => 'approve']);
        } catch (Throwable) {
            return null;
        }
    }
}
