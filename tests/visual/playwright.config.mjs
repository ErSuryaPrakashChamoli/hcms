// UX.18 visual regression. Run against the disposable, frozen-clock showcase (see README.md):
//   tests/visual/prepare.sh && tests/visual/serve.sh      (once per run: fresh data at the frozen moment)
//   npx playwright test -c tests/visual                    (compare with the reviewed baselines)
//   npx playwright test -c tests/visual --update-snapshots (only after reviewing every difference)
import { defineConfig } from '@playwright/test';

const touch = { hasTouch: true, isMobile: true };

export default defineConfig({
    testDir: '.',
    testMatch: /.*\.visual\.mjs/,
    globalSetup: './global-setup.mjs',
    // One worker, fixed order: some screens record state (recent items, the Home visit), so order is part of the data.
    workers: 1,
    fullyParallel: false,
    retries: 0,
    timeout: 90_000,
    reporter: [['list'], ['json', { outputFile: 'results/visual-results.json' }], ['html', { outputFolder: 'results/report', open: 'never' }]],
    outputDir: 'results/artifacts',
    snapshotPathTemplate: '{testDir}/__screenshots__/{projectName}/{arg}{ext}',
    expect: {
        toHaveScreenshot: { maxDiffPixelRatio: 0.001, threshold: 0.2, animations: 'disabled', caret: 'hide', scale: 'css', stylePath: './stabilise.css' },
    },
    use: {
        baseURL: process.env.VISUAL_BASE_URL ?? 'http://127.0.0.1:8092',
        reducedMotion: 'reduce',
        deviceScaleFactor: 1,
        locale: 'en-GB',
        timezoneId: 'Asia/Kolkata',
    },
    projects: [
        { name: 'chromium-phone', metadata: { vp: 'phone' }, use: { browserName: 'chromium', viewport: { width: 390, height: 844 }, ...touch } },
        { name: 'chromium-tablet', metadata: { vp: 'tablet' }, use: { browserName: 'chromium', viewport: { width: 768, height: 1024 }, ...touch } },
        { name: 'chromium-desktop', metadata: { vp: 'desktop' }, use: { browserName: 'chromium', viewport: { width: 1440, height: 900 } } },
        // Landscape tablet at the rail breakpoint (the rail replaces the phone bar at 1024 px): a focused set.
        { name: 'chromium-tablet-landscape', metadata: { vp: 'tabletL', landscape: true }, use: { browserName: 'chromium', viewport: { width: 1024, height: 768 }, ...touch } },
        // Cross-engine smoke: a few screens in Firefox and the WebKit engine (Playwright WPE MiniBrowser, not Apple Safari).
        { name: 'firefox-desktop', metadata: { vp: 'desktop', engine: 'firefox' }, use: { browserName: 'firefox', viewport: { width: 1440, height: 900 } } },
        { name: 'webkit-phone', metadata: { vp: 'phone', engine: 'webkit' }, use: { browserName: 'webkit', viewport: { width: 390, height: 844 }, ...touch, launchOptions: process.env.WEBKIT_EXE ? { executablePath: process.env.WEBKIT_EXE } : {} } },
    ],
});
