import { defineConfig, devices } from '@playwright/test';

const projects = [
  {
    name: 'chromium',
    use: { ...devices['Desktop Chrome'], browserName: 'chromium' },
  },
  {
    name: 'webkit-storefront',
    testMatch: '**/storefront.spec.mjs',
    use: { ...devices['Desktop Safari'], browserName: 'webkit' },
  },
];

// CI installs Firefox's system libraries. Local users can opt in when their
// runtime supports the Playwright-managed Firefox build.
if (process.env.CI || process.env.RUN_FIREFOX_E2E === 'true') {
  projects.push({
    name: 'firefox-storefront',
    testMatch: '**/storefront.spec.mjs',
    use: { ...devices['Desktop Firefox'], browserName: 'firefox' },
  });
}

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? 'line' : 'list',
  timeout: 90_000,
  expect: { timeout: 10_000 },
  outputDir: process.env.PLAYWRIGHT_OUTPUT_DIR ?? './test-results',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:4300',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects,
});
