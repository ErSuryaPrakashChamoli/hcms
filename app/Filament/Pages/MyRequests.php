<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\MyRequests as MyRequestsModel;
use App\Domain\Experience\Services\RequestRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * HROne parity RQ-1 (matrix 20 §20.1.0 #4–8, §20.1.19, §20.2.1 #39): "My requests" — every request the person raised, of
 * every type, under the tabs All / Pending / Approved / Rejected / Withdrawn / Drafts, with a request-type filter, date
 * ranges (requested / date asked for / decided), a search and "hide closed"; a closed request can be hidden from the list
 * (a personal flag; nothing changes on the request). A manager may follow a report's requests: each record is shown only
 * when the module's own policy lets them see it. Rows open the module's screen, where the request is acted on.
 */
class MyRequests extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My requests';

    protected static ?string $title = 'My requests';

    protected static ?string $slug = 'my-requests';

    protected static ?int $navigationSort = -8;

    protected string $view = 'filament.pages.experience.my-requests';

    public const DATE_KINDS = ['requested' => 'Requested on', 'effective' => 'Date asked for', 'decided' => 'Decided on'];

    #[Url]
    public string $tab = 'all';

    #[Url]
    public ?string $type = null;

    #[Url]
    public string $date = 'requested';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    #[Url]
    public string $search = '';

    #[Url(as: 'open')]
    public bool $hideClosed = false;

    #[Url]
    public ?int $employee = null;

    public bool $showHidden = false;

    public int $limit = MyRequestsModel::PAGE;

    public static function canAccess(): bool
    {
        return NewRequest::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return $this->subject() !== null
            ? 'The requests of '.$this->subject()->person?->display_name.' that you may see, with where each one stands.'
            : 'Every request you raised, with where each one stands. Open one to follow it up, withdraw it or answer a question.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newRequest')->label('New request')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => NewRequest::canAccess())
                ->url(fn () => NewRequest::getUrl()),
        ];
    }

    /** @return array{rows: Collection, counts: array<string, int>, types: array<string, array{label: string, module: string, count: int}>, more: bool, hidden: int, team: bool} */
    #[Computed]
    public function requests(): array
    {
        return app(MyRequestsModel::class)->for(auth()->user(), $this->subject(), [
            'tab' => $this->tab, 'type' => $this->type ?: null, 'date' => $this->date, 'from' => $this->from ?: null, 'until' => $this->until ?: null,
            'search' => $this->search, 'hide_closed' => $this->hideClosed, 'show_hidden' => $this->showHidden, 'limit' => $this->limit,
        ]);
    }

    /** @return array<string, string> the date kinds offered: the chosen type's own labels, else the generic ones */
    public function dateKinds(): array
    {
        $type = $this->type ? app(RequestRegistry::class)->find($this->type) : null;

        return $type !== null ? array_map(fn (array $d) => $d['label'], $type->dateFilters()) : self::DATE_KINDS;
    }

    /** @return Collection<int, Employee> the people whose requests the viewer may follow besides their own */
    #[Computed]
    public function team(): Collection
    {
        return app(MyRequestsModel::class)->team(auth()->user());
    }

    /** The team member being followed, when the viewer may follow them (else their own requests). */
    public function subject(): ?Employee
    {
        if ($this->employee === null) {
            return null;
        }
        $member = $this->team->firstWhere('id', $this->employee);

        return $member instanceof Employee ? $member : null;
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, MyRequestsModel::TABS) ? $tab : 'all';
        $this->limit = MyRequestsModel::PAGE;
    }

    public function showMore(): void
    {
        $this->limit = min($this->limit + MyRequestsModel::PAGE, 500);
    }

    /** Hide a closed request from the list, or show it again (the person's own requests only). */
    public function toggleHidden(string $key): void
    {
        [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
        $row = $this->subject() === null ? $this->requests['rows']->firstWhere('key', $key) : null;
        if ($row === null || $type === null || ! ctype_digit((string) $id) || (! $row['hidden'] && ! ($row['status'] instanceof RequestStatus && $row['status']->isClosed()))) {
            return;
        }
        app(ExperiencePreferences::class)->toggleHiddenRequest(auth()->user(), $key);
        unset($this->requests);
    }

    public function clearFilters(): void
    {
        $this->reset(['type', 'from', 'until', 'search', 'hideClosed', 'showHidden']);
        $this->date = 'requested';
        $this->limit = MyRequestsModel::PAGE;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'date', 'from', 'until', 'search', 'hideClosed', 'showHidden', 'employee'], true)) {
            $this->limit = MyRequestsModel::PAGE;
            if ($property === 'type' && ! array_key_exists($this->date, $this->dateKinds())) {
                $this->date = 'requested';
            }
        }
    }
}
