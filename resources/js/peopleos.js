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
            this.$wire.opened(mode);
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

Object.assign(window, components);
const registerAll = () => Object.entries(components).forEach(([name, factory]) => window.Alpine?.data(name, factory));
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
