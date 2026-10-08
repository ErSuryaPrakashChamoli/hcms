// UX.18 behavioural browser tests (Back closes overlays, Firefox notification loading), against the same frozen-clock
// showcase as the visual suite: tests/visual/serve.sh, then npm run browser:test.
import { defineConfig } from '@playwright/test';

const touch = { hasTouch: true, isMobile: true };

export default defineConfig({
    testDir: '.',
    testMatch: /.*\.browser\.mjs/,
    globalSetup: '../visual/global-setup.mjs',
    workers: 1,
    retries: 0,
    timeout: 90_000,
    reporter: [['list'], ['json', { outputFile: 'results/browser-results.json' }]],
    outputDir: 'results/artifacts',
    use: { baseURL: process.env.VISUAL_BASE_URL ?? 'http://127.0.0.1:8092', reducedMotion: 'reduce' },
    projects: [
        { name: 'chromium-phone', use: { browserName: 'chromium', viewport: { width: 390, height: 844 }, ...touch } },
        { name: 'chromium-desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 900 } } },
        { name: 'firefox-phone', use: { browserName: 'firefox', viewport: { width: 390, height: 844 } } },
        { name: 'webkit-phone', use: { browserName: 'webkit', viewport: { width: 390, height: 844 }, ...touch, launchOptions: process.env.WEBKIT_EXE ? { executablePath: process.env.WEBKIT_EXE } : {} } },
    ],
});
