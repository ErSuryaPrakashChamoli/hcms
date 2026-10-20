// Helpers for the time-and-leave capture script (read-only use of the showcase: forms are opened, filled and cancelled).
import { settle } from './lib.mjs';

export const modal = (page) => page.locator('.fi-modal-window:visible').last();

/** Set a field of the currently mounted action form through Livewire (for non-native date pickers). */
export async function setData(page, key, value) {
    await page.evaluate(([k, v]) => {
        const c = window.Livewire.all().find((c) => Array.isArray(c.$wire.mountedActions) && c.$wire.mountedActions.length > 0);
        if (!c) throw new Error('no mounted action');
        return c.$wire.set(`mountedActions.0.data.${k}`, v);
    }, [key, value]);
    await settle(page, 900);
}

/** Close the open modal without submitting (the modal's own Cancel / close button). */
export async function cancelModal(page) {
    const m = modal(page);
    const btn = m.getByRole('button', { name: 'Cancel', exact: true }).last();
    if (await btn.count()) { await btn.click(); } else { await page.keyboard.press('Escape'); }
    await page.waitForTimeout(800);
}
