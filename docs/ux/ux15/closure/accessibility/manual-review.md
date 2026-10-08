# UX.15 closure: manual accessibility review (C.13)

Chromium 1440×1000, HR admin, reduced motion on. Script: `keyboard-review.mjs`. Showcase data only.

| Area | Result |
|---|---|
| Keyboard navigation | One skip link first (Filament 5.8's duplicate is now hidden), then the top bar, the area navigation and the module list. Every stop shows a focus ring |
| Drawers | Edit opened from the keyboard (Enter on the row action) opens the side drawer with focus inside it, on the first field. Escape closes it and focus returns to the Edit action |
| Forms | The drawer form shows the review after a change. Screen readers hear a short status ("1 change to review") instead of the whole list on every keystroke (live region narrowed during this review). The date picker's labelled input is the only focus stop (accessible trigger) |
| Tables | Row actions have full contrast. Lens chips are toggle buttons (`aria-pressed`) in a labelled group. People chips are links with a peek on focus |
| Command palette | Ctrl+K opens it with focus in the combobox input. The input shows the caret inside the modal palette rather than an outline (unchanged from UX.15) |
| Reduced motion | 0 running animations with `prefers-reduced-motion: reduce` |
| Screen-reader semantics | axe: 0 violations in 78 checks (light and dark), including ARIA roles (the Livewire progress bar is now a named progressbar), nested interactive controls and target size |
| Contrast | axe colour contrast is clean in both themes, including placeholders, row actions, dark filled buttons and earlier custom pages |
