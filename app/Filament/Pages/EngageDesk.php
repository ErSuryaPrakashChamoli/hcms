<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Resources\Badges\BadgeResource;
use App\Filament\Resources\EmployeeFeedback\EmployeeFeedbackResource;
use App\Filament\Resources\EngagementCampaigns\EngagementCampaignResource;
use App\Filament\Resources\MissionStatements\MissionStatementResource;
use App\Filament\Resources\MoodReasons\MoodReasonResource;
use App\Filament\Resources\Recognitions\RecognitionResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\RewardPoints\RewardPointEntryResource;
use App\Filament\Resources\Surveys\SurveyResource;
use App\Filament\Support\Pages\OperationsDeskPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * HROne parity GO-1 (HR Desk → Engage panel, matrix 27 §27.1 #2): the engagement registers in HROne's order — HR
 * handbook · Announcement · Survey · Badges · Reward points · the wall, mood and feedback · Reports · Engage setup (HROne's
 * "Add …" actions stay in each register's header). Every entry is an existing screen listed only when it
 * opens for the viewer; events (GO-15), polls (GO-16) and fundraisers (GO-18) are not built, so the desk has no such
 * entry; candidate engagement belongs to recruitment (out of scope). Opens for people who run engagement (handbook,
 * announcements, surveys or recognition) — not for employees who only read and take part.
 */
class EngageDesk extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Engage desk';

    protected static ?string $title = 'Engage desk';

    protected static ?string $slug = 'engage-desk';

    protected static ?int $navigationSort = 1;

    /** Any one of these makes someone an engagement operator for the desk. */
    public const OPERATOR_PERMISSIONS = ['kb.manage', 'communication.manage', 'engagement.manage', 'recognition.manage', 'recognition.points', 'wall.moderate'];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }
        foreach (self::OPERATOR_PERMISSIONS as $permission) {
            if ($user->hasPermission($permission)) {
                return static::anyEntryOpens();
            }
        }

        return false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'The handbook, announcements, surveys, recognition and the wall in one place, in HROne\'s Engage order.';
    }

    protected function sections(): array
    {
        return [
            ['label' => 'HR handbook', 'items' => [
                ['label' => 'HR handbook', 'target' => ArticleResource::class, 'description' => 'Policies and articles with acknowledgements'],
            ]],
            ['label' => 'Announcement', 'items' => [
                ['label' => 'Announcements', 'target' => AnnouncementResource::class],
                ['label' => 'Announcements feed', 'target' => AnnouncementsFeed::class],
                ['label' => 'Audiences', 'target' => AudienceResource::class],
            ]],
            ['label' => 'Survey', 'items' => [
                ['label' => 'Surveys', 'target' => SurveyResource::class, 'description' => 'Pulse, engagement and event feedback surveys'],
                ['label' => 'Survey results', 'target' => SurveyResults::class],
                ['label' => 'Campaigns', 'target' => EngagementCampaignResource::class],
            ]],
            ['label' => 'Recognition', 'items' => [
                ['label' => 'Badges', 'target' => BadgeResource::class],
                ['label' => 'Recognitions given', 'target' => RecognitionResource::class],
                ['label' => 'Reward points', 'target' => RewardPointEntryResource::class],
                ['label' => 'Social wall', 'target' => SocialWall::class],
            ]],
            ['label' => 'Listening', 'items' => [
                ['label' => 'Happiness index', 'target' => HappinessIndex::class],
                ['label' => 'Feedback inbox', 'target' => EmployeeFeedbackResource::class],
            ]],
            ['label' => 'Reports', 'items' => [
                ['label' => 'Report library', 'target' => ReportLibraryPage::class, 'description' => 'Ready-made badge, points and happiness reports'],
                ['label' => 'My reports', 'target' => ReportResource::class],
            ]],
            ['label' => 'Engage setup', 'items' => [
                ['label' => 'Setup centre: engage', 'target' => SetupCentre::class, 'params' => ['module' => 'engage']],
                ['label' => 'Mission & values', 'target' => MissionStatementResource::class],
                ['label' => 'Mood reasons', 'target' => MoodReasonResource::class],
            ]],
        ];
    }
}
