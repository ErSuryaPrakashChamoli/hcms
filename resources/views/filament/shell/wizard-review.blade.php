{{--
    The Review step of a record form followed as a wizard (App\Filament\Support\FormWizard). Lists, step by step, what
    was filled in (create) or changed, Before → After (edit), read from the form itself in the browser; saving stays the
    page's or drawer's own button.
--}}
<div class="pos-wizard-review" x-data="posFormReview(@js($mode))" aria-live="polite">
    <p class="pos-meta" x-show="! items.length"
        x-text="mode === 'create' ? 'Nothing filled in yet. Go back to a step to fill it in.' : 'No changes yet. Open a step from the list to change it.'"></p>
    <template x-for="group in groups" :key="group.label">
        <section class="pos-wizard-review-group">
            <h3 class="pos-wizard-review-heading" x-text="group.label"></h3>
            <ul class="pos-form-review-list">
                <template x-for="item in group.items" :key="item.key">
                    <li>
                        <span class="pos-form-review-label" x-text="item.label"></span>
                        <span class="pos-form-review-values">
                            <template x-if="mode !== 'create'"><span class="pos-change-before" x-text="item.before || 'Empty'"></span></template>
                            <template x-if="mode !== 'create'"><span aria-hidden="true" class="pos-muted">→</span></template>
                            <span class="pos-change-after" x-text="item.after || 'Empty'"></span>
                        </span>
                    </li>
                </template>
            </ul>
        </section>
    </template>
    <p class="pos-meta">Recorded in the audit trail when saved. Nothing changes until you save.</p>
</div>
