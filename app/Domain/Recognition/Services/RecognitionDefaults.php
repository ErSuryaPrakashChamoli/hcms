<?php

namespace App\Domain\Recognition\Services;

use App\Domain\Recognition\Models\Badge;

/** HROne setup parity HP-9c: HROne's nine system badges, for every tenant (at provisioning; "Add standard badges" later). */
final class RecognitionDefaults
{
    /** code => [name, description, icon, tone] in HROne's order. */
    public const BADGES = [
        'SUPER_WOMAN' => ['Super Woman', 'For a woman colleague who goes above and beyond, every day.', 'heroicon-o-bolt', 'rose'],
        'MAKING_AN_IMPACT' => ['Making an Impact', 'For work that made a real difference to the team or the business.', 'heroicon-o-rocket-launch', 'violet'],
        'MAKING_WORK_FUN' => ['Making Work Fun', 'For bringing energy and joy to the workplace.', 'heroicon-o-face-smile', 'amber'],
        'AMAZING_MENTOR' => ['Amazing Mentor', 'For guiding and growing the people around them.', 'heroicon-o-academic-cap', 'sky'],
        'GREAT_JOB' => ['Great Job', 'For a job well done.', 'heroicon-o-hand-thumb-up', 'emerald'],
        'TEAM_PLAYER' => ['Team Player', 'For putting the team first.', 'heroicon-o-users', 'teal'],
        'THANK_YOU' => ['Thank You', 'To say thank you for help when it counted.', 'heroicon-o-heart', 'rose'],
        'ABOVE_AND_BEYOND' => ['Going Above and Beyond', 'For effort far beyond what was asked.', 'heroicon-o-trophy', 'gold'],
        'OUT_OF_THE_BOX' => ['Out of the Box Thinker', 'For a fresh idea that changed how we work.', 'heroicon-o-light-bulb', 'indigo'],
    ];

    /** @return int badges added (existing ones, by code, are left as the organisation changed them) */
    public function seed(): int
    {
        $added = 0;
        $i = 0;
        foreach (self::BADGES as $code => [$name, $description, $icon, $tone]) {
            $badge = Badge::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'description' => $description, 'icon' => $icon, 'tone' => $tone, 'is_system' => true, 'status' => 'active', 'sort_order' => (++$i) * 10,
            ]);
            $added += $badge->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }
}
