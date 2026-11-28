/*
 * PeopleOS interaction layer (Alpine, no framework). Keyboard-first, reduced-motion aware.
 * Every action here only opens screens or calls Livewire methods; the server decides access.
 */

const isTyping = (e) => {
    const t = e.target;
    return t && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName));
};

/*
 * UX.18: a Livewire call cut short by leaving the page rejects with a plain object ({ status: null, ... }); Firefox and
 * WebKit report an uncaught "[object Object]" when nobody catches it. Leaving mid-call is not an error, so the
 * interaction layer's own calls catch it (Livewire still runs its failure hooks).
 */
const quiet = (call) => (call && typeof call.catch === 'function' ? call.catch(() => {}) : call);

/*
 * UX.18: bring the chosen chip of a scrolling chip row into sight by scrolling the row itself. scrollIntoView() moved
 * Chromium's sequential-focus starting point to the chip, so the first Tab on Home skipped the skip link, the header
 * and the navigation (WCAG 2.4.3). Only rows that actually overflow (phones) are scrolled.
 */
window.posRevealChip = (row, chip) => {
    if (!row || !chip) return;
    const reveal = () => {
        if (row.scrollWidth <= row.clientWidth) return;
        row.scrollLeft += chip.getBoundingClientRect().left - row.getBoundingClientRect().left - 16;
    };
    reveal();
    // The row may only overflow once web fonts have loaded (seen in the WebKit engine): reveal again then.
    requestAnimationFrame(reveal);
    document.fonts?.ready.then(reveal);
};

const go = (url) => {
    if (!url) return;
    if (window.Livewire?.navigate) window.Livewire.navigate(url);
    else window.location.assign(url);
};

/*
 * Components are plain factories exposed on window (so x-data="posCommand(...)" resolves even when a page
 * arrives through wire:navigate) and are also registered with Alpine.data when Alpine starts.
 */
const components = {};

/* Command center (⌘K / Ctrl+K) */
components.posCommand = (initialMode = 'all') => ({
        isOpen: false,
        activeId: null,
        actionIndex: -1,
        mode: initialMode,
        lastFocus: null,
        onViewport: null,

        init() {
            // Keep the highlighted row valid when results re-render.
            this.$watch('isOpen', (v) => document.documentElement.classList.toggle('pos-command-open', v));
            // Results re-render on the server: re-select a valid row (observer dies with the element).
            new MutationObserver(() => this.ensureActive()).observe(this.$refs.list, { childList: true, subtree: true });
        },
        items() {
            return Array.from(this.$refs.list?.querySelectorAll('[role="option"]') ?? []);
        },
        ensureActive() {
            const items = this.items();
            if (!items.length) { this.activeId = null; return; }
            if (!items.some((el) => el.id === this.activeId)) this.activate(items[0].id);
        },
        open(detail = {}) {
            this.lastFocus = document.activeElement;
            this.isOpen = true;
            const mode = detail?.mode ?? 'all';
            if (mode !== this.mode) { this.mode = mode; }
            quiet(this.$wire.opened(mode).then(() => { if (detail?.pick) return quiet(this.$wire.pick(detail.pick)); }));
            this.$nextTick(() => { this.$refs.input?.focus(); this.$refs.input?.select(); this.ensureActive(); });
            this.trackViewport(true);
        },
        close() {
            this.isOpen = false;
            this.actionIndex = -1;
            this.trackViewport(false);
            this.$nextTick(() => this.lastFocus?.focus?.());
        },
        // UX.17: on phones the sheet takes the visible viewport, which shrinks when the on-screen keyboard opens.
        trackViewport(on) {
            const vv = window.visualViewport;
            const dialog = this.$refs.dialog;
            if (!vv || !dialog) return;
            if (on && !this.onViewport) {
                this.onViewport = () => dialog.style.setProperty('--pos-vvh', `${Math.round(vv.height)}px`);
                this.onViewport();
                vv.addEventListener('resize', this.onViewport);
            } else if (!on && this.onViewport) {
                vv.removeEventListener('resize', this.onViewport);
                this.onViewport = null;
                dialog.style.removeProperty('--pos-vvh');
            }
        },
        activate(id) {
            if (this.activeId !== id) this.actionIndex = -1;
            this.activeId = id;
            document.getElementById(id)?.scrollIntoView({ block: 'nearest' });
        },
        move(step) {
            const items = this.items();
            if (!items.length) return;
            const i = items.findIndex((el) => el.id === this.activeId);
            const next = items[(i + step + items.length) % items.length];
            this.activate(next.id);
        },
        currentActions() {
            const el = document.getElementById(this.activeId);
            try { return JSON.parse(el?.dataset.actions || '[]'); } catch (e) { return []; }
        },
        actionMove(e, step) {
            const actions = this.currentActions();
            if (!actions.length) return;
            e.preventDefault();
            this.actionIndex = Math.max(-1, Math.min(actions.length - 1, this.actionIndex + step));
        },
        cycleMode(e) {
            e.preventDefault();
            const modes = ['all', 'actions', 'people'];
            const next = modes[(modes.indexOf(this.mode) + (e.shiftKey ? 2 : 1)) % 3];
            this.mode = next;
            quiet(this.$wire.setMode(next));
        },
        choose(el, preview = false) {
            el = el ?? document.getElementById(this.activeId);
            if (!el) return;
            if (this.actionIndex >= 0) {
                const action = this.currentActions()[this.actionIndex];
                if (action) return this.runActionData(action, el.dataset.id);
            }
            const drawer = JSON.parse(el.dataset.drawer || 'null');
            const url = el.dataset.url;
            if (url && url.startsWith('#pick:')) {
                // A change that needs a person first: stay open and ask whom (the server re-checks access).
                quiet(this.$wire.pick(url.slice(6)).then(() => this.$nextTick(() => { this.$refs.input?.focus(); this.ensureActive(); })));
                return;
            }
            quiet(this.$wire.remember(el.dataset.id));
            if ((preview || !url) && drawer) {
                this.close();
                window.dispatchEvent(new CustomEvent('pos-drawer-open', { detail: drawer }));
                return;
            }
            if (url) { this.close(); go(url); }
        },
        runActionData(action, id = null) {
            const el = document.getElementById(this.activeId);
            quiet(this.$wire.remember(id ?? el?.dataset.id ?? ''));
            this.close();
            if (action.drawer) window.dispatchEvent(new CustomEvent('pos-drawer-open', { detail: action.drawer }));
            else go(action.url);
        },
        globalKey(e) {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                this.isOpen ? this.close() : this.open({ mode: 'all' });
                return;
            }
            // UX.18: Escape closes the open center wherever focus is (it only listened inside the dialog; a press that
            // landed before focus moved in was lost).
            if (this.isOpen && e.key === 'Escape') { e.preventDefault(); this.close(); return; }
            if (this.isOpen || isTyping(e) || e.metaKey || e.ctrlKey || e.altKey) return;
            if (e.key === '/') { e.preventDefault(); this.open({ mode: 'all' }); }
            if (e.key === 'n' && !document.querySelector('.fi-modal-open')) { e.preventDefault(); this.open({ mode: 'actions' }); }
        },
    });

/* Keyboard list navigation for inboxes: J/K move, Enter opens, A/R decide (approvals). */
components.posListNav = (selector) => ({
        index: -1,
        handle(e) {
            if (isTyping(e) || e.metaKey || e.ctrlKey || e.altKey || document.documentElement.classList.contains('pos-command-open')) return;
            // Closed Filament modals stay in the DOM; only an open one (or an open drawer) pauses list keys.
            if (document.querySelector('.fi-modal-open, .pos-drawer[data-open="true"], .pos-ai-panel[data-open="true"]')) return;
            const rows = Array.from(this.$root.querySelectorAll(selector));
            if (!rows.length) return;
            const key = e.key.toLowerCase();
            if (key === 'j' || key === 'arrowdown') { e.preventDefault(); this.focus(rows, Math.min(rows.length - 1, this.index + 1)); }
            else if (key === 'k' || key === 'arrowup') { e.preventDefault(); this.focus(rows, Math.max(0, this.index - 1)); }
            else if (key === 'enter' && this.index >= 0) { rows[this.index]?.querySelector('[data-open]')?.click(); }
            else if ((key === 'a' || key === 'r') && this.index >= 0) {
                const id = rows[this.index]?.dataset.approvalId;
                if (id) { e.preventDefault(); window.dispatchEvent(new CustomEvent('pos-approval-shortcut', { detail: { id, decision: key === 'a' ? 'approve' : 'reject' } })); }
            }
        },
        focus(rows, i) {
            this.index = i;
            rows.forEach((r, j) => r.toggleAttribute('data-focused', j === i));
            rows[i].focus({ preventScroll: true });
            rows[i].scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        },
    });

/* Drawer shell: focus trap, Esc, return focus, stacking (data-depth). */
components.posDrawer = () => ({
        open: false,
        lastFocus: null,
        show() { this.lastFocus = document.activeElement; this.open = true; },
        hide() { this.open = false; quiet(this.$wire.close()); this.$nextTick(() => this.lastFocus?.focus?.()); },
    });

/*
 * People peek (UX.15): the Peek level of Peek → Drawer → Workspace. Any [data-person] chip shows a small
 * card after a short hover or keyboard focus; the card is drawn from PeekHost::peek(), which answers only
 * with directory fields the viewer may already see. Touch devices skip the peek (a tap opens the drawer).
 * Document listeners are bound once and always talk to the newest host (SPA navigation re-creates it).
 */
components.posPeek = (wire) => ({
        open: false, data: null, loading: false, style: '', trigger: null, timer: null, hideTimer: null,
        init() {
            window.__posPeek = this;
            this.cache = window.__posPeekCache ?? (window.__posPeekCache = {});
            if (window.__posPeekBound) return;
            window.__posPeekBound = true;
            const canHover = () => window.matchMedia('(hover: hover) and (pointer: fine)').matches;
            const host = () => window.__posPeek;
            const chip = (e) => e.target?.closest?.('[data-person]');
            document.addEventListener('mouseover', (e) => { const t = chip(e); if (t && canHover() && t !== host()?.trigger) host()?.schedule(t); });
            document.addEventListener('mouseout', (e) => { const t = chip(e); if (t && !t.contains(e.relatedTarget)) host()?.leave(); });
            document.addEventListener('focusin', (e) => { const t = chip(e); if (t && t.matches(':focus-visible')) host()?.schedule(t); });
            document.addEventListener('focusout', (e) => { if (chip(e)) host()?.leave(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && host()?.open) host().hide(); });
            window.addEventListener('scroll', () => { if (host()?.open) host().hide(); }, { passive: true, capture: true });
        },
        schedule(t) {
            clearTimeout(this.timer); clearTimeout(this.hideTimer);
            const delay = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--pos-peek-delay')) || 350;
            this.timer = setTimeout(() => this.show(t), delay);
        },
        show(t) {
            if (!document.body.contains(t)) return;
            const id = parseInt(t.dataset.person, 10);
            if (!id) return;
            this.trigger?.removeAttribute('aria-describedby');
            this.trigger = t;
            t.setAttribute('aria-describedby', 'pos-peek');
            this.place(t);
            this.data = this.cache[id] ?? null;
            this.loading = this.data === null;
            this.open = true;
            if (this.data === null) {
                quiet(wire.peek(id).then((d) => { this.cache[id] = d; if (this.trigger === t) { this.data = d; this.loading = false; } }))
                    .catch(() => { if (this.trigger === t) { this.loading = false; } });
            }
        },
        place(t) {
            const r = t.getBoundingClientRect();
            const left = Math.max(12, Math.min(r.left, window.innerWidth - 312));
            this.style = r.bottom + 240 < window.innerHeight
                ? `left:${left}px; top:${r.bottom + 8}px`
                : `left:${left}px; bottom:${window.innerHeight - r.top + 8}px`;
        },
        keep() { clearTimeout(this.hideTimer); },
        leave() { clearTimeout(this.timer); this.hideTimer = setTimeout(() => this.hide(), 180); },
        hide() {
            clearTimeout(this.timer); clearTimeout(this.hideTimer);
            this.open = false;
            this.trigger?.removeAttribute('aria-describedby');
            this.trigger = null;
        },
        drawer() {
            const id = this.data?.id;
            this.hide();
            if (id) window.dispatchEvent(new CustomEvent('pos-drawer-open', { detail: { type: 'person', id } }));
        },
    });

/*
 * Rail (desktop): the handle on the brand block's edge keeps the menu open or folds it to icons; that choice is
 * Filament's own (isOpenDesktop, remembered). While folded, resting the mouse on the rail opens it over the page without
 * moving the page (a peek) until the mouse leaves. A peek only sets isOpen, which Filament puts back on every load and
 * navigation. Touch never peeks, and below 1024 px the rail is Filament's drawer.
 */
components.posRail = () => ({
        tip: false, timer: null, abort: null,
        get pinned() { return this.$store.sidebar.isOpenDesktop; },
        peeking: () => document.documentElement.hasAttribute('data-pos-rail-peek'),
        init() {
            this.unpeek();
            const rail = document.querySelector('.fi-main-sidebar');
            if (!rail) return;
            this.abort = new AbortController();
            const on = { signal: this.abort.signal };
            const mouse = (e) => e.pointerType === 'mouse' && window.innerWidth >= 1024;
            const leave = () => {
                clearTimeout(this.timer);
                if (this.peeking()) this.timer = setTimeout(() => this.unpeek(), 220);
            };
            rail.addEventListener('pointerover', (e) => {
                if (!mouse(e) || this.pinned) return;
                clearTimeout(this.timer);
                if (!this.peeking()) this.timer = setTimeout(() => this.peek(), 140);
            }, on);
            rail.addEventListener('pointerleave', leave, on);
            // Moving from a peek up to the handle keeps the peek, so the click pins what is on screen.
            this.$el.addEventListener('pointerenter', () => clearTimeout(this.timer), on);
            this.$el.addEventListener('pointerleave', leave, on);
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && this.peeking()) this.unpeek(); }, on);
        },
        destroy() { this.abort?.abort(); this.unpeek(); },
        peek() {
            if (this.pinned || window.innerWidth < 1024) return;
            document.documentElement.setAttribute('data-pos-rail-peek', '');
            this.$store.sidebar.isOpen = true;
        },
        unpeek() {
            clearTimeout(this.timer);
            if (!this.peeking()) return;
            if (!this.pinned) this.$store.sidebar.isOpen = false;
            document.documentElement.removeAttribute('data-pos-rail-peek');
        },
        toggle() {
            const pinned = this.pinned;
            clearTimeout(this.timer);
            document.documentElement.removeAttribute('data-pos-rail-peek');
            if (pinned) this.$store.sidebar.close();
            else this.$store.sidebar.open();
        },
    });

/* Organisation map: pan and zoom with mouse, touch and keyboard. */
components.posPanZoom = () => ({
        scale: 1, x: 0, y: 0, dragging: false, sx: 0, sy: 0,
        zoom(step) { this.scale = Math.min(2, Math.max(0.4, +(this.scale + step).toFixed(2))); },
        reset() { this.scale = 1; this.x = 0; this.y = 0; },
        start(e) { if (e.target.closest('button,a')) return; this.dragging = true; const p = e.touches?.[0] ?? e; this.sx = p.clientX - this.x; this.sy = p.clientY - this.y; },
        drag(e) { if (!this.dragging) return; const p = e.touches?.[0] ?? e; this.x = p.clientX - this.sx; this.y = p.clientY - this.sy; },
        end() { this.dragging = false; },
        wheel(e) { if (!e.ctrlKey && !e.metaKey) return; e.preventDefault(); this.zoom(e.deltaY < 0 ? 0.1 : -0.1); },
        key(e) {
            const k = e.key;
            if (k === '+' || k === '=') this.zoom(0.1);
            else if (k === '-') this.zoom(-0.1);
            else if (k === '0') this.reset();
            else if (k === 'ArrowLeft') this.x += 40; else if (k === 'ArrowRight') this.x -= 40;
            else if (k === 'ArrowUp') this.y += 40; else if (k === 'ArrowDown') this.y -= 40;
            else return;
            e.preventDefault();
        },
        get style() { return `transform: translate(${this.x}px, ${this.y}px) scale(${this.scale})`; },
    });

/*
 * Form review (UX.15 closure): the Review step of Context → Information → Change → Review → Confirm on record
 * forms. It reads only what is already in this form (labels and the values the person sees) and masks anything that
 * looks like a secret. It sends nothing anywhere and decides nothing: saving is still the page's own action.
 *
 * Two scopes:
 * - 'all' (a wizard's Review step, FormWizard): every detail the person went through, step by step and section by
 *   section, including what was left empty ("Not filled in"), so the whole record can be checked before it is saved
 *   (owner, 2026-11-19: "the review must show every detail"). Editing marks what changed as Before → After and can
 *   narrow the list to the changes.
 * - 'changes' (the short panel under a one-screen drawer form): only what was filled in (create) or changed (edit).
 *
 * In a wizard it reads every step (each step is its own form) and leaves out its own step (the reason for change, a
 * confirmation), which the person sees right there.
 */
components.posFormReview = (mode = 'edit', scope = 'changes') => ({
    mode,
    scope,
    items: [],
    onlyChanges: false,
    baseline: null,
    form: null,
    ownStep: null,
    init() {
        // A wizard's review reads all its steps; a drawer form contains its review; a record page's review sits after the page form.
        this.form = this.$root.closest('.fi-sc-wizard') ?? this.$root.closest('form') ?? this.$root.closest('.fi-page')?.querySelector('form');
        this.ownStep = this.$root.closest('.fi-sc-wizard-step');
        if (!this.form) return;
        const update = () => { clearTimeout(this.t); this.t = setTimeout(() => this.refresh(), 120); };
        // Snapshot once Livewire and the field components have rendered their values. Until the person changes a field,
        // what the form shows stays the starting point: selects and date pickers that finish loading later are no change.
        const touch = (e) => { if (e.type !== 'click' || e.target.closest?.('.fi-fo-field')) this.touched = true; update(); };
        setTimeout(() => { this.baseline = this.read(); this.refresh(); }, 400);
        ['input', 'change', 'click', 'keyup'].forEach((e) => this.form.addEventListener(e, touch));
        new MutationObserver(update).observe(this.form, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['aria-checked', 'value'] });
        // After a successful save the saved values are the new baseline (other round trips, such as a wizard's Next or a
        // live field, keep the changes listed).
        window.Livewire?.hook?.('commit', ({ component, commit, succeed }) => {
            const saved = (commit?.calls ?? []).some((c) => ['save', 'create'].includes(c.method));
            if (saved && component?.el?.contains?.(this.$root)) succeed(() => setTimeout(() => { if (!this.form.querySelector('.fi-fo-field-wrp-error-message, [data-validation-error]')) { this.baseline = this.read(); this.touched = false; this.refresh(); } }, 300));
        });
    },
    secret(w) {
        const name = [...w.querySelectorAll('input,textarea,select')].map((i) => `${i.type} ${i.getAttribute('wire:model') ?? ''} ${i.id}`).join(' ');
        return /password|secret|token|api[_-]?key|client[_-]?secret/i.test(name);
    },
    text(el) {
        return (el?.textContent ?? '').replace(/\s+/g, ' ').trim();
    },
    // A choice's own name (a radio's or checkbox's label without its description; a toggle button's label).
    choice(input) {
        const own = input.closest('label') ?? (input.id ? this.form.querySelector(`label[for="${CSS.escape(input.id)}"]`) : null);
        const name = own?.querySelector('.fi-fo-radio-label-text > p:not(.fi-fo-radio-label-description), .fi-fo-checkbox-list-option-label') ?? own;
        return this.text(name) || input.value;
    },
    // The label a field shows ("Code"), else what it is named for screen readers.
    labelOf(w) {
        // Its own label, never that of a field inside a repeater or group it holds.
        const own = [...w.querySelectorAll('.fi-fo-field-label-content')].find((l) => l.closest('.fi-fo-field') === w);
        const shown = this.text(own).replace(/\s*\*$/, '');
        if (shown) return shown;
        // Its own control's name for screen readers (never one of the fields inside a repeater it holds).
        const control = [...w.querySelectorAll('input:not([type=hidden]),textarea,select,[contenteditable=true]')].find((c) => c.closest('.fi-fo-field') === w);
        const named = (control?.getAttribute('aria-label') ?? '').trim();
        if (named) return named;
        // A field whose label is hidden because its section names it (owner, 2026-11-26: the review shows the entire
        // entry): named by that section, else by what it asks for, else by its step.
        const section = w.closest('.fi-section, .fi-fieldset');
        const heading = section && this.form.contains(section)
            ? this.text(section.matches('.fi-fieldset') ? section.querySelector(':scope > legend') : section.querySelector('.fi-section-header-heading')).replace(/\s*\*$/, '')
            : '';
        return heading || (control?.getAttribute('placeholder') ?? '').trim() || this.stepLabel(w).replace(/\s\(\d+\)$/, '');
    },
    // A text field's value with what it shows before and after it (₹, %, days); never the field's own buttons.
    affixed(w, value) {
        if (!value) return value;
        const prefix = this.text(w.querySelector('.fi-input-wrp-prefix .fi-input-wrp-label'));
        const suffix = this.text(w.querySelector('.fi-input-wrp-suffix .fi-input-wrp-label'));
        return [prefix, value, suffix].filter(Boolean).join(' ');
    },
    // Repeaters and builders: one line per item, "Label: value · Label: value" (a table repeater names its columns).
    listValue(w) {
        const table = w.querySelector('.fi-fo-table-repeater table');
        if (table) {
            const heads = [...table.querySelectorAll(':scope > thead th')].map((th) => this.text(th).replace(/\*$/, ''));
            return [...table.querySelectorAll(':scope > tbody > tr')].map((tr, n) => {
                const cells = [...tr.children].map((td, i) => {
                    const field = td.querySelector('.fi-fo-field');
                    const value = field ? this.fieldValue(field) : '';
                    return value && heads[i] ? `${heads[i]}: ${value}` : value;
                }).filter(Boolean);
                return cells.length ? `${n + 1}. ${cells.join(' · ')}` : '';
            }).filter(Boolean).join('\n');
        }
        const items = [...w.querySelectorAll('.fi-fo-repeater-item, .fi-fo-simple-repeater-item, .fi-fo-builder-item')]
            .filter((item) => item.parentElement?.closest('.fi-fo-field') === w);
        return items.map((item, n) => {
            const title = this.text(item.querySelector(':scope > .fi-fo-repeater-item-header .fi-fo-repeater-item-header-label, :scope > .fi-fo-builder-item-header .fi-fo-builder-item-header-label'));
            const parts = [...item.querySelectorAll('.fi-fo-field')]
                .filter((f) => f.parentElement?.closest('.fi-fo-field') === w)
                .map((f) => {
                    const value = this.secret(f) ? '••••' : this.fieldValue(f);
                    const label = this.labelOf(f);
                    return value ? (label ? `${label}: ${value}` : value) : '';
                })
                .filter(Boolean);
            const line = [title, parts.join(' · ')].filter(Boolean).join(' — ');
            return line ? `${n + 1}. ${line}` : '';
        }).filter(Boolean).join('\n');
    },
    fieldValue(w) {
        if (w.querySelector('.fi-fo-repeater, .fi-fo-simple-repeater, .fi-fo-table-repeater, .fi-fo-builder')) return this.listValue(w);
        // Rich text and Markdown: the text as written.
        const rich = w.querySelector('.fi-fo-rich-editor [contenteditable]');
        if (rich) return (rich.innerText ?? rich.textContent ?? '').replace(/\n{3,}/g, '\n\n').trim();
        const markdown = w.querySelector('.fi-fo-markdown-editor .CodeMirror-code');
        if (markdown) return [...markdown.querySelectorAll('.CodeMirror-line')].map((l) => l.textContent).join('\n').trim();
        // Files: their names (the person picked them; the file itself is not read).
        const upload = w.querySelector('.fi-fo-file-upload');
        if (upload) {
            const files = [...upload.querySelectorAll('.filepond--file-info-main')].map((f) => this.text(f)).filter(Boolean);
            return files.length ? files.join(', ') : '';
        }
        // Tags: each tag.
        const tags = w.querySelector('.fi-fo-tags-input');
        if (tags) return [...tags.querySelectorAll('.fi-fo-tags-input-tags-ctn .fi-badge-label')].map((t) => this.text(t)).filter(Boolean).join(', ');
        // Key-value: "key: value" pairs.
        const pairs = w.querySelector('.fi-fo-key-value');
        if (pairs) {
            return [...pairs.querySelectorAll('tbody tr')].map((tr) => [...tr.querySelectorAll('input')].map((i) => i.value.trim()))
                .filter(([k, v]) => k || v).map(([k, v]) => `${k}: ${v ?? ''}`).join('\n');
        }
        // A grouped checkbox list (GroupedCheckboxList): one line per section, "Leave: Apply, Approve".
        const grouped = w.querySelector('[data-pos-grouped]');
        if (grouped) {
            const lines = new Map();
            grouped.querySelectorAll('input[type=checkbox]:checked').forEach((i) => {
                const group = i.dataset.posReviewGroup ?? '';
                lines.set(group, [...(lines.get(group) ?? []), this.choice(i)]);
            });
            return [...lines].map(([group, names]) => (group ? `${group}: ` : '') + names.filter(Boolean).join(', ')).join('\n');
        }
        // Toggle buttons, radios and checkbox lists: the chosen options by name.
        const options = w.querySelector('.fi-fo-toggle-buttons, .fi-fo-toggle-buttons-wrp, .fi-fo-radio, .fi-fo-checkbox-list');
        if (options) return [...options.querySelectorAll('input:checked')].map((i) => this.choice(i)).filter(Boolean).join(', ');
        // Date pickers: only the date shown to the person (the month and year controls are the calendar's own).
        const shown = w.querySelector('.fi-fo-date-time-picker-display-text-input');
        if (shown) return shown.value.trim();
        const sw = w.querySelector('[role=switch]');
        if (sw) return sw.getAttribute('aria-checked') === 'true' ? 'Yes' : 'No';
        const native = w.querySelector('select');
        if (native) return [...native.selectedOptions].map((o) => o.textContent.trim()).filter((t) => t && !/^select an option$/i.test(t)).join(', ');
        const custom = w.querySelector('.fi-select-input');
        if (custom) {
            // A placeholder ("Any", "Location timezone, then application default", …) is no value.
            if (custom.querySelector('.fi-select-input-placeholder')) return '';
            const badges = [...custom.querySelectorAll('.fi-select-input-value-badges-ctn .fi-badge-label')];
            if (badges.length) return badges.map((b) => this.text(b)).filter(Boolean).join(', ');
            const value = custom.querySelector('.fi-select-input-value-label, .fi-select-input-value-ctn, .fi-select-input-btn');
            return this.text(value).replace(/^Select an option$/i, '');
        }
        const color = w.querySelector('.fi-fo-color-picker input');
        if (color) return color.value.trim();
        const boxes = [...w.querySelectorAll('input[type=checkbox]')];
        if (boxes.length === 1) return boxes[0].checked ? 'Yes' : 'No';
        if (boxes.length > 1) return boxes.filter((b) => b.checked).map((b) => this.choice(b)).join(', ');
        const radio = w.querySelector('input[type=radio]:checked');
        if (radio) return this.choice(radio);
        return this.affixed(w, [...w.querySelectorAll('input:not([type=hidden]):not([type=file]),textarea')].map((i) => i.value.trim()).filter(Boolean).join(' – '));
    },
    // The wizard step a field sits in, named as in the step list.
    stepLabel(w) {
        const step = w.closest('.fi-sc-wizard-step');
        if (!step) return '';
        const steps = [...this.form.querySelectorAll(':scope > .fi-sc-wizard-step')];
        const labels = this.form.querySelectorAll(':scope > .fi-sc-wizard-header .fi-sc-wizard-header-step-label');
        return (labels[steps.indexOf(step)]?.textContent ?? '').trim();
    },
    // The titled section (or fieldset) a field sits in inside its step, when it is not the step itself ("Bank account").
    sectionLabel(w, group) {
        const section = w.closest('.fi-section, .fi-fieldset');
        if (!section || !this.form.contains(section) || (this.ownStep && section.contains(this.ownStep))) return '';
        const label = this.text(section.matches('.fi-fieldset')
            ? section.querySelector(':scope > legend')
            : section.querySelector('.fi-section-header-heading')).replace(/\s*\*$/, '');
        // The step's own section is named like the step ("More details" in the step "More details (2)").
        return label === group || label === group.replace(/\s\(\d+\)$/, '') ? '' : label;
    },
    read() {
        const out = new Map();
        // Fields, and (in the 'all' scope) the labelled details a step shows without asking (what applies today, a
        // worked-out figure): they are part of what the person went through.
        this.form.querySelectorAll(this.scope === 'all' ? '.fi-fo-field, .fi-in-entry' : '.fi-fo-field').forEach((w, i) => {
            if (w.closest('[x-cloak]') || w.parentElement?.closest('.fi-fo-field, .fi-in-entry')) return;
            // A record list inside a step (Edit employee: addresses, bank accounts …) saves its own entries; not this form's.
            if (w.closest('.pos-wizard-list')) return;
            // Fields hidden in the browser (client-side visibility) are not part of what is saved.
            if (w.closest('fieldset.fi-sc-visibility-fieldset[disabled]')) return;
            if (this.ownStep && w.closest('.fi-sc-wizard-step') === this.ownStep) return;
            // Another wizard's review inside this form (a drawer opened from it) is not this form's.
            if (w.closest('.fi-sc-wizard') && w.closest('.fi-sc-wizard') !== this.form && this.form.matches('.fi-sc-wizard')) return;
            const group = this.stepLabel(w);
            if (w.matches('.fi-in-entry')) {
                const label = this.text(w.querySelector('.fi-in-entry-label'));
                const value = this.text(w.querySelector('.fi-in-entry-content'));
                if (label && value && value !== '—') out.set(`entry-${i}:${label}`, { label, value, secret: false, entry: true, group, section: this.sectionLabel(w, group) });
                return;
            }
            const label = this.labelOf(w);
            if (!label) return;
            // Keyed by the field itself, so fields that appear or go as the form changes do not shift the others.
            const id = w.querySelector('label[for]')?.getAttribute('for') ?? w.querySelector('[id]')?.id ?? i;
            out.set(`${id}:${label}`, { label, value: String(this.fieldValue(w) ?? ''), secret: this.secret(w), group, section: this.sectionLabel(w, group) });
        });
        return out;
    },
    refresh() {
        if (!this.baseline) return;
        const now = this.read();
        if (!this.touched) this.baseline = now;
        const items = [];
        // Create: what is filled in (defaults included), once the person has started; every detail in the 'all' scope.
        const started = [...now].some(([key, f]) => !f.entry && f.value !== (this.baseline.get(key)?.value ?? ''));
        now.forEach((f, key) => {
            const before = this.baseline.get(key)?.value ?? '';
            // A detail the step works out itself is shown as it is now, never as a change the person made.
            const changed = this.mode !== 'create' && !f.entry && f.value !== before;
            const listed = this.scope === 'all' || (this.mode === 'create' ? started && f.value !== '' : changed);
            if (listed) {
                items.push({
                    key, label: f.label, group: f.group, section: f.section, changed,
                    before: f.secret ? (before ? '••••' : '') : before, after: f.secret ? (f.value ? '••••' : '') : f.value,
                });
            }
        });
        // Only a real change re-renders: the review sits inside the form it watches.
        const signature = JSON.stringify(items);
        if (signature !== this.signature) { this.signature = signature; this.items = items; }
        if (!this.changes) this.onlyChanges = false;
    },
    get changes() { return this.items.filter((i) => i.changed).length; },
    get blanks() { return this.items.filter((i) => !i.after).length; },
    // The items by wizard step, then by section inside the step, in form order.
    get groups() {
        const groups = [];
        this.items.filter((item) => !this.onlyChanges || item.changed).forEach((item) => {
            let group = groups[groups.length - 1];
            if (!group || group.label !== item.group) groups.push(group = { label: item.group, parts: [], items: [] });
            let part = group.parts[group.parts.length - 1];
            if (!part || part.label !== item.section) group.parts.push(part = { key: `${group.label}:${group.parts.length}`, label: item.section, items: [] });
            part.items.push(item);
            group.items.push(item);
        });
        return groups;
    },
    // The line under the banner's title: how much there is to check.
    get summary() {
        if (!this.items.length) return 'Nothing is saved until you confirm below, and then it is recorded in the audit trail.';
        const steps = new Set(this.items.map((i) => i.group)).size;
        const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
        const parts = [`${plural(this.items.length, 'detail', 'details')}${steps > 1 ? ` in ${plural(steps, 'step', 'steps')}` : ''}`];
        if (this.mode !== 'create') parts.push(this.changes ? `${plural(this.changes, 'change', 'changes')}` : 'no changes yet');
        if (this.scope === 'all' && this.blanks) parts.push(`${this.blanks} not filled in`);
        return `${parts.join(' · ')}. Nothing is saved until you confirm below, and then it is recorded in the audit trail.`;
    },
    submit() { this.form?.requestSubmit(); },
    // The wizard's own step button for a step (named as in the step list); Review's Edit presses it to go back there.
    stepButton(label) {
        const labels = [...(this.form?.querySelectorAll(':scope > .fi-sc-wizard-header .fi-sc-wizard-header-step-label') ?? [])];
        return labels.find((l) => l.textContent.trim() === label)?.closest('.fi-sc-wizard-header-step-btn') ?? null;
    },
    canEdit(label) { return !!this.stepButton(label); },
    editStep(label) {
        const button = this.stepButton(label);
        if (button && !button.disabled) button.click();
    },
});

/*
 * Browse (x-pos.browse): areas down the left, the open one's screens beside them; an accordion where the space is
 * narrow (one grid column). One area is open at a time; a remembered page reopens the area last opened this visit.
 * Up/Down, Home and End move between the area headings (Enter or Space opens one).
 */
components.posBrowse = (remember = null) => ({
    open: null,
    init() {
        const keys = [...this.$root.querySelectorAll(':scope > [data-browse-key]')].map((el) => el.dataset.browseKey);
        let saved = null;
        try { saved = remember ? sessionStorage.getItem(`pos-browse:${remember}`) : null; } catch (e) { saved = null; }
        this.open = keys.includes(saved) ? saved : (keys[0] ?? null);
    },
    // Side by side when the grid has two columns (a container query decides, not the window).
    wide() {
        return getComputedStyle(this.$root).gridTemplateColumns.trim().split(/\s+/).length > 1;
    },
    toggle(key) {
        this.open = this.open === key && !this.wide() ? null : key;
        if (remember && this.open) {
            try { sessionStorage.setItem(`pos-browse:${remember}`, this.open); } catch (e) { /* storage unavailable: nothing to remember */ }
        }
    },
    move(e) {
        if (!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(e.key) || e.metaKey || e.ctrlKey || e.altKey) return;
        const head = e.target instanceof Element ? e.target.closest('.pos-browse-head') : null;
        if (!head) return;
        const heads = [...this.$root.querySelectorAll(':scope > .pos-browse-head')];
        const i = heads.indexOf(head);
        const next = e.key === 'Home' ? heads[0] : e.key === 'End' ? heads[heads.length - 1] : heads[(i + (e.key === 'ArrowDown' ? 1 : -1) + heads.length) % heads.length];
        e.preventDefault();
        next?.focus();
    },
});

/*
 * Employee 360 views (UX.15 closure P1-04): Now is the default; Profile, Journey, Work, Time, Growth, Rewards, Documents
 * and Activity are one step away. The URL hash names the view, so #journey links keep working; the html[data-pos360-view]
 * attribute lets what Filament renders follow the same view. Owner, 2026-11-27: each view is followed by its own lists
 * ([data-pos360-of]); a link to one list (#list-<name>) opens its view and scrolls to it. The old Records view is gone:
 * #records opens Profile, where every detail now is.
 */
const pos360 = {
    views: ['now', 'profile', 'journey', 'work', 'time', 'growth', 'rewards', 'documents', 'activity'],
    aliases: { records: 'profile' },
    view: 'now',
    set(view, scroll = false) {
        view = this.aliases[view] ?? view;
        this.view = this.views.includes(view) ? view : 'now';
        document.documentElement.dataset.pos360View = this.view;
        if (scroll) {
            const nav = document.querySelector('.pos-360-nav');
            if (nav && nav.getBoundingClientRect().top < 0) nav.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        }
    },
    fromHash(scroll = false) {
        const id = decodeURIComponent(location.hash.replace('#', ''));
        const target = id && !this.views.includes(id) ? document.getElementById(id) : null;
        const of = target?.closest('[data-pos360-of]')?.dataset.pos360Of;
        if (!of) return this.set(id, scroll);
        // A list: open the view it belongs to, then bring the list into sight once the view shows.
        this.set(of, false);
        requestAnimationFrame(() => requestAnimationFrame(() => target.scrollIntoView({ block: 'start', behavior: 'auto' })));
    },
};

/*
 * A checkbox list in groups (App\Filament\Support\Forms\GroupedCheckboxList): areas open and close, a live count per
 * area and a summary of the areas with something ticked, one search across every option, and "Tick all / Clear" for
 * the whole list, an area or a section. Ticking goes through each checkbox's own wire:model (a change event), exactly
 * as Filament's own "Select all" does, so the field's state, rules and saving are the checkbox list's.
 */
components.posGroupedChecks = (config = {}) => ({
    search: '',
    tick: 0,
    open: {},
    groups: config.groups ?? [],
    init() {
        this.groups.forEach((g) => { this.open[g.key] = !config.collapsed; });
        this.$root.addEventListener('change', () => this.tick++);
        // wire:model ticks the saved boxes as each one starts, after this component: count once they have (editing a role).
        this.$nextTick(() => this.tick++);
        setTimeout(() => this.tick++, 250);
        // A round trip (a wizard step, a validation message) can redraw the boxes: count again once it lands.
        window.Livewire?.hook?.('commit', ({ component, succeed }) => {
            if (component?.el?.contains?.(this.$root)) succeed(() => this.$nextTick(() => this.tick++));
        });
    },
    query() { return this.search.trim().toLowerCase(); },
    matches(option) { const q = this.query(); return !q || (option.dataset.search ?? '').includes(q); },
    anyMatch(el) { return !this.query() || [...el.querySelectorAll('[data-pos-gcl-option]')].some((o) => this.matches(o)); },
    noMatches() { return !!this.query() && !this.anyMatch(this.$root); },
    groupEl(key) { return this.$root.querySelector(`[data-pos-gcl-group="${CSS.escape(key)}"]`); },
    count(key) { this.tick; return this.groupEl(key)?.querySelectorAll('input[type=checkbox]:checked').length ?? 0; },
    size(key) { return this.groupEl(key)?.querySelectorAll('input[type=checkbox]').length ?? 0; },
    state(key) { const n = this.count(key); return n === 0 ? 'none' : (n === this.size(key) ? 'all' : 'some'); },
    countText(key) {
        const n = this.count(key), of = this.size(key), word = config.word ?? 'ticked';
        if (n === 0) return `None ${word}`;
        return n === of ? `All ${of} ${word}` : `${n} of ${of} ${word}`;
    },
    get total() { this.tick; return this.$root.querySelectorAll('[data-pos-gcl-option] input[type=checkbox]:checked').length; },
    get tickedGroups() { return this.groups.map((g) => ({ ...g, count: this.count(g.key) })).filter((g) => g.count > 0); },
    isOpen(key) { return !!this.query() || !!this.open[key]; },
    toggle(key) { this.open[key] = !this.open[key]; },
    openAll(value) { this.groups.forEach((g) => { this.open[g.key] = value; }); },
    reveal(key) {
        this.search = '';
        this.open[key] = true;
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.$nextTick(() => this.groupEl(key)?.scrollIntoView({ block: 'start', behavior: reduce ? 'auto' : 'smooth' }));
    },
    // Tick or clear every box in a scope that the search shows (a hidden match stays as it is).
    tickIn(scope, value) {
        scope?.querySelectorAll('[data-pos-gcl-option]').forEach((option) => {
            const box = option.querySelector('input[type=checkbox]');
            if (!box || box.disabled || box.checked === value || !this.matches(option)) return;
            box.checked = value;
            box.dispatchEvent(new Event('change', { bubbles: true }));
        });
        this.tick++;
    },
});

Object.assign(window, components);
const registerAll = () => {
    Object.entries(components).forEach(([name, factory]) => window.Alpine?.data(name, factory));
    if (window.Alpine && !window.Alpine.store('pos360')) window.Alpine.store('pos360', pos360);
};
if (window.Alpine) registerAll();
document.addEventListener('alpine:init', registerAll);

/* UX.19: a tablist moves between its tabs with the arrow keys, Home and End, as its role announces (WAI-ARIA tabs,
   manual activation: Enter or Space still selects, and every tab stays a Tab stop). My HR's sections, the assistant
   picker, journey stages and the palette's scope chips. */
document.addEventListener('keydown', (e) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key) || e.metaKey || e.ctrlKey || e.altKey) return;
    const tab = e.target instanceof Element ? e.target.closest('[role=tab]') : null;
    const list = tab?.closest('[role=tablist]');
    if (!list) return;
    const tabs = [...list.querySelectorAll('[role=tab]')].filter((t) => !t.disabled && t.getClientRects().length > 0);
    const i = tabs.indexOf(tab);
    if (i < 0) return;
    const next = e.key === 'Home' ? tabs[0] : e.key === 'End' ? tabs[tabs.length - 1] : tabs[(i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
    e.preventDefault();
    next.focus();
});

/* Global shortcuts that are not the command center: G then H/W/P/O/A. */
(() => {
    let g = false;
    let timer = null;
    window.addEventListener('keydown', (e) => {
        if (isTyping(e) || e.metaKey || e.ctrlKey || e.altKey || document.documentElement.classList.contains('pos-command-open')) return;
        if (e.key === '?') { e.preventDefault(); window.dispatchEvent(new CustomEvent('pos-shortcuts')); return; }
        if (e.key.toLowerCase() === 'g') { g = true; clearTimeout(timer); timer = setTimeout(() => (g = false), 900); return; }
        if (!g) return;
        g = false;
        const map = window.PeopleOS?.goto ?? {};
        const url = map[e.key.toLowerCase()];
        if (url) { e.preventDefault(); go(url); }
    });
})();

/*
 * Rail sections: a section of several areas is a button (sidebar/item.blade.php). Clicking it opens its areas under it
 * and folds the section that was open, so one section is open at a time; clicking it again folds it. It never opens a
 * page: each area does. The current section's areas start open. On a folded desktop rail a click first opens the rail
 * over the page (the hover peek of posRail), so the areas can be seen. Livewire replaces the rail on navigation, so
 * the listener sits on the document.
 */
(() => {
    const set = (button, open) => {
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.getElementById(button.getAttribute('aria-controls'))?.toggleAttribute('data-pos-rail-closed', !open);
        // Marks a section opened by a click (its areas fade in); the current section's are simply there.
        button.parentElement.toggleAttribute('data-pos-rail-open', open);
    };
    document.addEventListener('click', (e) => {
        const button = e.target.closest?.('.fi-sidebar [data-pos-rail-toggle]');
        if (!button) return;
        e.preventDefault();
        const open = button.getAttribute('aria-expanded') !== 'true';
        document.querySelectorAll('.fi-sidebar [data-pos-rail-toggle][aria-expanded="true"]').forEach((other) => { if (other !== button) set(other, false); });
        set(button, open);
        if (!open) return;
        const sidebar = window.Alpine?.store('sidebar');
        if (sidebar && !sidebar.isOpen && window.innerWidth >= 1024) {
            document.documentElement.setAttribute('data-pos-rail-peek', '');
            sidebar.isOpen = true;
        }
        // Areas that open below the rail's edge scroll into sight.
        const li = button.parentElement;
        const nav = li.closest('.fi-sidebar-nav');
        if (!nav) return;
        requestAnimationFrame(() => {
            const edge = nav.getBoundingClientRect();
            const box = li.getBoundingClientRect();
            const by = Math.min(box.bottom + 12 - edge.bottom, box.top - edge.top);
            if (by > 0) nav.scrollBy({ top: by, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        });
    });
})();

/* Livewire's navigation progress bar (NProgress) ships role="bar", which is not an ARIA role: expose it as a named
   progress bar. Navigation swaps <body>, so the body observer is re-attached whenever that happens. */
(() => {
    const fix = (root) => {
        root.querySelectorAll('#nprogress [role="bar"]').forEach((el) => { el.setAttribute('role', 'progressbar'); el.setAttribute('aria-label', 'Loading page'); });
        root.querySelectorAll('#nprogress [role="spinner"]').forEach((el) => el.removeAttribute('role'));
    };
    let bodyObserver = null;
    const watchBody = () => {
        bodyObserver?.disconnect();
        if (!document.body) return;
        bodyObserver = new MutationObserver((records) => records.forEach((r) => r.addedNodes.forEach((n) => { if (n.id === 'nprogress') fix(n); })));
        bodyObserver.observe(document.body, { childList: true });
        fix(document.body);
    };
    new MutationObserver(watchBody).observe(document.documentElement, { childList: true });
    if (document.body) watchBody(); else document.addEventListener('DOMContentLoaded', watchBody);
})();

/*
 * Pinned page chrome (CSS sticks the area bar and the page header under the top bar). This measures them: the area
 * bar's height places the header below it (--pos-area-h); once content runs beneath the header it folds to one row
 * (html[data-pos-header-stuck]), and --pos-header-fold keeps its place in the page the same height, so nothing below
 * moves as it folds. --pos-pinned-h, the whole panel, keeps focused fields clear of it (scroll-padding). Tables pin
 * their column headings just below the panel: one that fits its width drops Filament's sideways scroller, whose box
 * would otherwise hold its headings (data-pos-fit, CSS); a wider one keeps the scroller, and its heading row is moved
 * down as the page scrolls. Tables in modals keep Filament's own behaviour. html[data-pos-scrolled] and
 * html[data-pos-far] drive the panel's edge and the back-to-top button. A Livewire update rewrites the tables'
 * attributes, so the marks are put back after every one.
 *
 * List pages go further: when the page holds one table and, on a screen 768 px or wider, its rows can have at least
 * LIST_MIN of the screen, only the rows scroll (html[data-pos-list], CSS). The page is laid out to fit the screen and
 * --pos-list-h is the room left for the rows; otherwise (forms with a table below, phones) the page scrolls as above.
 */
(() => {
    const root = document.documentElement;
    const watched = new WeakSet();
    let header = null;
    let area = 0;
    let compact = 0;
    let wide = [];
    let list = false;
    let measuring = 0;
    const LIST_MIN = 220;
    const follow = () => {
        const y = window.scrollY;
        const line = (document.querySelector('.fi-topbar-ctn')?.getBoundingClientRect().bottom ?? 0) + area;
        const live = !!header?.isConnected;
        root.toggleAttribute('data-pos-scrolled', y > 2);
        root.toggleAttribute('data-pos-far', y > Math.max(800, window.innerHeight));
        root.toggleAttribute('data-pos-header-stuck', !list && live && y > 0 && header.getBoundingClientRect().top <= line + 1);
        const top = line + (live ? compact : 0);
        wide.forEach(({ box, head, height }) => {
            const r = box.getBoundingClientRect();
            const shift = Math.max(0, Math.min(top - r.top, r.height - height));
            head.style.transform = shift > 0 ? `translateY(${shift}px)` : '';
        });
    };
    const sizes = new ResizeObserver(() => schedule());
    const watch = (el) => { if (el && !watched.has(el)) { watched.add(el); sizes.observe(el); } };
    const measure = () => {
        measuring = 0;
        const bar = document.querySelector('.fi-topbar-ctn');
        const areaBar = document.querySelector('.pos-spacebar-wrap');
        header = document.querySelector('.fi-page-header-main-ctn > .fi-header');
        // A header with nothing left to show (a module's own page: the area bar names it) is not drawn and pins nothing.
        if (header && !header.getClientRects().length) header = null;
        watch(areaBar);
        watch(header);
        area = areaBar?.offsetHeight ?? 0;
        root.style.setProperty('--pos-area-h', `${area}px`);
        wide = [];
        const boxes = [...document.querySelectorAll('.fi-page .fi-ta-content-ctn')].filter((box) => !box.closest('.fi-modal'));
        list = false;
        if (boxes.length === 1 && window.innerWidth >= 768) {
            // Measured in the list layout: the room is the screen less what sits above and below the rows.
            const box = boxes[0];
            const main = document.querySelector('.fi-main');
            root.setAttribute('data-pos-list', '');
            root.removeAttribute('data-pos-header-stuck');
            const r = box.getBoundingClientRect();
            const below = (box.closest('.fi-page')?.getBoundingClientRect().bottom ?? r.bottom) - r.bottom + (main ? parseFloat(getComputedStyle(main).paddingBottom) || 0 : 0);
            const room = Math.floor(window.innerHeight - (r.top + window.scrollY) - below);
            list = room >= LIST_MIN;
            if (list) {
                root.style.setProperty('--pos-list-h', `${room}px`);
                box.removeAttribute('data-pos-fit');
                box.querySelector('.fi-ta-table > thead')?.style.removeProperty('transform');
                watch(box);
                watch(box.closest('.fi-ta-ctn'));
            }
        }
        root.toggleAttribute('data-pos-list', list);
        let full = 0;
        compact = 0;
        if (header && !list) {
            // Both heights, read with the fold off and on; restored before the frame is drawn.
            const folded = root.hasAttribute('data-pos-header-stuck');
            root.removeAttribute('data-pos-header-stuck');
            full = header.offsetHeight;
            root.setAttribute('data-pos-header-stuck', '');
            compact = header.offsetHeight;
            root.toggleAttribute('data-pos-header-stuck', folded);
        }
        root.style.setProperty('--pos-header-fold', `${full - compact}px`);
        root.style.setProperty('--pos-header-h', `${compact}px`);
        root.style.setProperty('--pos-pinned-h', `${(bar?.offsetHeight ?? 0) + area + (list ? header?.offsetHeight ?? 0 : compact)}px`);
        (list ? [] : boxes).forEach((box) => {
            const table = box.querySelector('.fi-ta-table');
            const head = table?.querySelector(':scope > thead');
            watch(box);
            watch(table);
            const isWide = !!table && table.offsetWidth > box.clientWidth + 1;
            box.toggleAttribute('data-pos-fit', !isWide);
            if (isWide && head) wide.push({ box, head, height: head.offsetHeight });
        });
        follow();
    };
    const schedule = () => { if (!measuring) measuring = requestAnimationFrame(measure); };
    // In the scroll event itself (not a later frame), so a moved heading row keeps pace with the page.
    window.addEventListener('scroll', follow, { passive: true });
    window.addEventListener('resize', schedule);
    // Straight away after a page swap, so the new page is drawn in its own layout.
    document.addEventListener('livewire:navigated', measure);
    const hook = () => window.Livewire.hook('morphed', schedule);
    if (window.Livewire) hook(); else document.addEventListener('livewire:init', hook, { once: true });
    // At once when the page is parsed (Filament keeps it hidden until Alpine starts), and again once web fonts settle.
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', measure); else measure();
    document.fonts?.ready.then(schedule);
    // Back to top, then on to the start of the page content for keyboard users.
    window.posToTop = () => {
        window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        document.getElementById('pos-main')?.focus({ preventScroll: true });
    };
})();

/*
 * Attendance calendar: with the totals beside the month and at least CAL_MIN of room below the employee bar, the month
 * and its totals fit that room (html[data-pos-cal-fit], --pos-cal-h; theme.css section K), so the whole month shows
 * without scrolling. A day too short for its notes drops them (no-body); its shift is in the top row and always shows.
 * Otherwise the page scrolls.
 */
(() => {
    const root = document.documentElement;
    const CAL_MIN = 440;
    let pending = 0;
    const measure = () => {
        pending = 0;
        const main = document.querySelector('.pos-cal-main');
        const beside = !!main && getComputedStyle(main).gridTemplateColumns.trim().split(/\s+/).length > 1;
        // The page's own bottom padding, at least one gap, stays below the month.
        const page = document.querySelector('.fi-main');
        const below = Math.max(page ? parseFloat(getComputedStyle(page).paddingBottom) || 0 : 0, parseFloat(getComputedStyle(root).getPropertyValue('--pos-gap')) || 16);
        const room = beside ? Math.floor(window.innerHeight - (main.getBoundingClientRect().top + window.scrollY) - below) : 0;
        if (room < CAL_MIN) {
            root.removeAttribute('data-pos-cal-fit');
            return;
        }
        root.style.setProperty('--pos-cal-h', `${room}px`);
        root.setAttribute('data-pos-cal-fit', '');
        const day = main.querySelector('.pos-cal-day')?.offsetHeight ?? 0;
        root.setAttribute('data-pos-cal-fit', day < 56 ? 'no-body' : '');
    };
    const schedule = () => { if (!pending) pending = requestAnimationFrame(measure); };
    window.addEventListener('resize', schedule);
    document.addEventListener('livewire:navigated', measure);
    const hook = () => window.Livewire.hook('morphed', schedule);
    if (window.Livewire) hook(); else document.addEventListener('livewire:init', hook, { once: true });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', measure); else measure();
    document.fonts?.ready.then(schedule);
})();

/*
 * The Employee 360's "Upload or open documents" and "Government IDs" (owner, 2026-11-27) open the Edit page with that tab
 * chosen (?relation=…#tabs-<RelationManager>): the record tabs sit under the form, so the page scrolls down to that list
 * (its lazy placeholder carries the class in its wire:key), below the pinned bar; again once the list has loaded.
 */
(() => {
    const toTabs = () => {
        if (!location.hash.startsWith('#tabs')) return;
        const name = location.hash.slice('#tabs-'.length).replace(/[^A-Za-z0-9]/g, '');
        const target = (name && document.querySelector(`[wire\\:key*="${name}-"]`)) || document.querySelector('[wire\\:key*="relationManagerTabs"]');
        if (!target) return;
        const top = target.getBoundingClientRect().top + window.scrollY - (parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--pos-sticky-top')) || 0) - 16;
        window.scrollTo({ top: Math.max(0, top), behavior: 'auto' });
    };
    // Lists above it load lazily and push it down: scroll again as they arrive.
    const later = () => [350, 1200, 2500].forEach((ms) => setTimeout(toTabs, ms));
    document.addEventListener('livewire:navigated', later);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', later); else later();
})();

/*
 * UX.18: Back closes the topmost overlay before it leaves the page (Android Back, the browser's Back button, a mouse's
 * back button). Each open overlay (a Filament modal, the phone menu, the command center, a drawer or sheet, the
 * assistant) adds one same-URL history entry; Back pops it and closes that overlay, and only then navigates. Closing an
 * overlay any other way (Esc, Cancel, a click outside, finishing an action) takes its entry back out. A link followed
 * from inside an overlay first removes the overlay entries, then navigates, so history never keeps stale steps.
 * Overlays are followed from the DOM (their open classes), so components only need a close event. This listener runs
 * in the capture phase and handles its own entries, so Livewire's navigation never sees them.
 */
const overlayBack = {
    stack: [],
    swallow: 0,
    resume: null,
    rerouting: false,
    watched: [
        // [id, is it open?, how to close it]
        ['command', () => document.documentElement.classList.contains('pos-command-open'), () => window.dispatchEvent(new CustomEvent('pos-command-close'))],
        ['drawer', () => document.documentElement.classList.contains('pos-drawer-is-open'), () => window.dispatchEvent(new CustomEvent('pos-drawer-close'))],
        ['assistant', () => document.documentElement.classList.contains('pos-ai-open'), () => window.dispatchEvent(new CustomEvent('pos-ai-close'))],
        ['menu', () => window.matchMedia('(max-width: 1023px)').matches && !!document.querySelector('.fi-sidebar.fi-sidebar-open'), () => window.Alpine?.store('sidebar')?.close()],
    ],
    init() {
        window.addEventListener('popstate', (e) => this.onPop(e), { capture: true });
        document.addEventListener('alpine:navigate', (e) => this.onNavigate(e));
        document.addEventListener('alpine:navigated', () => { if (!this.resume) this.stack = []; });
        new MutationObserver((records) => this.onMutations(records)).observe(document.documentElement, { attributes: true, attributeFilter: ['class'], subtree: true });
    },
    onMutations(records) {
        let shell = false;
        for (const r of records) {
            const el = r.target;
            if (el === document.documentElement || el.classList?.contains('fi-sidebar')) shell = true;
            if (el.classList?.contains('fi-modal') && el.id) {
                this.sync(`modal:${el.id}`, el.classList.contains('fi-modal-open'), () => window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: el.id } })));
            }
        }
        if (shell) this.watched.forEach(([id, isOpen, close]) => this.sync(id, isOpen(), close));
    },
    sync(id, open, close) {
        const i = this.stack.findIndex((e) => e.id === id);
        if (open && i === -1) {
            this.stack.push({ id, close });
            history.pushState({ ...(history.state ?? {}), posOverlay: this.stack.length }, '', location.href);
        } else if (!open && i !== -1) {
            // Closed in the page: take its entry back out (the resulting popstate is ours).
            this.stack.splice(i, 1);
            if (history.state?.posOverlay) { this.swallow++; history.back(); }
        }
    },
    onPop(e) {
        if (this.swallow > 0) {
            this.swallow--;
            e.stopImmediatePropagation();
            if (this.swallow === 0 && this.resume) {
                const url = this.resume;
                this.resume = null;
                this.rerouting = true;
                try { go(url); } finally { this.rerouting = false; }
            }
            return;
        }
        if (this.stack.length > 0) {
            e.stopImmediatePropagation();
            this.stack.pop().close();
            return;
        }
        // A leftover overlay step (after a reload, or going Forward): nothing is open, so stay put.
        if (e.state?.posOverlay) e.stopImmediatePropagation();
    },
    onNavigate(e) {
        if (this.rerouting || e.detail?.history || this.stack.length === 0) return;
        e.preventDefault();
        this.resume = String(e.detail?.url ?? location.href);
        const steps = this.stack.length;
        this.stack = [];
        this.swallow = 1;
        history.go(-steps);
    },
};
overlayBack.init();
window.PeopleOS = Object.assign(window.PeopleOS || {}, { overlayBack });
