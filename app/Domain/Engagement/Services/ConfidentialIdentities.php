<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\EmployeeFeedback;
use App\Domain\Engagement\Models\EngagementIdentity;
use App\Domain\Engagement\Models\SurveyResponse;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Support\Guard;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;

/**
 * Phase 13: the ONLY path from a confidential response or feedback item to its author.
 *
 * - Needs engagement.confidential_identity and a written reason.
 * - Works one item at a time, and only for employees inside the requester's scope.
 * - Audited (CONFIDENTIAL_RESPONSE_IDENTIFIED: who asked, why, which item). The audit names the
 *   item, never the person, so the audit trail does not itself become an identity map.
 *
 * Anonymous items have no identity row: there is nothing to reveal, for anyone, ever.
 */
final class ConfidentialIdentities
{
    public function __construct(private readonly AccessScopes $scopes, private readonly AuditRecorder $audit) {}

    public function reveal(string $subjectType, string $subjectId, string $reason, User $actor): Employee
    {
        Guard::authorise($actor, 'engagement.confidential_identity');
        if (mb_strlen(trim($reason)) < 10) {
            throw new EngagementRuleViolation('Identifying the author of a confidential item needs a clear reason.');
        }
        $entity = match ($subjectType) {
            'survey_response' => $this->confidentialResponse($subjectId),
            'feedback' => $this->confidentialFeedback($subjectId),
            default => throw new EngagementRuleViolation('Unknown confidential item.'),
        };
        $employeeId = EngagementIdentity::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->value('employee_id')
            ?? throw new EngagementRuleViolation('No identity is held for this item.');
        if (! $this->scopes->allowsEmployeeId($actor, (int) $employeeId)) {
            throw new EngagementRuleViolation('The author is outside your scope; someone who covers them must make this request.');
        }
        $this->audit->record(AuditAction::ConfidentialResponseIdentified, 'engagement', $entity, [], trim($reason), actor: $actor, metadata: ['subject_type' => $subjectType, 'subject_id' => $subjectId]);

        return AccessScope::withoutScoping(fn () => Employee::query()->with('person')->findOrFail($employeeId));
    }

    private function confidentialResponse(string $id): SurveyVersion
    {
        $versionId = SurveyResponse::query()->whereKey($id)->value('survey_version_id') ?? throw new EngagementRuleViolation('Unknown response.');
        $version = SurveyVersion::query()->findOrFail($versionId);
        if ($version->anonymity_mode !== 'confidential') {
            throw new EngagementRuleViolation('Only confidential responses can be identified; anonymous responses never can.');
        }

        return $version;
    }

    private function confidentialFeedback(string $id): EmployeeFeedback
    {
        $feedback = EmployeeFeedback::query()->findOrFail($id);
        if ($feedback->mode !== 'confidential') {
            throw new EngagementRuleViolation('Only confidential feedback can be identified; anonymous feedback never can.');
        }

        return $feedback;
    }
}
