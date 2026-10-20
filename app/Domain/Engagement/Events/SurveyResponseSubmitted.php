<?php

namespace App\Domain\Engagement\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 13: a response was submitted. Value-free by design: the survey version and its mode only.
 * For anonymous and confidential surveys it carries no employee, user, response id or time beyond the
 * dispatch itself, and it is deliberately not bridged to notification rules or webhooks (a rule or a
 * webhook payload would carry the initiator).
 */
final class SurveyResponseSubmitted
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly int $surveyVersionId, public readonly string $mode) {}
}
