<?php

namespace App\Filament\Pages;

use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Models\AnnouncementRead;
use App\Domain\Communication\Services\CommunicationPreferences;
use App\Domain\Communication\Services\Communications;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Policies\EmployeeDocumentPolicy;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Services\EngagementAnalytics;
use App\Domain\Engagement\Services\Feedback;
use App\Domain\Engagement\Services\SurveyResponses;
use App\Domain\Experience\Services\ExperienceTasks;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\Letters\Models\Letter;
use App\Domain\ServiceDesk\Models\ServiceDefinitionVersion;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Filament\Support\EngagementActions;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Phase 12 — My HR: one place for an employee's HR service. Tabs:
 * - Requests;
 * - Tasks;
 * - Approvals;
 * - Documents and letters;
 * - Policies;
 * - Services;
 * - Notifications;
 * - Phase 13: Surveys, Feedback, Communications and Preferences (engagement and communication, read
 *   and acted on through their own services).
 *
 * Every tab reads through the owning domain:
 * - CaseAccess for requests;
 * - the domain task sources;
 * - the Document domain and its own-document policy;
 * - the knowledge base audience and versions;
 * - the catalogue's availability and eligibility.
 *
 * Nothing is copied and nothing is queried around those boundaries. Employee 360 remains the record;
 * this is the action layer.
 */
class MyHr extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My HR';

    protected static ?string $title = 'My HR';

    protected static ?string $slug = 'my-hr';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.my-hr';

    public const TABS = [
        'requests' => 'Requests', 'tasks' => 'Tasks', 'approvals' => 'Approvals', 'documents' => 'Documents', 'policies' => 'Policies', 'services' => 'Services',
        'surveys' => 'Surveys', 'feedback' => 'Feedback', 'communications' => 'Communications', 'preferences' => 'Preferences', 'notifications' => 'Notifications',
    ];

    #[Url]
    public string $tab = 'requests';

    public static function canAccess(): bool
    {
        return auth()->check() && self::me() !== null;
    }

    public static function me(): ?Employee
    {
        return auth()->check() ? Employee::query()->where('user_id', auth()->id())->first() : null;
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'requests';
    }

    /** @return Collection<int, Ticket> */
    public function getRequests(): Collection
    {
        $me = self::me();

        return app(CaseAccess::class)->visible(Ticket::query()->with(['service', 'category']), auth()->user())
            ->where(fn ($q) => $q->where('tickets.employee_id', $me?->id ?? 0)->orWhere('tickets.raised_by', auth()->id()))
            ->orderByDesc('tickets.id')->limit(30)->get();
    }

    public function getTasks(): Collection
    {
        return app(ExperienceTasks::class)->for(auth()->user(), self::me());
    }

    public function getAttention(): Collection
    {
        return collect(app(NeedsAttention::class)->forEmployee(self::me(), auth()->user()));
    }

    public function getApprovals(): Collection
    {
        return $this->getTasks()->filter(fn ($t) => $t->kind === 'approval')->values();
    }

    /** @return Collection<int, EmployeeDocument> own documents through the Document domain's own-document policy */
    public function getDocuments(): Collection
    {
        $me = self::me();
        if ($me === null || ! auth()->user()->hasPermission('document.own')) {
            return collect();
        }

        return EmployeeDocument::query()->with('type')->where('employee_id', $me->id)->where('status', '!=', 'archived')->orderByDesc('id')->get()
            ->filter(fn (EmployeeDocument $d) => EmployeeDocumentPolicy::isOwn(auth()->user(), $d))->values();
    }

    public function documentUrl(EmployeeDocument $document): string
    {
        return app(Documents::class)->downloadUrl($document);
    }

    /** @return Collection<int, Letter> my letters and their status (Letters owns them; issued letters are in Documents) */
    public function getLetters(): Collection
    {
        $me = self::me();

        return $me ? Letter::query()->where('employee_id', $me->id)->orderByDesc('id')->limit(20)->get(['id', 'number', 'type', 'status', 'issued_at', 'created_at']) : collect();
    }

    public function getPolicies(): Collection
    {
        $me = self::me();

        return $me && auth()->user()->hasPermission('kb.view') ? app(KnowledgeBase::class)->visibleTo($me) : collect();
    }

    /** @return list<int> ids of policies the employee still has to acknowledge */
    public function getPendingPolicyIds(): array
    {
        $me = self::me();

        return $me ? app(KnowledgeBase::class)->pendingAcknowledgements($me)->pluck('id')->all() : [];
    }

    public function acknowledge(int $id): void
    {
        $article = Article::query()->findOrFail($id);
        ServiceDeskActions::run(fn () => app(KnowledgeBase::class)->acknowledge($article, self::me(), 'web', request()->ip()), 'Acknowledged');
    }

    /** @return Collection<int, ServiceDefinitionVersion> services I can request for myself now */
    public function getServices(): Collection
    {
        return app(ServiceCatalogue::class)->availableTo(auth()->user(), self::me(), 'employee');
    }

    public function getNotifications(): Collection
    {
        return auth()->user()->notifications()->latest()->limit(20)->get();
    }

    /** Phase 13: my surveys — open ones to take, and my history (status only for anonymous / confidential). */
    public function getSurveys(): Collection
    {
        return auth()->user()->hasPermission('engagement.participate') ? app(SurveyResponses::class)->mySurveys(auth()->user()) : collect();
    }

    public function canSeeSurveyResults(int $versionId): bool
    {
        $version = SurveyVersion::query()->find($versionId);

        return $version !== null && app(EngagementAnalytics::class)->access($version, auth()->user()) !== null && app(EngagementAnalytics::class)->released($version);
    }

    public function takeSurveyAction(): Action
    {
        return EngagementActions::takeSurvey();
    }

    /** My identified feedback (confidential and anonymous items cannot be listed back to anyone). */
    public function getMyFeedback(): Collection
    {
        return app(Feedback::class)->mine(auth()->user())->orderByDesc('submitted_on')->limit(20)->get();
    }

    public function giveFeedbackAction(): Action
    {
        return EngagementActions::giveFeedback()->visible(fn () => auth()->user()->hasPermission('engagement.participate'));
    }

    /** @return Collection<int, array{announcement: Announcement, read: ?AnnouncementRead}> */
    public function getCommunications(): Collection
    {
        $me = self::me();
        if ($me === null || ! auth()->user()->hasPermission('communication.view')) {
            return collect();
        }
        $feed = app(Communications::class)->feedFor($me);
        $reads = AnnouncementRead::query()->where('employee_id', $me->id)->whereIn('announcement_id', $feed->pluck('id'))->get()->keyBy('announcement_id');

        return $feed->map(fn (Announcement $a) => ['announcement' => $a, 'read' => $reads->get($a->id), 'attachment' => app(Communications::class)->attachmentUrl($a)]);
    }

    public function readAnnouncement(int $id): void
    {
        ServiceDeskActions::run(fn () => app(Communications::class)->markRead(Announcement::query()->findOrFail($id), self::me()), 'Marked as read');
    }

    public function acknowledgeAnnouncement(int $id): void
    {
        ServiceDeskActions::run(fn () => app(Communications::class)->acknowledge(Announcement::query()->findOrFail($id), self::me()), 'Acknowledged');
    }

    /** @return array{optional: array<string, array{label: string, in_app: bool, email: bool}>, mandatory: array<string, string>} */
    public function getPreferences(): array
    {
        $me = self::me();
        $service = app(CommunicationPreferences::class);
        $current = $me ? $service->for($me) : [];

        return [
            'optional' => collect($service->categories())->map(fn ($label, $category) => ['label' => $label] + ($current[$category] ?? ['in_app' => true, 'email' => true]))->all(),
            'mandatory' => collect(config('peopleos.communication.mandatory_types'))->mapWithKeys(fn ($t) => [$t => config("peopleos.communication.types.{$t}", $t)])->all(),
        ];
    }

    public function setPreference(string $category, string $channel, bool $on): void
    {
        $me = self::me();
        $current = app(CommunicationPreferences::class)->for($me)[$category] ?? ['in_app' => true, 'email' => true];
        if (! in_array($channel, ['in_app', 'email'], true)) {
            return;
        }
        $current[$channel] = $on;
        ServiceDeskActions::run(fn () => app(CommunicationPreferences::class)->set($me, $category, $current['in_app'], $current['email'], auth()->user()), 'Preference saved');
    }

    public function requestServiceAction(): Action
    {
        return ServiceDeskActions::requestService();
    }

    protected function getHeaderActions(): array
    {
        return [ServiceDeskActions::askHr(), $this->giveFeedbackAction()];
    }
}
