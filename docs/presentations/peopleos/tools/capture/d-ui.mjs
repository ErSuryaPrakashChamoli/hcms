// Small UI helpers for fork-D capture scripts (Filament searchable selects, create pages, tolerant steps).
import { idle, go, setForm } from './lib.mjs';

/** Pick `text` in the index-th searchable select ("Select an option" button) inside `root`. */
export async function chooseSearchable(page, root, index, text, search = text) {
    const btn = root.locator('button', { hasText: 'Select an option' }).nth(index);
    await btn.click();
    await idle(page, 500);
    await page.keyboard.type(search.slice(0, 12), { delay: 30 });
    await idle(page, 1200);
    await page.locator('[role=option]:visible, .fi-select-input-option:visible').filter({ hasText: text }).first().click();
    await idle(page, 800);
}

/** Pick an option of a searchable select identified by its field id (e.g. form.company_id). */
export async function chooseById(page, id, text, search = text) {
    const wrapper = page.locator(`[id="${id}"]`).locator('xpath=ancestor::*[contains(@class,"fi-fo-field")][1]');
    const btn = wrapper.locator('button').first();
    await btn.click();
    await idle(page, 500);
    await page.keyboard.type(search.slice(0, 12), { delay: 30 });
    await idle(page, 1200);
    await page.locator('[role=option]:visible, .fi-select-input-option:visible').filter({ hasText: text }).first().click();
    await idle(page, 800);
}

/** True when a list page shows a row containing `text`. */
export async function listHas(page, path, text) {
    await go(page, path);
    return (await page.locator('main').getByText(text, { exact: false }).count()) > 0;
}

export async function fillForm(page, values) {
    for (const [k, v] of Object.entries(values)) {
        const el = page.locator(`[id="form.${k}"]`);
        const tag = await el.evaluate((e) => e.tagName.toLowerCase()).catch(() => null);
        if (tag === 'select') await el.selectOption(typeof v === 'object' ? v : { label: v }).catch(async () => el.selectOption(v));
        else if (tag === 'input' || tag === 'textarea') {
            const type = await el.getAttribute('type');
            const ro = await el.evaluate((e) => e.readOnly);
            if (ro) await setForm(page, k, v);
            else await el.fill(String(v));
        } else await setForm(page, k, v);
        await page.waitForTimeout(150);
    }
    await idle(page, 600);
}
