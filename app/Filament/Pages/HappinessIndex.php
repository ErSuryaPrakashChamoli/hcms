<?php

namespace App\Filament\Pages;

use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\MoodCheckIn;
use App\Domain\Engagement\Services\ConfidentialIdentities;
use App\Domain\Engagement\Services\MoodAnalytics;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * HROne setup parity HP-9f (Engage → Happiness index): the mood bot's results — per period that has ended, the
 * check-ins, the average mood, the mood distribution, the share of happy check-ins, the reasons picked and the
 * department groups, all under the engagement privacy thresholds (MoodAnalytics); comments for engagement.comments
 * holders above the text threshold; identified check-ins for engagement.responses when the organisation chose them.
 */
class HappinessIndex extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFaceSmile;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Happiness index';

    protected static ?string $title = 'Happiness index';

    protected static ?string $slug = 'happiness-index';

    protected static ?int $navigationSort = 65;

    protected string $view = 'filament.pages.happiness-index';

    public ?string $commentsFor = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(MoodAnalytics::class)->canView($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    public function results(): array
    {
        return app(MoodAnalytics::class)->results(auth()->user());
    }

    public function canReadComments(): bool
    {
        return auth()->user()->can('engagement.comments');
    }

    public function showComments(string $period): void
    {
        $this->commentsFor = $period;
    }

    /** @return list<array{text: string, handle: ?string}>|null */
    public function comments(): ?array
    {
        return $this->commentsFor === null || ! $this->canReadComments() ? null : app(MoodAnalytics::class)->comments(auth()->user(), $this->commentsFor);
    }

    /** @return list<array<string, mixed>> */
    public function identified(): array
    {
        return app(MoodAnalytics::class)->identified(auth()->user());
    }

    public function moodLabel(int $mood): string
    {
        return MoodCheckIn::MOODS[$mood] ?? (string) $mood;
    }

    /** The reasoned, audited reveal of one confidential check-in's author (engagement.confidential_identity). */
    public function identifyAction(): Action
    {
        return Action::make('identify')->label('Identify the author')->color('danger')
            ->visible(fn () => auth()->user()->can('engagement.confidential_identity'))
            ->modalDescription('Only for a serious concern. Your reason is recorded in the audit trail.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->minLength(10)->maxLength(500)])
            ->action(function (array $data, array $arguments) {
                try {
                    $employee = app(ConfidentialIdentities::class)->reveal('mood_checkin', (string) ($arguments['handle'] ?? ''), (string) $data['reason'], auth()->user());
                    Notification::make()->warning()->title('Author: '.$employee->person?->display_name.' ('.$employee->employee_code.')')->persistent()->send();
                } catch (EngagementRuleViolation $e) {
                    Notification::make()->danger()->title('Not identified')->body($e->getMessage())->send();
                }
            });
    }
}
