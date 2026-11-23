// Playwright QA for the Legal form (mirrors vea-join's tests/e2e setup).
// Runs against a local `php -S` over the built public/ (router.php adds the real
// security headers, so the CSP applies). Fake data only; nothing is ever sent.
// Run:  cd tests/e2e && npm test        (after `composer build`)
import { defineConfig } from '@playwright/test';

const PORT = Number(process.env.E2E_PORT || 8189);

export default defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.js$/,
  timeout: 60_000,
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  outputDir: './test-results',
  webServer: {
    command: `php -S 127.0.0.1:${PORT} router.php`,
    url: `http://127.0.0.1:${PORT}/legal/`,
    reuseExistingServer: false,
    timeout: 20_000,
    stdout: 'ignore',
    stderr: 'ignore',   // php -S logs every request
  },
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    bypassCSP: false,          // the real CSP must apply
    browserName: 'chromium',
    headless: true,
    locale: 'en-US',
    timezoneId: 'America/New_York',
  },
  projects: [
    { name: 'desktop-1280', use: { viewport: { width: 1280, height: 900 } } },
    { name: 'mobile-390', use: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, hasTouch: true, isMobile: true } },
  ],
});
