/*
 * PeopleOS interaction layer (Alpine, no framework). Keyboard-first, reduced-motion aware.
 * Every action here only opens screens or calls Livewire methods; the server decides access.
 */

const isTyping = (e) => {
    const t = e.target;
    return t && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName));
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
            this.$wire.opened(mode).then(() => { if (detail?.pick) this.$wire.pick(detail.pick); });
            this.$nextTick(() => { this.$refs.input?.focus(); this.$refs.input?.select(); this.ensureActive(); });
        },
        close() {
            this.isOpen = false;
            this.actionIndex = -1;
            this.$nextTick(() => this.lastFocus?.focus?.());
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
            this.$wire.setMode(next);
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
                this.$wire.pick(url.slice(6)).then(() => this.$nextTick(() => { this.$refs.input?.focus(); this.ensureActive(); }));
                return;
            }
            this.$wire.remember(el.dataset.id);
            if ((preview || !url) && drawer) {
                this.close();
                window.dispatchEvent(new CustomEvent('pos-drawer-open', { detail: drawer }));
                return;
            }
            if (url) { this.close(); go(url); }
        },
        runActionData(action, id = null) {
            const el = document.getElementById(this.activeId);
            this.$wire.remember(id ?? el?.dataset.id ?? '');
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
        hide() { this.open = false; this.$wire.close(); this.$nextTick(() => this.lastFocus?.focus?.()); },
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
                wire.peek(id).then((d) => { this.cache[id] = d; if (this.trigger === t) { this.data = d; this.loading = false; } })
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
 */
components.posFormReview = (mode = 'edit') => ({
    mode,
    items: [],
    baseline: null,
    form: null,
    init() {
        // A drawer form contains its review; a record page's review sits after the page form.
        this.form = this.$root.closest('form') ?? this.$root.closest('.fi-page')?.querySelector('form');
        if (!this.form) return;
        const update = () => { clearTimeout(this.t); this.t = setTimeout(() => this.refresh(), 120); };
        // Snapshot once Livewire and the field components have rendered their values.
        setTimeout(() => { this.baseline = this.read(); this.refresh(); }, 400);
        ['input', 'change', 'click', 'keyup'].forEach((e) => this.form.addEventListener(e, update));
        new MutationObserver(update).observe(this.form, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['aria-checked', 'value'] });
        // After a successful save the saved values are the new baseline.
        window.Livewire?.hook?.('commit', ({ component, succeed }) => {
            if (component?.el?.contains?.(this.$root)) succeed(() => setTimeout(() => { if (!this.form.querySelector('.fi-fo-field-wrp-error-message, [data-validation-error]')) { this.baseline = this.read(); this.refresh(); } }, 300));
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
    read() {
        const out = new Map();
        this.form.querySelectorAll('.fi-fo-field').forEach((w, i) => {
            if (w.closest('[x-cloak]') || w.parentElement?.closest('.fi-fo-field')) return;
            const label = (w.querySelector('.fi-fo-field-label-content')?.textContent ?? '').replace(/\s+/g, ' ').trim().replace(/\s*\*$/, '');
            if (!label) return;
            out.set(`${i}:${label}`, { label, value: String(this.fieldValue(w) ?? ""), secret: this.secret(w) });
        });
        return out;
    },
    refresh() {
        if (!this.baseline) return;
        const now = this.read();
        const items = [];
        now.forEach((f, key) => {
            const before = this.baseline.get(key)?.value ?? '';
            if (this.mode === 'create' ? f.value !== '' : f.value !== before) {
                items.push({ label: f.label, before: f.secret ? (before ? '••••' : '') : before, after: f.secret ? '••••' : f.value });
            }
        });
        this.items = items;
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
