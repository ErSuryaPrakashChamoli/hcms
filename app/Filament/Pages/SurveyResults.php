<?php

namespace App\Filament\Pages;

use App\Domain\Engagement\Models\SurveyQuestion;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Services\ConfidentialIdentities;
use App\Domain\Engagement\Services\EngagementAnalytics;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use RuntimeException;

/**
 * Phase 13: survey results through EngagementAnalytics only. Everything shown is an aggregate:
 * - suppressed below the privacy threshold, with complementary suppression;
 * - groups from the version's one pinned dimension, with no ad-hoc filters;
 * - free text overall, above the text threshold, shuffled;
 * - anonymous / confidential results only once the survey has closed.
 *
 * Managers see their own team group only. The confidential reveal is a separate, reasoned, audited
 * action; anonymous responses have none.
 */
class SurveyResults extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'survey-results';

    protected static ?string $title = 'Survey results';

    protected string $view = 'filament.pages.survey-results';

    #[Url]
    public ?int $version = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasPermission('engagement.analytics') || $user->hasPermission('engagement.team_results') || $user->hasPermission('engagement.participate'));
    }

    public function mount(): void
    {
        abort_if($this->record() === null || app(EngagementAnalytics::class)->access($this->record(), auth()->user()) === null, 403);
    }

    public function record(): ?SurveyVersion
    {
        return $this->version ? SurveyVersion::query()->with('survey')->find($this->version) : null;
    }

    public function getTitle(): string
    {
        $v = $this->record();

        return $v ? 'Results: '.$v->survey->name.' v'.$v->version : 'Survey results';
    }

    public function getResults(): array
    {
        return app(EngagementAnalytics::class)->results($this->record(), auth()->user());
    }

    public function getParticipation(): ?array
    {
        $analytics = app(EngagementAnalytics::class);

        return $analytics->access($this->record(), auth()->user()) === 'hr' ? $analytics->participation($this->record(), auth()->user()) : null;
    }

    /** @return array<string, list<array{text: string, handle: ?string}>|null> question key => comments (null = suppressed) */
    public function getComments(): array
    {
        $analytics = app(EngagementAnalytics::class);
        if (! auth()->user()->hasPermission('engagement.comments') || $analytics->access($this->record(), auth()->user()) !== 'hr') {
            return [];
        }

        return SurveyQuestion::query()->where('survey_version_id', $this->version)->where('type', 'text')->orderBy('position')->get()
            ->mapWithKeys(fn (SurveyQuestion $q) => [$q->prompt => $analytics->comments($this->record(), $q, auth()->user())])->all();
    }

    public function getTrend(): array
    {
        $analytics = app(EngagementAnalytics::class);

        return $analytics->access($this->record(), auth()->user()) === 'hr' ? $analytics->trend($this->record()->survey, auth()->user()) : [];
    }

    /** Confidential surveys only: identify one comment's author with a reason (audited). */
    public function revealAction(): Action
    {
        return Action::make('reveal')->label('Identify author')->icon('heroicon-m-identification')->color('danger')->size('xs')
            ->visible(fn () => $this->record()?->anonymity_mode === 'confidential' && auth()->user()->hasPermission('engagement.confidential_identity'))
            ->modalDescription('Confidential responses are identified only for a serious, documented reason. The request, your reason and the item are audited.')
            ->schema([Textarea::make('reason')->required()->minLength(10)])
            ->action(function (array $data, array $arguments) {
                try {
                    $employee = app(ConfidentialIdentities::class)->reveal('survey_response', (string) ($arguments['handle'] ?? ''), $data['reason'], auth()->user());
                    Notification::make()->warning()->title('Author: '.$employee->employee_code.' · '.$employee->person?->full_name)->persistent()->send();
                } catch (RuntimeException $e) {
                    ServiceDeskActions::refuse($e->getMessage());
                }
            });
    }
}
