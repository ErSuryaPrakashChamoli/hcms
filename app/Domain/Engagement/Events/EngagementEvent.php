<?php

namespace App\Domain\Engagement\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 13: survey.* / campaign.* / feedback.* lifecycle events. Context carries references only
 * (codes, names, versions, dates): never answers, free text, respondents or audience members.
 */
final class EngagementEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context  @param  list<int>  $recipientUserIds */
    public function __construct(public readonly string $name, public readonly Model $subject, public readonly array $context = [], public readonly array $recipientUserIds = []) {}
}
