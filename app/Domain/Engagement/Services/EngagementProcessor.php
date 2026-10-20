<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\EngagementCampaign;
use App\Domain\Engagement\Models\SurveyVersion;

/**
 * Phase 13: `peopleos:engagement:process` for one tenant. It:
 * - opens scheduled survey versions whose date has come (audience snapshot and invitations);
 * - closes those past their closing time;
 * - sends missing invitations and due reminders;
 * - launches scheduled campaigns and completes ended ones.
 *
 * Every step is a locked status transition or a logged notice, so overlapping or repeated runs
 * change nothing twice.
 */
final class EngagementProcessor
{
    public function __construct(private readonly Surveys $surveys, private readonly SurveyNotices $notices, private readonly Campaigns $campaigns) {}

    /** @return array<string, int> */
    public function run(): array
    {
        $out = ['opened' => 0, 'closed' => 0, 'invitations' => 0, 'reminders' => 0, 'campaigns_launched' => 0, 'campaigns_completed' => 0, 'errors' => 0];
        $guard = function (callable $step, string $counter) use (&$out) {
            try {
                $step();
                $out[$counter]++;
            } catch (EngagementRuleViolation) {
                $out['errors']++;
            }
        };

        SurveyVersion::query()->where('status', 'open')->whereNotNull('closes_at')->where('closes_at', '<=', now())->orderBy('id')
            ->each(fn (SurveyVersion $v) => $guard(fn () => $this->surveys->close($v, null, 'Closing date reached'), 'closed'));
        SurveyVersion::query()->where('status', 'scheduled')->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', now()))->orderBy('id')
            ->each(fn (SurveyVersion $v) => $guard(fn () => $this->surveys->open($v), 'opened'));
        SurveyVersion::query()->where('status', 'open')->orderBy('id')->each(function (SurveyVersion $v) use (&$out) {
            $out['invitations'] += $this->notices->invite($v);
            $out['reminders'] += $this->notices->remind($v);
        });
        EngagementCampaign::query()->where('status', 'scheduled')->where('starts_on', '<=', today()->toDateString().' 23:59:59')->orderBy('id')
            ->each(fn (EngagementCampaign $c) => $guard(fn () => $this->campaigns->launch($c), 'campaigns_launched'));
        EngagementCampaign::query()->where('status', 'active')->whereNotNull('ends_on')->where('ends_on', '<', today()->toDateString())->orderBy('id')
            ->each(fn (EngagementCampaign $c) => $guard(fn () => $this->campaigns->complete($c), 'campaigns_completed'));

        return $out;
    }
}
