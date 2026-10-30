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
 * forms. It reads only what is already in this form (labels and the values the person sees), lists each changed
 * field as Before → After (create: what has been filled in), and masks anything that looks like a secret. It sends
 * nothing anywhere and decides nothing: saving is still the page's own action.
 * In a form followed as a wizard (FormWizard) it is the last step: it reads every step (each step is its own form),
 * groups the fields by step and leaves out its own step (the reason for change).
 */
components.posFormReview = (mode = 'edit') => ({
    mode,
    items: [],
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
    fieldValue(w) {
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
            const shown = custom.querySelector('.fi-select-input-value-label, .fi-select-input-value-ctn, .fi-select-input-btn');
            return (shown?.textContent ?? '').replace(/\s+/g, ' ').trim().replace(/^Select an option$/i, '');
        }
        const boxes = [...w.querySelectorAll('input[type=checkbox]')];
        if (boxes.length === 1) return boxes[0].checked ? 'Yes' : 'No';
        if (boxes.length > 1) return boxes.filter((b) => b.checked).map((b) => b.closest('label')?.textContent.trim() ?? b.value).join(', ');
        const radio = w.querySelector('input[type=radio]:checked');
        if (radio) return radio.closest('label')?.textContent.trim() ?? radio.value;
        return [...w.querySelectorAll('input:not([type=hidden]):not([type=file]),textarea')].map((i) => i.value.trim()).filter(Boolean).join(' – ');
    },
    // The wizard step a field sits in, named as in the step list.
    stepLabel(w) {
        const step = w.closest('.fi-sc-wizard-step');
        if (!step) return '';
        const steps = [...this.form.querySelectorAll(':scope > .fi-sc-wizard-step')];
        const labels = this.form.querySelectorAll(':scope > .fi-sc-wizard-header .fi-sc-wizard-header-step-label');
        return (labels[steps.indexOf(step)]?.textContent ?? '').trim();
    },
    read() {
        const out = new Map();
        this.form.querySelectorAll('.fi-fo-field').forEach((w, i) => {
            if (w.closest('[x-cloak]') || w.parentElement?.closest('.fi-fo-field')) return;
            if (this.ownStep && w.closest('.fi-sc-wizard-step') === this.ownStep) return;
            const label = (w.querySelector('.fi-fo-field-label-content')?.textContent ?? '').replace(/\s+/g, ' ').trim().replace(/\s*\*$/, '');
            if (!label) return;
            out.set(`${i}:${label}`, { label, value: String(this.fieldValue(w) ?? ""), secret: this.secret(w), group: this.stepLabel(w) });
        });
        return out;
    },
    refresh() {
        if (!this.baseline) return;
        const now = this.read();
        if (!this.touched) this.baseline = now;
        const items = [];
        // Create: everything filled in (defaults included) is reviewed, but only once the person has started.
        const started = [...now].some(([key, f]) => f.value !== (this.baseline.get(key)?.value ?? ''));
        now.forEach((f, key) => {
            const before = this.baseline.get(key)?.value ?? '';
            if (this.mode === 'create' ? started && f.value !== '' : f.value !== before) {
                items.push({ key, label: f.label, group: f.group, before: f.secret ? (before ? '••••' : '') : before, after: f.secret ? '••••' : f.value });
            }
        });
        // Only a real change re-renders: the review sits inside the form it watches.
        const signature = JSON.stringify(items);
        if (signature !== this.signature) { this.signature = signature; this.items = items; }
    },
    // The items by wizard step, in form order.
    get groups() {
        const groups = [];
        this.items.forEach((item) => {
            const last = groups[groups.length - 1];
            if (last && last.label === item.group) last.items.push(item);
            else groups.push({ label: item.group, items: [item] });
        });
        return groups;
    },
    submit() { this.form?.requestSubmit(); },
});

/*
 * Employee 360 views (UX.15 closure P1-04): Now is the default; Journey, Work, Growth, Rewards, Documents and
 * Records are one step away. The URL hash names the view, so #journey and #records links keep working; the
 * html[data-pos360-view] attribute lets the deep record sections (rendered by Filament) follow the same view.
 */
const pos360 = {
    views: ['now', 'journey', 'work', 'growth', 'rewards', 'documents', 'records'],
    view: 'now',
    set(view, scroll = false) {
        this.view = this.views.includes(view) ? view : 'now';
        document.documentElement.dataset.pos360View = this.view;
        if (scroll) {
            const nav = document.querySelector('.pos-360-nav');
            if (nav && nav.getBoundingClientRect().top < 0) nav.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        }
    },
    fromHash(scroll = false) { this.set(location.hash.replace('#', ''), scroll); },
};

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
