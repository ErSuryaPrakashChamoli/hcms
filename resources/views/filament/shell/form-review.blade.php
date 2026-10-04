{{--
    UX.15 closure: Review before Confirm on record forms. Lists the fields changed in this form (create: filled in) as
    Before → After, read from the form itself in the browser; saving stays the page's own action.
--}}
<section class="pos-form-review" x-data="posFormReview(@js($mode))" x-cloak x-show="items.length" x-transition.opacity aria-live="polite" aria-labelledby="pos-form-review-title">
    <div class="pos-form-review-head">
        <h2 id="pos-form-review-title" class="pos-sec-title">
            <span x-text="mode === 'create' ? 'Review before creating' : 'Your changes'"></span><span class="pos-sec-count" x-text="items.length"></span>
        </h2>
        <button type="button" class="pos-btn pos-btn-primary pos-btn-sm" x-on:click="submit()" x-text="mode === 'create' ? @js($createLabel) : (items.length === 1 ? 'Save 1 change' : `Save ${items.length} changes`)"></button>
    </div>
    <ul class="pos-form-review-list">
        <template x-for="item in items" :key="item.label">
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
    <p class="pos-meta">Recorded in the audit trail when saved. Nothing changes until you save.</p>
</section>
