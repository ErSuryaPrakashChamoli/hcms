<?php

namespace App\Filament\Support\Forms;

use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\View\FormsIconAlias;
use Filament\Support\Enums\GridDirection;
use Filament\Support\Icons\Heroicon;
use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
use Filament\Support\View\Components\InputComponent\WrapperComponent\IconComponent;
use Illuminate\Support\Js;

use function Filament\Support\generate_icon_html;

/**
 * A long checkbox list shown in groups (owner, 2026-11-19: role permissions and My space pages "bifurcated module
 * wise", each explained in a few lines). An area has a plain explanation and a live count, and may be split into
 * sections (modules) with their own line and "Tick all · Clear". Above the groups: a short "how this works" note, a
 * live summary of what is ticked (each area a chip that opens it), one search across everything and open/close all.
 *
 * It is the same field as CheckboxList — the same state, validation, relationship saving and wire:model binding, and
 * the same option markup the wizard's Review reads (which lists the ticked options one line per section through
 * data-pos-review-group). Every option is shown: one that no group names lands in "Other", so nothing can stay
 * ticked out of sight. Behaviour lives in posGroupedChecks (resources/js/peopleos.js); styles in theme.css §T.
 */
class GroupedCheckboxList extends CheckboxList
{
    /** @var array<int, array<string, mixed>>|Closure */
    protected array|Closure $groups = [];

    /** @var array<int|string, string>|Closure value => a small code shown after the option's name (a permission key) */
    protected array|Closure $optionCodes = [];

    protected string|Closure $countWord = 'ticked';

    protected bool|Closure $groupsCollapsed = false;

    protected string|Closure|null $summaryLead = null;

    protected string|Closure|null $summaryEmpty = null;

    protected string|Closure|null $explainerTitle = null;

    /** @var list<string>|Closure */
    protected array|Closure $explainerPoints = [];

    /**
     * The groups, in display order: `[['key' => …, 'label' => …, 'hint' => …, 'sections' => [['label' => ?…, 'hint' =>
     * ?…, 'options' => [value, …]], …]], …]`. A section without a label shows its options straight under the area.
     *
     * @param  array<int, array<string, mixed>>|Closure  $groups
     */
    public function groups(array|Closure $groups): static
    {
        $this->groups = $groups;

        return $this;
    }

    /** @param  array<int|string, string>|Closure  $codes */
    public function optionCodes(array|Closure $codes): static
    {
        $this->optionCodes = $codes;

        return $this;
    }

    /** The word after a count: "3 of 16 allowed", "2 of 9 hidden". */
    public function countWord(string|Closure $word): static
    {
        $this->countWord = $word;

        return $this;
    }

    /** Whether areas start closed (the summary and the counts still show what each holds). */
    public function groupsCollapsed(bool|Closure $condition = true): static
    {
        $this->groupsCollapsed = $condition;

        return $this;
    }

    /** The summary line: its lead ("This role can work in") and what it says when nothing is ticked. */
    public function summary(string|Closure|null $lead, string|Closure|null $empty = null): static
    {
        $this->summaryLead = $lead;
        $this->summaryEmpty = $empty;

        return $this;
    }

    /**
     * A short note above the list: what ticking means, in a few points.
     *
     * @param  list<string>|Closure  $points
     */
    public function explainer(string|Closure|null $title, array|Closure $points): static
    {
        $this->explainerTitle = $title;
        $this->explainerPoints = $points;

        return $this;
    }

    /**
     * The groups as given, holding only options that exist (each once), every other option in a closing "Other" group,
     * and empty sections and groups left out.
     *
     * @return list<array{key: string, label: string, hint: string, sections: list<array{label: string, hint: string, options: list<int|string>}>}>
     */
    public function getGroups(): array
    {
        $options = $this->getOptions();
        $seen = [];
        $groups = [];
        foreach ((array) $this->evaluate($this->groups, ['options' => $options]) as $i => $group) {
            $sections = [];
            foreach ((array) ($group['sections'] ?? []) as $section) {
                $values = [];
                foreach ((array) ($section['options'] ?? []) as $value) {
                    if (array_key_exists($value, $options) && ! isset($seen[$value])) {
                        $seen[$value] = true;
                        $values[] = $value;
                    }
                }
                if ($values !== []) {
                    $sections[] = ['label' => (string) ($section['label'] ?? ''), 'hint' => (string) ($section['hint'] ?? ''), 'options' => $values];
                }
            }
            if ($sections !== []) {
                $groups[] = ['key' => (string) ($group['key'] ?? 'g'.$i), 'label' => (string) ($group['label'] ?? ''), 'hint' => (string) ($group['hint'] ?? ''), 'sections' => $sections];
            }
        }
        $rest = array_values(array_filter(array_keys($options), fn ($value) => ! isset($seen[$value])));
        if ($rest !== []) {
            $groups[] = ['key' => 'other-options', 'label' => 'Other', 'hint' => '', 'sections' => [['label' => '', 'hint' => '', 'options' => $rest]]];
        }

        return $groups;
    }

    public function toEmbeddedHtml(): string
    {
        $id = $this->getId();
        $isDisabled = $this->isDisabled();
        $canToggle = $this->isBulkToggleable() && ! $isDisabled;
        $statePath = $this->getStatePath();
        $options = $this->getOptions();
        $codes = (array) $this->evaluate($this->optionCodes);
        $groups = $this->getGroups();
        $livewireKey = $this->getLivewireKey();
        $wireModelAttribute = $this->applyStateBindingModifiers('wire:model');
        $hasError = $this->hasErrorForPath($statePath);
        $collapsed = (bool) $this->evaluate($this->groupsCollapsed);
        $word = (string) $this->evaluate($this->countWord);
        $lead = $this->evaluate($this->summaryLead);
        $empty = $this->evaluate($this->summaryEmpty);
        $explainerTitle = $this->evaluate($this->explainerTitle);
        $explainerPoints = (array) $this->evaluate($this->explainerPoints);
        $config = [
            'groups' => array_map(fn (array $g) => ['key' => $g['key'], 'label' => $g['label']], $groups),
            'collapsed' => $collapsed,
            'word' => $word,
        ];
        $optionsAttributes = fn () => $this->getExtraAttributeBag()
            ->grid($this->getColumns(), $this->getGridDirection() ?? GridDirection::Column)
            ->class(['fi-fo-checkbox-list-options', 'pos-gcl-options']);
        $link = fn (string $label, string $click, string $sr = '') => '<button type="button" class="pos-gcl-link" x-on:click="'.e($click).'">'.e($label).($sr !== '' ? '<span class="fi-sr-only"> '.e($sr).'</span>' : '').'</button>';

        ob_start(); ?>

        <div
            aria-labelledby="<?= e($id) ?>-label"
            role="group"
            x-data="posGroupedChecks(<?= e(Js::from($config)) ?>)"
            data-pos-grouped
            <?= $this->getExtraAlpineAttributeBag()->class(['fi-fo-checkbox-list', 'pos-gcl'])->toHtml() ?>
        >
            <?php if ($explainerTitle || $explainerPoints) { ?>
                <div class="pos-gcl-explain">
                    <?= generate_icon_html(Heroicon::OutlinedInformationCircle, attributes: (new FilamentComponentAttributeBag)->class(['pos-gcl-explain-icon']))?->toHtml() ?>
                    <div>
                        <?php if ($explainerTitle) { ?><p class="pos-gcl-explain-title"><?= e($explainerTitle) ?></p><?php } ?>
                        <?php if ($explainerPoints) { ?>
                            <ul class="pos-gcl-explain-list">
                                <?php foreach ($explainerPoints as $point) { ?><li><?= e($point) ?></li><?php } ?>
                            </ul>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>

            <?php if ($lead) { ?>
                <div class="pos-gcl-summary" aria-live="polite">
                    <span class="pos-gcl-summary-lead"><?= e($lead) ?></span>
                    <span class="pos-gcl-summary-empty" x-show="! total"><?= e($empty ?? 'Nothing ticked yet.') ?></span>
                    <template x-for="g in tickedGroups" :key="g.key">
                        <button type="button" class="pos-gcl-chip" x-on:click="reveal(g.key)">
                            <span x-text="g.label"></span><span class="pos-gcl-chip-count" x-text="g.count"></span>
                        </button>
                    </template>
                </div>
            <?php } ?>

            <div class="pos-gcl-tools">
                <div <?= (new FilamentComponentAttributeBag)->class(['fi-input-wrp', 'pos-gcl-search'])->toHtml() ?>>
                    <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-inline">
                        <?= generate_icon_html(Heroicon::MagnifyingGlass, FormsIconAlias::COMPONENTS_CHECKBOX_LIST_SEARCH_FIELD, (new FilamentComponentAttributeBag)->color(IconComponent::class, 'gray'))?->toHtml() ?>
                    </div>
                    <div class="fi-input-wrp-content-ctn">
                        <input type="search" x-model.debounce.150ms="search" class="fi-input fi-input-has-inline-prefix"
                            aria-label="<?= e($this->getSearchPrompt()) ?>" placeholder="<?= e($this->getSearchPrompt()) ?>" />
                    </div>
                </div>
                <div class="pos-gcl-tool-links">
                    <?= $link('Open all', 'openAll(true)') ?>
                    <?= $link('Close all', 'openAll(false)') ?>
                    <?php if ($canToggle && count($options)) { ?>
                        <span class="pos-gcl-sep" aria-hidden="true"></span>
                        <?= $link('Tick all', 'tickIn($root, true)') ?>
                        <?= $link('Clear all', 'tickIn($root, false)') ?>
                    <?php } ?>
                </div>
            </div>

            <div class="pos-gcl-groups">
                <?php foreach ($groups as $group) {
                    $key = $group['key'];
                    $bodyId = $id.'-group-'.$key;
                    $jsKey = e(Js::from($key)); ?>
                    <section class="pos-gcl-group" data-pos-gcl-group="<?= e($key) ?>" wire:key="<?= e($livewireKey) ?>.groups.<?= e($key) ?>"
                        x-show="anyMatch($el)" :data-open="isOpen(<?= $jsKey ?>) ? '' : null" :data-state="state(<?= $jsKey ?>)">
                        <div class="pos-gcl-head">
                            <button type="button" class="pos-gcl-toggle" x-on:click="toggle(<?= $jsKey ?>)"
                                :aria-expanded="isOpen(<?= $jsKey ?>).toString()" aria-controls="<?= e($bodyId) ?>">
                                <?= generate_icon_html(Heroicon::ChevronRight, attributes: (new FilamentComponentAttributeBag)->class(['pos-gcl-chevron']))?->toHtml() ?>
                                <span class="pos-gcl-title"><?= e($group['label']) ?></span>
                                <span class="pos-gcl-count" x-text="countText(<?= $jsKey ?>)"></span>
                            </button>
                            <?php if ($group['hint'] !== '') { ?><p class="pos-gcl-hint"><?= e($group['hint']) ?></p><?php } ?>
                        </div>

                        <div id="<?= e($bodyId) ?>" class="pos-gcl-body" x-show="isOpen(<?= $jsKey ?>)" <?= $collapsed ? 'x-cloak' : '' ?>>
                            <?php
                            // The area's own links where its sections have none of their own (no section names) or there are several;
                            // one named section carries its own links, so the area does not repeat them.
                            $named = $group['sections'][0]['label'] !== '';
                    if ($canToggle && (! $named || count($group['sections']) > 1)) { ?>
                                <div class="pos-gcl-body-tools">
                                    <?= $link($named ? 'Tick whole area' : 'Tick all', 'tickIn($el.closest(\'[data-pos-gcl-group]\'), true)', $named ? '('.$group['label'].')' : 'in '.$group['label']) ?>
                                    <?= $link($named ? 'Clear area' : 'Clear', 'tickIn($el.closest(\'[data-pos-gcl-group]\'), false)', $named ? '('.$group['label'].')' : 'in '.$group['label']) ?>
                                </div>
                            <?php } ?>
                            <?php foreach ($group['sections'] as $s => $section) {
                                $reviewGroup = $section['label'] !== '' ? $section['label'] : $group['label']; ?>
                                <div class="pos-gcl-section" data-pos-gcl-section x-show="anyMatch($el)" wire:key="<?= e($livewireKey) ?>.groups.<?= e($key) ?>.<?= $s ?>">
                                    <?php if ($section['label'] !== '') { ?>
                                        <div class="pos-gcl-section-head">
                                            <div>
                                                <p class="pos-gcl-section-title"><?= e($section['label']) ?></p>
                                                <?php if ($section['hint'] !== '') { ?><p class="pos-gcl-section-hint"><?= e($section['hint']) ?></p><?php } ?>
                                            </div>
                                            <?php if ($canToggle && count($section['options']) > 1) { ?>
                                                <div class="pos-gcl-section-tools">
                                                    <?= $link('Tick all', 'tickIn($el.closest(\'[data-pos-gcl-section]\'), true)', 'in '.$section['label']) ?>
                                                    <?= $link('Clear', 'tickIn($el.closest(\'[data-pos-gcl-section]\'), false)', 'in '.$section['label']) ?>
                                                </div>
                                            <?php } ?>
                                        </div>
                                    <?php } ?>
                                    <div <?= $optionsAttributes()->toHtml() ?>>
                                        <?php foreach ($section['options'] as $value) {
                                            $label = (string) $options[$value];
                                            $code = (string) ($codes[$value] ?? '');
                                            $description = $this->hasDescription($value) ? (string) $this->getDescription($value) : '';
                                            $search = mb_strtolower(implode(' ', [$label, $code, $description, $section['label'], $group['label']])); ?>
                                            <div class="fi-fo-checkbox-list-option-ctn" data-pos-gcl-option data-search="<?= e($search) ?>"
                                                x-show="matches($el)" wire:key="<?= e($livewireKey) ?>.options.<?= e($value) ?>">
                                                <label class="fi-fo-checkbox-list-option">
                                                    <input
                                                        type="checkbox"
                                                        <?= $this->getExtraInputAttributeBag()
                                                            ->merge([
                                                                'disabled' => $isDisabled || $this->isOptionDisabled($value, $label),
                                                                'value' => e($value),
                                                                'wire:loading.attr' => 'disabled',
                                                                $wireModelAttribute => $statePath,
                                                                'data-pos-review-group' => $reviewGroup,
                                                            ], escape: false)
                                                            ->class(['fi-checkbox-input', 'fi-valid' => ! $hasError, 'fi-invalid' => $hasError])
                                                            ->toHtml() ?>
                                                    />
                                                    <div class="fi-fo-checkbox-list-option-text">
                                                        <span class="pos-gcl-option-name">
                                                            <span class="fi-fo-checkbox-list-option-label"><?= e($label) ?></span>
                                                            <?php if ($code !== '') { ?><code class="pos-gcl-code"><?= e($code) ?></code><?php } ?>
                                                        </span>
                                                        <?php if ($description !== '') { ?>
                                                            <p class="fi-fo-checkbox-list-option-description"><?= e($description) ?></p>
                                                        <?php } ?>
                                                    </div>
                                                </label>
                                            </div>
                                        <?php } ?>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </section>
                <?php } ?>
            </div>

            <p class="pos-gcl-none" x-cloak x-show="noMatches()" role="status" aria-live="polite"><?= e($this->getNoSearchResultsMessage()) ?></p>
        </div>

        <?php return $this->wrapEmbeddedHtml(ob_get_clean(), labelTag: 'div');
    }
}
