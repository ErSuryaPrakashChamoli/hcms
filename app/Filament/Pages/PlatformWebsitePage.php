<?php

namespace App\Filament\Pages;

use App\Domain\Website\Enums\EnquiryStatus;
use App\Domain\Website\Models\WebsiteEnquiry;
use App\Domain\Website\Models\WebsiteRevision;
use App\Domain\Website\Services\Enquiries;
use App\Domain\Website\Services\Website;
use App\Domain\Website\Support\SiteContent;
use App\Domain\Website\Support\SiteDefaults;
use App\Filament\Support\Website\WebsiteForms;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Platform → Public website: operators edit the marketing site (header menus, pages, footer, announcement), the sign-in
 * page's PeopleOS copy and the search settings as one shared draft; Preview shows the draft exactly as visitors would see
 * it; Publish makes it live. History restores an earlier version into the draft. Enquiries lists "talk to sales" forms.
 */
class PlatformWebsitePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Public website';

    protected static ?string $title = 'Public website';

    protected static ?string $slug = 'platform-website';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.platform-website';

    /** The open area of the editor (site, announcement, header, pages, footer, sign_in, seo, enquiries, history). */
    #[Url(as: 'area')]
    public string $area = 'pages';

    #[Url(as: 'enquiries')]
    public string $enquiryFilter = 'new';

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if (! array_key_exists($this->area, $this->areas())) {
            $this->area = 'pages';
        }
    }

    public function getHeader(): ?View
    {
        return view('filament.shell.empty');
    }

    /** @return array<string, array{label: string, sub: string, icon: string}> */
    public function areas(): array
    {
        return [
            'pages' => ['label' => 'Pages', 'sub' => 'Home, product, pricing and every other page', 'icon' => 'heroicon-o-document-duplicate'],
            'header' => ['label' => 'Header and menus', 'sub' => 'Menus with their drop-down panels, and the header buttons', 'icon' => 'heroicon-o-bars-3-bottom-left'],
            'announcement' => ['label' => 'Announcement bar', 'sub' => 'The thin bar above the header', 'icon' => 'heroicon-o-megaphone'],
            'footer' => ['label' => 'Footer', 'sub' => 'Link columns, legal line and social profiles', 'icon' => 'heroicon-o-bars-3'],
            'sign_in' => ['label' => 'Sign-in page', 'sub' => 'Welcome panel, notice, help and app links', 'icon' => 'heroicon-o-key'],
            'site' => ['label' => 'Site and brand', 'sub' => 'On or off, name and contact details', 'icon' => 'heroicon-o-globe-alt'],
            'seo' => ['label' => 'Search engines', 'sub' => 'Titles, description and indexing', 'icon' => 'heroicon-o-magnifying-glass'],
            'enquiries' => ['label' => 'Enquiries', 'sub' => '"Talk to sales" messages from visitors', 'icon' => 'heroicon-o-inbox'],
            'history' => ['label' => 'History', 'sub' => 'Published versions; restore one into the draft', 'icon' => 'heroicon-o-clock'],
        ];
    }

    public function open(string $area): void
    {
        if (array_key_exists($area, $this->areas())) {
            $this->area = $area;
        }
    }

    public function website(): Website
    {
        return app(Website::class);
    }

    public function draft(): SiteContent
    {
        return $this->website()->draft();
    }

    /** @return array{live: ?WebsiteRevision, draft: ?WebsiteRevision, changed: list<string>, enabled: bool} */
    public function status(): array
    {
        $website = $this->website();

        return ['live' => $website->live(), 'draft' => $website->draftRevision(), 'changed' => $website->changedAreas(), 'enabled' => $website->published()->enabled()];
    }

    public function areaChanged(string $area): bool
    {
        $label = Website::AREAS[$area] ?? null;

        return $label !== null && in_array($label, $this->status()['changed'], true);
    }

    /** @return Collection<int, WebsiteEnquiry> */
    public function enquiries(): Collection
    {
        return app(Enquiries::class)->list(EnquiryStatus::tryFrom($this->enquiryFilter));
    }

    public function newEnquiries(): int
    {
        return app(Enquiries::class)->newCount();
    }

    /** @return Collection<int, WebsiteRevision> */
    public function history(): Collection
    {
        return $this->website()->history();
    }

    public function previewUrl(string $slug = ''): string
    {
        return route('website.preview', $slug === '' ? [] : ['slug' => $slug]);
    }

    /** @param array<string, mixed> $page */
    public function blockSummary(array $page): string
    {
        $labels = collect((array) ($page['blocks'] ?? []))->map(fn ($b) => SiteDefaults::BLOCKS[$b['type'] ?? ''] ?? null)->filter()->values();

        return $labels->isEmpty() ? 'No sections yet' : $labels->countBy()->map(fn ($n, $label) => $n > 1 ? "{$label} ×{$n}" : $label)->implode(' · ');
    }

    // ------------------------------------------------------------------ Draft and publish

    public function previewAction(): Action
    {
        return Action::make('preview')->label('Preview')->icon(Heroicon::OutlinedEye)->color('gray')
            ->url(fn () => $this->previewUrl(), shouldOpenInNewTab: true);
    }

    public function publishAction(): Action
    {
        return Action::make('publish')->label('Publish')->icon(Heroicon::OutlinedRocketLaunch)->color('primary')
            ->disabled(fn () => ! $this->website()->canPublish())
            ->tooltip(fn () => $this->website()->canPublish() ? null : 'Nothing to publish: no unpublished changes.')
            ->modalHeading('Publish the website')
            ->modalDescription(function () {
                $changed = $this->website()->changedAreas();
                $live = $this->website()->live() === null;

                return ($live ? 'This is the first publish: the website goes live at '.url('/').' (if "Public website is on" in Site and brand). ' : '')
                    .($changed === [] ? 'The draft matches the live site.' : 'Changed: '.implode(', ', $changed).'. ').'Visitors see the new version straight away.';
            })
            ->schema([Textarea::make('note')->label('What changed (for the history)')->rows(2)->maxLength(500)])
            ->modalSubmitActionLabel('Publish now')
            ->action(function (array $data): void {
                $this->guard(fn () => $this->website()->publish(auth()->user(), $data['note'] ?? null), 'The website is published');
            });
    }

    public function discardAction(): Action
    {
        return Action::make('discard')->label('Discard draft')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
            ->visible(fn () => $this->website()->draftRevision() !== null)
            ->requiresConfirmation()->modalHeading('Discard unpublished changes?')
            ->modalDescription('Every change since the last publish is thrown away and the editor shows the live site again. This cannot be undone.')
            ->modalSubmitActionLabel('Discard changes')->modalIconColor('danger')
            ->action(fn () => $this->guard(fn () => $this->website()->discardDraft(auth()->user()), 'Draft discarded'));
    }

    public function restoreAction(): Action
    {
        return Action::make('restore')->label('Restore into draft')->icon(Heroicon::OutlinedArrowPath)->color('gray')->size('sm')
            ->requiresConfirmation()->modalHeading('Restore this version into the draft?')
            ->modalDescription('The draft is replaced with this version. Nothing changes for visitors until you publish.')
            ->action(function (array $arguments): void {
                $revision = WebsiteRevision::query()->findOrFail((int) ($arguments['revision'] ?? 0));
                $this->guard(fn () => $this->website()->restore($revision, auth()->user()), 'Restored into the draft — preview it, then publish');
            });
    }

    // ------------------------------------------------------------------ Areas

    public function editSiteAction(): Action
    {
        return $this->areaWizard('editSite', 'site', 'Site and brand', WebsiteForms::siteSteps());
    }

    public function editAnnouncementAction(): Action
    {
        return Action::make('editAnnouncement')->label('Edit announcement')->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('Announcement bar')->modalWidth(Width::ThreeExtraLarge)
            ->fillForm(fn () => $this->draft()->section('announcement'))
            ->schema(WebsiteForms::announcementSchema())
            ->action(fn (array $data) => $this->saveArea('announcement', $data));
    }

    public function editHeaderAction(): Action
    {
        return Action::make('editHeader')->label('Header buttons')->icon(Heroicon::OutlinedCursorArrowRays)->color('gray')
            ->modalHeading('Header buttons')->modalWidth(Width::TwoExtraLarge)
            ->fillForm(fn () => $this->draft()->section('header'))
            ->schema(WebsiteForms::headerButtonsSchema())
            ->action(function (array $data): void {
                $header = $this->draft()->section('header');
                $this->saveArea('header', ['sign_in_label' => $data['sign_in_label'] ?? '', 'cta_label' => $data['cta_label'] ?? '', 'cta_url' => $data['cta_url'] ?? ''] + $header);
            });
    }

    public function editFooterAction(): Action
    {
        return $this->areaWizard('editFooter', 'footer', 'Footer', WebsiteForms::footerSteps());
    }

    public function editSignInAction(): Action
    {
        return $this->areaWizard('editSignIn', 'sign_in', 'Sign-in page', WebsiteForms::signInSteps());
    }

    public function editSeoAction(): Action
    {
        return Action::make('editSeo')->label('Edit search settings')->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('Search engines and sharing')->modalWidth(Width::TwoExtraLarge)
            ->fillForm(fn () => $this->draft()->section('seo'))
            ->schema(WebsiteForms::seoSchema())
            ->action(fn (array $data) => $this->saveArea('seo', $data));
    }

    // ------------------------------------------------------------------ Menus

    public function addMenuAction(): Action
    {
        return Action::make('addMenu')->label('Add menu')->icon(Heroicon::OutlinedPlus)
            ->modalHeading('New menu')->slideOver()->modalWidth(Width::FourExtraLarge)
            ->fillForm(['feature' => ['enabled' => false], 'columns' => []])
            ->steps(WebsiteForms::menuSteps())
            ->action(fn (array $data) => $this->guard(fn () => $this->website()->saveMenu(null, WebsiteForms::clean($data), auth()->user()), 'Menu added to the draft'));
    }

    public function editMenuAction(): Action
    {
        return Action::make('editMenu')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)->size('sm')->color('gray')
            ->modalHeading(fn (array $arguments) => 'Menu: '.($this->menu($arguments)['label'] ?? ''))->slideOver()->modalWidth(Width::FourExtraLarge)
            ->fillForm(fn (array $arguments) => $this->menu($arguments) + ['feature' => ['enabled' => false]])
            ->steps(WebsiteForms::menuSteps())
            ->action(fn (array $data, array $arguments) => $this->guard(fn () => $this->website()->saveMenu((int) $arguments['index'], WebsiteForms::clean($data), auth()->user()), 'Menu saved to the draft'));
    }

    public function moveMenuAction(): Action
    {
        return Action::make('moveMenu')->iconButton()->icon(fn (array $arguments) => ($arguments['by'] ?? 1) < 0 ? Heroicon::OutlinedArrowUp : Heroicon::OutlinedArrowDown)
            ->label(fn (array $arguments) => ($arguments['by'] ?? 1) < 0 ? 'Move up' : 'Move down')->color('gray')->size('sm')
            ->action(fn (array $arguments) => $this->guard(fn () => $this->website()->moveMenu((int) $arguments['index'], (int) $arguments['by'] < 0 ? -1 : 1, auth()->user()), null));
    }

    public function deleteMenuAction(): Action
    {
        return Action::make('deleteMenu')->iconButton()->icon(Heroicon::OutlinedTrash)->label('Remove menu')->color('danger')->size('sm')
            ->requiresConfirmation()->modalHeading(fn (array $arguments) => 'Remove the "'.($this->menu($arguments)['label'] ?? '').'" menu?')
            ->modalDescription('It is removed from the draft; visitors keep seeing it until you publish.')
            ->action(fn (array $arguments) => $this->guard(fn () => $this->website()->deleteMenu((int) $arguments['index'], auth()->user()), 'Menu removed from the draft'));
    }

    // ------------------------------------------------------------------ Pages

    public function addPageAction(): Action
    {
        return Action::make('addPage')->label('Add page')->icon(Heroicon::OutlinedPlus)
            ->modalHeading('New page')->slideOver()->modalWidth(Width::FiveExtraLarge)
            ->fillForm(['status' => 'published', 'blocks' => [
                ['type' => 'hero', 'data' => ['tone' => 'ink', 'visual' => 'orbit', 'title' => '', 'chips' => []]],
                ['type' => 'cta', 'data' => ['heading' => 'See it with your own policies', 'primary_label' => 'Book a demo', 'primary_url' => '/contact']],
            ]])
            ->steps(WebsiteForms::pageSteps())
            ->action(fn (array $data) => $this->guard(fn () => $this->website()->savePage(null, $this->pageData($data), auth()->user()), 'Page added to the draft'));
    }

    public function editPageAction(): Action
    {
        return Action::make('editPage')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)->size('sm')->color('gray')
            ->modalHeading(fn (array $arguments) => 'Page: '.($this->page($arguments)['title'] ?? ''))->slideOver()->modalWidth(Width::FiveExtraLarge)
            ->fillForm(fn (array $arguments) => $this->page($arguments))
            ->steps(WebsiteForms::pageSteps())
            ->action(fn (array $data, array $arguments) => $this->guard(fn () => $this->website()->savePage((string) $arguments['slug'], $this->pageData($data), auth()->user()), 'Page saved to the draft'));
    }

    public function duplicatePageAction(): Action
    {
        return Action::make('duplicatePage')->iconButton()->icon(Heroicon::OutlinedDocumentDuplicate)->label('Duplicate')->color('gray')->size('sm')
            ->modalHeading('Duplicate page')->modalWidth(Width::Large)
            ->fillForm(fn (array $arguments) => ['title' => ($this->page($arguments)['title'] ?? 'Page').' (copy)', 'slug' => trim(($arguments['slug'] ?: 'home').'-copy', '/')])
            ->schema(fn () => array_slice(WebsiteForms::pageSteps()[0]->getChildComponents(), 0, 2))
            ->action(function (array $data, array $arguments): void {
                $page = ['status' => 'hidden'] + $this->page($arguments);
                $page['title'] = $data['title'];
                $page['slug'] = $data['slug'];
                $page['status'] = 'hidden';
                $this->guard(fn () => $this->website()->savePage(null, $page, auth()->user()), 'Copy added to the draft as a hidden page');
            });
    }

    public function deletePageAction(): Action
    {
        return Action::make('deletePage')->iconButton()->icon(Heroicon::OutlinedTrash)->label('Remove page')->color('danger')->size('sm')
            ->visible(fn (array $arguments) => ($arguments['slug'] ?? '') !== '')
            ->requiresConfirmation()->modalHeading(fn (array $arguments) => 'Remove "'.($this->page($arguments)['title'] ?? '').'"?')
            ->modalDescription('Links to it in menus and the footer stay until you change them. Visitors keep seeing it until you publish.')
            ->action(fn (array $arguments) => $this->guard(fn () => $this->website()->deletePage((string) $arguments['slug'], auth()->user()), 'Page removed from the draft'));
    }

    // ------------------------------------------------------------------ Enquiries

    public function enquiryStatusAction(): Action
    {
        return Action::make('enquiryStatus')->label('Update')->icon(Heroicon::OutlinedCheckCircle)->color('gray')->size('sm')
            ->modalHeading('Update enquiry')->modalWidth(Width::Medium)
            ->fillForm(fn (array $arguments) => ['status' => WebsiteEnquiry::query()->find((int) $arguments['enquiry'])?->status?->value ?? 'new'])
            ->schema([Radio::make('status')->label('Status')->options(collect(EnquiryStatus::cases())->mapWithKeys(fn (EnquiryStatus $s) => [$s->value => $s->label()])->all())->required()])
            ->action(function (array $data, array $arguments): void {
                $enquiry = WebsiteEnquiry::query()->findOrFail((int) $arguments['enquiry']);
                app(Enquiries::class)->setStatus($enquiry, EnquiryStatus::from((string) $data['status']), auth()->user());
                Notification::make()->success()->title('Enquiry updated')->send();
            });
    }

    public function filterEnquiries(string $status): void
    {
        $this->enquiryFilter = in_array($status, ['new', 'contacted', 'closed', 'all'], true) ? $status : 'new';
    }

    // ------------------------------------------------------------------ Helpers

    /** @param list<Step> $steps */
    private function areaWizard(string $name, string $area, string $heading, array $steps): Action
    {
        return Action::make($name)->label('Edit')->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading($heading)->modalWidth(Width::ThreeExtraLarge)
            ->fillForm(fn () => $this->draft()->section($area))
            ->steps($steps)
            ->action(fn (array $data) => $this->saveArea($area, $data));
    }

    private function saveArea(string $area, array $data): void
    {
        $this->guard(fn () => $this->website()->saveArea($area, WebsiteForms::clean($data), auth()->user()), (Website::AREAS[$area] ?? 'Changes').' saved to the draft');
    }

    /** @return array<string, mixed> */
    private function menu(array $arguments): array
    {
        return (array) ($this->draft()->section('header')['menus'][(int) ($arguments['index'] ?? -1)] ?? []);
    }

    /** @return array<string, mixed> */
    private function page(array $arguments): array
    {
        return $this->draft()->page((string) ($arguments['slug'] ?? ''), includeHidden: true) ?? [];
    }

    /** @return array<string, mixed> */
    private function pageData(array $data): array
    {
        $data = WebsiteForms::clean($data);
        $data['slug'] = trim(mb_strtolower((string) ($data['slug'] ?? '')), '/');
        $data['blocks'] = array_values(array_filter((array) ($data['blocks'] ?? []), fn ($b) => is_array($b) && array_key_exists($b['type'] ?? '', SiteDefaults::BLOCKS)));

        return $data;
    }

    private function guard(callable $callback, ?string $success): void
    {
        try {
            $callback();
            if ($success !== null) {
                Notification::make()->success()->title($success)
                    ->body(str_ends_with($success, 'saved to the draft') ? 'Visitors see it only after you click Publish at the top of this page.' : null)
                    ->send();
            }
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
