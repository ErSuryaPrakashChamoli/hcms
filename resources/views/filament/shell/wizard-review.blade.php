{{--
    The Review step of a form followed as a wizard (App\Filament\Support\FormWizard), and the full list added to the
    review step of a wizard that builds its own steps. Lists every detail the person went through, step by step and
    section by section, including what was left empty, read from the form itself in the browser (posFormReview, scope
    'all'). Editing marks what changed, Before → After, and can show only the changes. Each step's card has Edit to go
    back to it. Saving stays the page's, drawer's or action's own button.
--}}
<div class="pos-wizard-review" x-data="posFormReview(@js($mode), 'all')">
    <div class="pos-step-banner pos-step-tone" data-tone="idle">
        <span class="pos-step-banner-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-clipboard-document-check" class="pos-step-banner-svg" /></span>
        <div class="pos-step-banner-body">
            <p class="pos-step-banner-title" x-text="mode === 'create' ? 'Check every detail before you confirm' : 'Check every detail and your changes'"></p>
            <p class="pos-step-banner-sub" aria-live="polite" x-text="summary"></p>
        </div>
    </div>

    <div class="pos-wizard-review-tools" x-show="mode !== 'create' && changes" x-cloak>
        <button type="button" class="pos-wizard-review-filter" role="switch" :aria-checked="onlyChanges.toString()" x-on:click="onlyChanges = ! onlyChanges">
            <span class="pos-wizard-review-filter-track" aria-hidden="true"><span></span></span>
            <span x-text="`Show only changes (${changes})`"></span>
        </button>
    </div>

    <p class="pos-wizard-review-empty" x-show="! items.length"
        x-text="mode === 'create' ? 'Nothing to review yet. Go back to a step to fill it in.' : 'Nothing to review yet. Open a step from the list.'"></p>

    <template x-for="group in groups" :key="group.label">
        <section class="pos-wizard-review-group" :aria-label="group.label || 'Details'">
            <header class="pos-wizard-review-head" x-show="group.label || canEdit(group.label)">
                <h3 class="pos-wizard-review-heading" x-text="group.label"></h3>
                <button type="button" class="pos-step-link" x-show="canEdit(group.label)" x-on:click="editStep(group.label)">
                    Edit<span class="fi-sr-only" x-text="' ' + group.label"></span>
                    <x-filament::icon icon="heroicon-m-pencil-square" class="pos-step-link-icon" />
                </button>
            </header>
            <template x-for="part in group.parts" :key="part.key">
                <div class="pos-wizard-review-part">
                    <h4 class="pos-wizard-review-subheading" x-show="part.label" x-text="part.label"></h4>
                    <dl class="pos-wizard-review-list">
                        <template x-for="item in part.items" :key="item.key">
                            <div class="pos-wizard-review-row" :data-changed="item.changed ? '' : null">
                                <dt>
                                    <span x-text="item.label"></span>
                                    <span class="pos-wizard-review-tag" x-show="item.changed">Changed</span>
                                </dt>
                                <dd>
                                    <template x-if="item.changed"><span class="pos-change-before" x-text="item.before || 'Empty'"></span></template>
                                    <template x-if="item.changed"><span aria-hidden="true" class="pos-muted">→</span></template>
                                    <span class="pos-change-after" :class="{ 'pos-wizard-review-blank': ! item.after }"
                                        x-text="item.after || (item.changed ? 'Empty' : 'Not filled in')"></span>
                                </dd>
                            </div>
                        </template>
                    </dl>
                </div>
            </template>
        </section>
    </template>

    <p class="pos-wizard-review-foot"><x-filament::icon icon="heroicon-m-shield-check" />Recorded in the audit trail when saved. Nothing changes until you confirm.</p>
</div>
