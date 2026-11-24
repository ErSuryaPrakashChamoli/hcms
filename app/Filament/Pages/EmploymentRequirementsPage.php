<?php

namespace App\Filament\Pages;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Employment\Requirements\EmploymentRequirementsPreflight;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Master relationships R2 (OD-3): the employment data requirements and their pre-flight. For each policy: what it
 * requires and checks, and how today's data stands against it (people who would fail each rule on their next change,
 * companies that could not meet a required field). "Switch to Block" publishes the chosen rules on Block with the
 * pre-flight record the policy needs; Warn and Off are set in the policy wizard as usual.
 */
class EmploymentRequirementsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Employment data requirements';

    protected static ?string $title = 'Employment data requirements';

    protected static ?string $slug = 'employment-data-requirements';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.employment-requirements';

    #[Url(as: 'policy')]
    public ?int $policyId = null;

    /** @var array<string, mixed>|null */
    private ?array $report = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('policy.view') ?? false;
    }

    public function mount(): void
    {
        $this->policyId ??= $this->policies()->keys()->first();
    }

    /** @return Collection<int, Policy> */
    public function policies()
    {
        return Policy::query()->where('type', EmploymentRequirements::TYPE)->orderBy('name')->get()->keyBy('id');
    }

    public function policy(): ?Policy
    {
        return $this->policyId === null ? null : $this->policies()->get($this->policyId);
    }

    public function choose(int $id): void
    {
        $this->policyId = $id;
        $this->report = null;
    }

    /** @return array<string, mixed>|null */
    public function report(): ?array
    {
        $policy = $this->policy();

        return $policy === null ? null : ($this->report ??= app(EmploymentRequirementsPreflight::class)->run($policy));
    }

    /** @return array{required_on_hire: list<string>, required_on_change: list<string>, required_mode: string, checks: array<string, string>}|null */
    public function settings(): ?array
    {
        $policy = $this->policy();
        $version = $policy?->versionEffectiveOn(today()) ?? $policy?->versions()->first();

        return $version === null ? null : EmploymentRequirements::normalise((array) $version->settings);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editPolicy')->label('Edit in the policy wizard')->color('gray')
                ->visible(fn () => $this->policy() !== null && (auth()->user()?->can('policy.update') ?? false))
                ->url(fn () => PolicyWizard::getUrl(['policy' => $this->policyId])),
            Action::make('newPolicy')->label('New requirements policy')->color('gray')
                ->visible(fn () => auth()->user()?->can('policy.create') ?? false)
                ->url(fn () => PolicyWizard::getUrl(['type' => EmploymentRequirements::TYPE])),
            Action::make('switchToBlock')->label('Switch to Block')->color('danger')->icon(Heroicon::OutlinedNoSymbol)
                ->visible(fn () => $this->policy() !== null && (auth()->user()?->can('policy.publish') ?? false))
                ->modalDescription('Publishes a new version with these rules on Block. The pre-flight runs again first; a rule a company cannot meet is refused.')
                ->schema([
                    CheckboxList::make('rules')->label('Rules to block')->required()
                        ->options(fn () => collect($this->report()['rules'] ?? [])->reject(fn (array $r) => $r['mode'] === EmploymentRequirements::BLOCK)->map(fn (array $r) => $r['label'])->all()),
                    Toggle::make('accept_gaps')->label('Block although some current people would fail (their next change then needs the data)'),
                    DatePicker::make('effective_from')->label('From')->default(today())->minDate(today())->required(),
                    AuditReasonField::make()->required(),
                ])
                ->action(function (array $data) {
                    try {
                        app(EmploymentRequirementsPreflight::class)->switchToBlock($this->policy(), (array) $data['rules'], auth()->user(), (string) AuditReasonField::extract($data),
                            $data['effective_from'], (bool) ($data['accept_gaps'] ?? false));
                        $this->report = null;
                        Notification::make()->success()->title('Published with Block')->send();
                    } catch (ConfigurationException $e) {
                        Notification::make()->danger()->title('Not switched to Block')->body($e->getMessage())->persistent()->send();
                    }
                }),
        ];
    }
}
