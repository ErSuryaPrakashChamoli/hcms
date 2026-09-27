<?php

namespace App\Filament\Pages;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Exceptions\InvalidHierarchyException;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Visual hierarchy builder (blueprint §10). Every action is an audited OrganisationTree call.
 */
class OrganisationDesigner extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Organisation Designer';

    protected string $view = 'filament.pages.organisation-designer';

    public string $search = '';

    public string $mode = 'tree';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('organisation.design') ?? false;
    }

    /** @return Collection<int, OrganisationNode> */
    public function getTreeProperty(): Collection
    {
        return app(OrganisationTree::class)->tree($this->search);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->addUnitAction('addRoot')->label('Add root unit')->icon(Heroicon::OutlinedPlus),
        ];
    }

    public function addChildAction(): Action
    {
        return $this->addUnitAction('addChild')->label('Add child')->iconButton()->icon(Heroicon::OutlinedPlus);
    }

    public function renameAction(): Action
    {
        return Action::make('rename')
            ->iconButton()->icon(Heroicon::OutlinedPencilSquare)->color('gray')
            ->modalHeading(fn (array $arguments) => 'Rename '.$this->node($arguments)->auditLabel())
            ->fillForm(fn (array $arguments) => ['name' => $this->node($arguments)->nodeable?->name])
            ->schema([
                TextInput::make('name')->required()->maxLength(255),
                AuditReasonField::make(),
            ])
            ->action(fn (array $arguments, array $data) => $this->guard(fn () => app(OrganisationTree::class)
                ->rename($this->node($arguments), $data['name'], $data[AuditReasonField::NAME] ?? null), 'Renamed'));
    }

    public function moveAction(): Action
    {
        return Action::make('move')
            ->iconButton()->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
            ->modalHeading(fn (array $arguments) => 'Move '.$this->node($arguments)->auditLabel())
            ->schema(fn (array $arguments) => [
                Select::make('parent_id')
                    ->label('New parent')
                    ->options(fn () => app(OrganisationTree::class)->options($this->node($arguments), $this->node($arguments)->typeKey()))
                    ->searchable()
                    ->helperText('Only parents allowed to contain this unit type are listed.')
                    ->required(fn () => ! $this->tree()->types()[$this->node($arguments)->typeKey()]['root']),
                AuditReasonField::make(),
            ])
            ->action(fn (array $arguments, array $data) => $this->guard(fn () => $this->tree()->move(
                $this->node($arguments),
                filled($data['parent_id'] ?? null) ? OrganisationNode::query()->findOrFail($data['parent_id']) : null,
                $data[AuditReasonField::NAME] ?? null,
            ), 'Moved'));
    }

    public function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->iconButton()->icon(Heroicon::OutlinedPauseCircle)->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Deactivate '.$this->node($arguments)->auditLabel())
            ->modalDescription('The unit and everything beneath it will be marked inactive. History is kept.')
            ->schema([AuditReasonField::make()->required()])
            ->action(fn (array $arguments, array $data) => $this->guard(fn () => $this->tree()
                ->setStatus($this->node($arguments), ActiveStatus::Inactive, $data[AuditReasonField::NAME] ?? null), 'Deactivated'));
    }

    public function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->iconButton()->icon(Heroicon::OutlinedPlayCircle)->color('success')
            ->schema([AuditReasonField::make()])
            ->action(fn (array $arguments, array $data) => $this->guard(fn () => $this->tree()
                ->setStatus($this->node($arguments), ActiveStatus::Active, $data[AuditReasonField::NAME] ?? null), 'Reactivated'));
    }

    public function moveUpAction(): Action
    {
        return Action::make('moveUp')->iconButton()->icon(Heroicon::OutlinedChevronUp)->color('gray')
            ->action(fn (array $arguments) => $this->guard(fn () => $this->tree()->reorder($this->node($arguments), 'up')));
    }

    public function moveDownAction(): Action
    {
        return Action::make('moveDown')->iconButton()->icon(Heroicon::OutlinedChevronDown)->color('gray')
            ->action(fn (array $arguments) => $this->guard(fn () => $this->tree()->reorder($this->node($arguments), 'down')));
    }

    public function removeAction(): Action
    {
        return Action::make('remove')
            ->iconButton()->icon(Heroicon::OutlinedXMark)->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Remove '.$this->node($arguments)->auditLabel().' from the tree')
            ->modalDescription('Only the placement is removed; the unit record itself stays and can be placed again.')
            ->schema([AuditReasonField::make()])
            ->action(fn (array $arguments, array $data) => $this->guard(fn () => $this->tree()
                ->detach($this->node($arguments), $data[AuditReasonField::NAME] ?? null), 'Removed'));
    }

    private function addUnitAction(string $name): Action
    {
        return Action::make($name)
            ->modalHeading(fn (array $arguments) => isset($arguments['node'])
                ? 'Add unit under '.$this->node($arguments)->auditLabel()
                : 'Add root unit')
            ->schema(function (array $arguments) {
                $parent = isset($arguments['node']) ? $this->node($arguments) : null;
                $types = $this->tree()->types();
                $allowed = $this->tree()->allowedChildTypes($parent);

                return [
                    Select::make('type')
                        ->options(collect($allowed)->mapWithKeys(fn (string $t) => [$t => $types[$t]['label']])->all())
                        ->required()
                        ->live(),
                    Radio::make('mode')
                        ->options(['new' => 'Create a new unit', 'existing' => 'Place an existing unit'])
                        ->default('new')
                        ->inline()
                        ->live(),
                    TextInput::make('name')->required()->maxLength(255)
                        ->visible(fn (Get $get) => $get('mode') === 'new')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, callable $set) => $set('code', Str::upper(Str::slug(Str::limit($state, 24, ''), '_')))),
                    TextInput::make('code')->required()->maxLength(32)->alphaDash()
                        ->visible(fn (Get $get) => $get('mode') === 'new'),
                    Select::make('unit_id')
                        ->label('Unit')
                        ->options(fn (Get $get) => $this->unplacedUnits($get('type')))
                        ->searchable()
                        ->required(fn (Get $get) => $get('mode') === 'existing')
                        ->visible(fn (Get $get) => $get('mode') === 'existing'),
                    AuditReasonField::make(),
                ];
            })
            ->action(function (array $arguments, array $data) {
                $parent = isset($arguments['node']) ? $this->node($arguments) : null;
                $reason = $data[AuditReasonField::NAME] ?? null;

                $this->guard(function () use ($data, $parent, $reason) {
                    if (($data['mode'] ?? 'new') === 'existing') {
                        $unit = $this->tree()->modelForType($data['type'])::query()->findOrFail($data['unit_id']);

                        return $this->tree()->attach($unit, $parent, $reason);
                    }

                    return $this->tree()->createUnit($data['type'], ['name' => $data['name'], 'code' => $data['code']], $parent, $reason);
                }, 'Unit added');
            });
    }

    /** @return array<int, string> */
    private function unplacedUnits(?string $type): array
    {
        if ($type === null) {
            return [];
        }

        return $this->tree()->modelForType($type)::query()
            ->whereDoesntHave('organisationNode')
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->mapWithKeys(fn ($u) => [$u->id => "{$u->name} ({$u->code})"])
            ->all();
    }

    private function node(array $arguments): OrganisationNode
    {
        return OrganisationNode::query()->with('nodeable')->findOrFail($arguments['node'] ?? null);
    }

    private function tree(): OrganisationTree
    {
        return app(OrganisationTree::class);
    }

    private function guard(callable $callback, ?string $success = null): void
    {
        try {
            $callback();

            if ($success) {
                Notification::make()->success()->title($success)->send();
            }
        } catch (InvalidHierarchyException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
        }
    }
}
