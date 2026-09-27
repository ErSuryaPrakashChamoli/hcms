<?php

namespace App\Filament\Pages;

use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Models\AnnouncementRead;
use App\Domain\Communication\Services\Communications;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/** The reader's view of the Communication Centre (§51): pinned first, unread highlighted, acknowledge inline. */
class AnnouncementsFeed extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?string $title = 'Announcements';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.announcements-feed';

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('communication.view') ?? false) && ServiceDeskActions::me() !== null;
    }

    public static function getNavigationBadge(): ?string
    {
        $me = ServiceDeskActions::me();
        if ($me === null) {
            return null;
        }
        $count = app(Communications::class)->pendingAcknowledgements($me)->count();

        return $count > 0 ? (string) $count : null;
    }

    /** @return Collection<int, array{announcement: Announcement, read: ?AnnouncementRead}> */
    public function getFeed(): Collection
    {
        $me = ServiceDeskActions::me();
        $reads = AnnouncementRead::query()->where('employee_id', $me->id)->get()->keyBy('announcement_id');

        return app(Communications::class)->feedFor($me)->map(fn (Announcement $a) => ['announcement' => $a, 'read' => $reads->get($a->id)]);
    }

    public function markRead(int $id): void
    {
        $announcement = Announcement::query()->findOrFail($id);
        app(Communications::class)->markRead($announcement, ServiceDeskActions::me());
    }

    public function acknowledge(int $id): void
    {
        $announcement = Announcement::query()->findOrFail($id);
        app(Communications::class)->acknowledge($announcement, ServiceDeskActions::me());
        Notification::make()->success()->title('Acknowledged')->send();
    }
}
