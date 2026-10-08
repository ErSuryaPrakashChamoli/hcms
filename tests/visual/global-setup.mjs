// Signs each persona in once through the normal login form and keeps the session for the run.
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { PERSONAS, authFile } from './personas.mjs';

export default async function globalSetup(config) {
    const baseURL = config.projects[0].use.baseURL;
    mkdirSync(new URL('./.auth/', import.meta.url).pathname, { recursive: true });
    const browser = await chromium.launch();
    for (const [persona, email] of Object.entries(PERSONAS)) {
        const context = await browser.newContext();
        const page = await context.newPage();
        await page.goto(`${baseURL}/admin/login`);
        await page.fill('input[type=email]', email);
        await page.fill('input[type=password]', process.env.SHOWCASE_PASSWORD ?? 'password');
        await Promise.all([page.waitForURL(/\/admin$/, { timeout: 120_000 }), page.click('button[type=submit]')]);
        await context.storageState({ path: authFile(persona) });
        await context.close();
    }
    await browser.close();
}
