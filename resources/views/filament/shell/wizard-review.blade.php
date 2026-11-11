{{--
    The Review step of a record form followed as a wizard (App\Filament\Support\FormWizard). Lists, step by step, what
    was filled in (create) or changed, Before → After (edit), read from the form itself in the browser; each step's card
    has Edit to go back to it. Saving stays the page's or drawer's own button.
--}}
<div class="pos-wizard-review" x-data="posFormReview(@js($mode))" aria-live="polite">
    <div class="pos-step-banner pos-step-tone" data-tone="idle">
        <span class="pos-step-banner-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-clipboard-document-check" class="pos-step-banner-svg" /></span>
        <div class="pos-step-banner-body">
            <p class="pos-step-banner-title" x-text="mode === 'create' ? 'Check what you filled in' : 'Check your changes'"></p>
            <p class="pos-step-banner-sub"
                x-text="items.length ? `${items.length} ${items.length === 1 ? 'detail' : 'details'} in ${groups.length} ${groups.length === 1 ? 'step' : 'steps'}. Nothing is saved until you confirm below.` : 'Nothing is saved until you confirm below.'"></p>
        </div>
    </div>

    <p class="pos-wizard-review-empty" x-show="! items.length"
        x-text="mode === 'create' ? 'Nothing filled in yet. Go back to a step to fill it in.' : 'No changes yet. Open a step from the list to change it.'"></p>

    <template x-for="group in groups" :key="group.label">
        <section class="pos-wizard-review-group" :aria-label="group.label">
            <header class="pos-wizard-review-head">
                <h3 class="pos-wizard-review-heading" x-text="group.label"></h3>
                <button type="button" class="pos-step-link" x-show="canEdit(group.label)" x-on:click="editStep(group.label)">
                    Edit<span class="fi-sr-only" x-text="' ' + group.label"></span>
                    <x-filament::icon icon="heroicon-m-pencil-square" class="pos-step-link-icon" />
                </button>
            </header>
            <dl class="pos-wizard-review-list">
                <template x-for="item in group.items" :key="item.key">
                    <div class="pos-wizard-review-row">
                        <dt x-text="item.label"></dt>
                        <dd>
                            <template x-if="mode !== 'create'"><span class="pos-change-before" x-text="item.before || 'Empty'"></span></template>
                            <template x-if="mode !== 'create'"><span aria-hidden="true" class="pos-muted">→</span></template>
                            <span class="pos-change-after" x-text="item.after || 'Empty'"></span>
                        </dd>
                    </div>
                </template>
            </dl>
        </section>
    </template>

    <p class="pos-wizard-review-foot"><x-filament::icon icon="heroicon-m-shield-check" />Recorded in the audit trail when saved. Nothing changes until you save.</p>
</div>
