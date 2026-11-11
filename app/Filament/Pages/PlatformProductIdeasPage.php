<?php

namespace App\Filament\Pages;

use App\Domain\ProductFeedback\Enums\ProductIdeaKind;
use App\Domain\ProductFeedback\Enums\ProductIdeaStatus;
use App\Domain\ProductFeedback\Models\ProductIdea;
use App\Domain\ProductFeedback\Services\ProductIdeaDirectory;
use App\Domain\ProductFeedback\Services\ProductIdeas;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * HROne parity AC-3: the product ideas organisations send to Markedge, across organisations, for platform operators —
 * filters by organisation, module, status and kind (and a reference or words of the title / process); one idea's text as
 * the organisation wrote it, the organisation, who sent it (name and work e-mail, as Platform operations shows an
 * organisation's contacts) and its files. "Change status" sets the status and Markedge's response, which the organisation
 * reads; ProductIdeas::changeStatus refuses anyone but an operator, audits it on both chains and tells the sender. No other
 * tenant record is read here.
 */
class PlatformProductIdeasPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Product ideas';

    protected static ?string $title = 'Product ideas from organisations';

    protected static ?string $slug = 'platform-product-ideas';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.platform-product-ideas';

    #[Url]
    public ?int $tenant = null;

    #[Url]
    public ?string $module = null;

    #[Url]
    public ?string $status = null;

    #[Url]
    public ?string $kind = null;

    #[Url]
    public ?string $search = null;

    #[Url]
    public ?int $idea = null;

    /** @var Collection<int, ProductIdea>|null */
    private ?Collection $rowsCache = null;

    private ?ProductIdea $selectedCache = null;

    public function getSubheading(): ?string
    {
        return 'Ideas organisations sent to improve PeopleOS, by module and status.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array{tenant: ?int, module: ?string, status: ?string, kind: ?string, search: ?string} */
    public function filters(): array
    {
        return ['tenant' => $this->tenant ?: null, 'module' => filled($this->module) ? $this->module : null, 'status' => filled($this->status) ? $this->status : null,
            'kind' => filled($this->kind) ? $this->kind : null, 'search' => filled($this->search) ? $this->search : null];
    }

    /** @return Collection<int, ProductIdea> */
    public function rows(): Collection
    {
        return $this->rowsCache ??= app(ProductIdeaDirectory::class)->list(auth()->user(), $this->filters());
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return app(ProductIdeaDirectory::class)->counts(auth()->user(), $this->filters());
    }

    public function selected(): ?ProductIdea
    {
        if ($this->idea === null) {
            return null;
        }
        if ($this->selectedCache?->getKey() !== $this->idea) {
            $this->selectedCache = app(ProductIdeaDirectory::class)->find(auth()->user(), $this->idea);
        }

        return $this->selectedCache;
    }

    /** @return array<int, string> */
    public function tenantOptions(): array
    {
        return app(ProductIdeaDirectory::class)->tenantOptions(auth()->user());
    }

    /** @return array<string, array<string, string>> */
    public function moduleOptions(): array
    {
        return ProductIdeas::moduleOptions();
    }

    /** @return array<string, string> */
    public function statusOptions(): array
    {
        return ProductIdeaStatus::options();
    }

    /** @return array<string, string> */
    public function kindOptions(): array
    {
        return ProductIdeaKind::options();
    }

    public function changeStatusAction(): Action
    {
        return Action::make('changeStatus')->label('Change status')->icon(Heroicon::OutlinedArrowPath)
            ->modalHeading(fn () => 'Change status · '.($this->selected()?->reference ?? ''))
            ->modalDescription('The organisation sees the status and your response as written, and the person who sent the idea is told in the app and by e-mail.')
            ->fillForm(fn () => ['status' => $this->selected()?->status?->value, 'platform_response' => $this->selected()?->platform_response])
            ->schema([
                Select::make('status')->label('Status')->options(collect(ProductIdeaStatus::options())->except(ProductIdeaStatus::Submitted->value)->all())->native(false)->required(),
                Textarea::make('platform_response')->label('Markedge\'s response (the organisation reads it)')->rows(5)->maxLength(5000)
                    ->helperText('Required when declining. Leave it as it is to keep the earlier response.'),
            ])
            ->action(function (array $data) {
                $idea = $this->selected();
                if ($idea === null) {
                    return;
                }
                try {
                    $fresh = app(ProductIdeas::class)->changeStatus($idea, auth()->user(), (string) ($data['status'] ?? ''), $data['platform_response'] ?? null);
                    Notification::make()->success()->title("{$fresh->reference} is now {$fresh->status->getLabel()}")->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
                $this->rowsCache = null;
                $this->selectedCache = null;
            });
    }

    public function downloadAttachmentAction(): Action
    {
        return Action::make('downloadAttachment')->label('Open')->link()->size('sm')
            ->action(function (array $arguments): ?StreamedResponse {
                $idea = $this->selected();
                if ($idea === null) {
                    return null;
                }
                try {
                    $file = app(ProductIdeas::class)->attachmentPath($idea, auth()->user(), (int) ($arguments['index'] ?? -1));
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return null;
                }

                return Storage::disk($file['disk'])->download($file['path'], $file['name']);
            });
    }
}
