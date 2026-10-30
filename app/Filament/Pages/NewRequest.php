<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Contracts\RequestType;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * HROne parity RQ-1 (matrix 20 §20.1.0): "New request" — every request the person can raise now, grouped by module, with
 * a search and up to twelve favourites shown first; "Raise for someone" lists the module on-behalf forms they may open.
 * Each entry opens the module's own request form (RequestRegistry), so nothing here creates anything.
 */
class NewRequest extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'New request';

    protected static ?string $title = 'New request';

    protected static ?string $slug = 'new-request';

    protected static ?int $navigationSort = -7;

    protected string $view = 'filament.pages.experience.new-request';

    /** Permissions that open a module's on-behalf request form (a person without an employee record may hold one). */
    public const ON_BEHALF_PERMISSIONS = ['leave.manage', 'attendance.manage', 'payroll.manage', 'expense.approve', 'asset.assign', 'asset.manage', 'servicedesk.agent', 'leave.approve'];

    #[Url]
    public string $search = '';

    /** Cheap on purpose (the navigation asks on every page): an employee record of one's own, or an on-behalf permission. */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null || ! app(TenantContext::class)->has()) {
            return false;
        }
        if (app(CurrentEmployee::class)->of($user) !== null || $user->hasPermission('form.submit')) {
            return true;
        }

        return collect(self::ON_BEHALF_PERMISSIONS)->contains(fn (string $permission) => $user->hasPermission($permission));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Everything you can request, in one place. Each opens the usual form, with its own rules and approvals.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('myRequests')->label('My requests')->icon(Heroicon::OutlinedQueueList)->color('gray')
                ->visible(fn () => MyRequests::canAccess())
                ->url(fn () => MyRequests::getUrl()),
        ];
    }

    /**
     * @return array{favourites: list<array<string, mixed>>, groups: array<string, list<array<string, mixed>>>, behalf: list<array<string, mixed>>, locks: list<string>, total: int}
     */
    #[Computed]
    public function catalogue(): array
    {
        $user = auth()->user();
        $registry = app(RequestRegistry::class);
        $favourites = app(ExperiencePreferences::class)->for($user)['favourite_requests'] ?? [];
        $entries = array_map(fn (array $e) => $this->entry($e['type'], $e['url'], in_array($e['type']->key(), $favourites, true)), $registry->available($user));
        $matching = array_values(array_filter($entries, fn (array $e) => $this->matches($e)));
        $groups = [];
        foreach ($matching as $entry) {
            $groups[$entry['module']][] = $entry;
        }
        $byKey = array_column($matching, null, 'key');
        $behalf = array_values(array_filter(array_map(fn (array $e) => $this->entry($e['type'], $e['url'], false), $registry->onBehalf($user)), fn (array $e) => $this->matches($e)));

        return [
            'favourites' => array_values(array_filter(array_map(fn (string $key) => $byKey[$key] ?? null, $favourites))),
            'groups' => $groups,
            'behalf' => $behalf,
            'locks' => $registry->lockNotices($user),
            'total' => count($entries),
        ];
    }

    /** Star or unstar a request type the person can raise (at most twelve; the oldest star drops off). */
    public function toggleFavourite(string $key): void
    {
        $user = auth()->user();
        if (! app(RequestRegistry::class)->isAvailable($user, $key)) {
            return;
        }
        app(ExperiencePreferences::class)->toggleFavouriteRequest($user, $key);
        unset($this->catalogue);
    }

    /** @return array<string, mixed> */
    private function entry(RequestType $type, string $url, bool $favourite): array
    {
        return ['key' => $type->key(), 'label' => $type->label(), 'description' => $type->description(), 'icon' => $type->icon(), 'module' => $type->module(),
            'keywords' => $type->keywords(), 'url' => $url, 'favourite' => $favourite];
    }

    /** @param  array<string, mixed>  $entry */
    private function matches(array $entry): bool
    {
        $search = mb_strtolower(trim($this->search));
        if ($search === '') {
            return true;
        }
        $haystack = mb_strtolower(implode(' ', [$entry['label'], $entry['description'], $entry['module'], ...$entry['keywords']]));

        return str_contains($haystack, $search);
    }
}
