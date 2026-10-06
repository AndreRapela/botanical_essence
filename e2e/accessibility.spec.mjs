import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const wcagTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];

function summarizeViolations(violations) {
  return violations.map((violation) => {
    const targets = violation.nodes.slice(0, 6).flatMap((node) => node.target).join(', ');
    const remainder = violation.nodes.length > 6 ? `, +${violation.nodes.length - 6} more` : '';
    return `${violation.impact ?? 'unknown'} ${violation.id}: ${violation.help} (${violation.nodes.length} affected: ${targets}${remainder})`;
  });
}

const publicPages = [
  { name: 'home', path: '/' },
  { name: 'catalog', path: '/shop' },
  { name: 'marketplace', path: '/marketplace' },
  { name: 'product detail', path: '/product/monstera-essencial', ready: '.product-detail-copy h1' },
  { name: 'checkout', path: '/payment', prepare: async (page) => {
    await page.goto('/product/monstera-essencial', { waitUntil: 'domcontentloaded' });
    await page.locator('.product-detail-actions .button-green').click();
    await expect(page).toHaveURL(/\/payment$/);
  } },
  { name: 'favorites', path: '/favorites' },
  { name: 'contact', path: '/contact' },
  { name: 'delivery policy', path: '/deliveries' },
  { name: 'returns policy', path: '/returns' },
  { name: 'login', path: '/login' },
  { name: 'registration', path: '/login', prepare: async (page) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByRole('group', { name: /account access options|options d’accès au compte/i })
      .getByRole('button', { name: /create account|créer un compte/i }).click();
    await expect(page.locator('.register-form')).toBeVisible();
  } },
];

for (const route of publicPages) {
  test(`public ${route.name} page has no automated WCAG A/AA violations on desktop or mobile`, async ({ page }) => {
    for (const viewport of [{ name: 'desktop', width: 1280 }, { name: 'mobile', width: 390 }]) {
      await page.setViewportSize({ width: viewport.width, height: 900 });
      if (route.prepare) {
        await route.prepare(page);
      } else {
        await page.goto(route.path, { waitUntil: 'domcontentloaded' });
      }

      await expect(page.locator('main:visible').first()).toBeVisible();
      if (route.ready) await expect(page.locator(route.ready)).toBeVisible();
      const { violations } = await new AxeBuilder({ page }).withTags(wcagTags).analyze();
      const summary = summarizeViolations(violations);
      expect(summary, `Accessibility violations on ${route.name} at ${viewport.name}`).toEqual([]);
    }
  });
}

const demoWorkspaces = [
  { role: 'customer', name: /enter as user|entrer comme utilisateur/i, path: '/account', landmark: '.account-view' },
  { role: 'seller', name: /enter as seller|entrer comme vendeur/i, path: '/seller', landmark: '.management-heading' },
  { role: 'administrator', name: /enter as administrator|entrer comme administrateur/i, path: '/admin', landmark: '.management-heading' },
];

for (const workspace of demoWorkspaces) {
  test(`${workspace.role} workspace has no automated WCAG A/AA violations`, async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    const response = await page.request.get('/api/auth/demo-accounts');
    expect(response.ok()).toBeTruthy();
    const demoState = await response.json();
    if (!demoState.enabled) {
      if (process.env.REQUIRE_DEMO_AUTH_TESTS === 'true') {
        throw new Error('CI requires seeded demo accounts for workspace accessibility scans.');
      }
      test.skip(true, 'Demo role accounts are disabled outside the disposable test environment.');
    }

    const demoButton = page.locator('.demo-login button').filter({ hasText: workspace.name });
    await expect(demoButton).toHaveCount(1);
    await demoButton.click();
    await expect(page).toHaveURL(new RegExp(`${workspace.path}$`));
    await expect(page.locator(workspace.landmark)).toBeVisible();

    for (const viewport of [{ name: 'desktop', width: 1280 }, { name: 'mobile', width: 390 }]) {
      await page.setViewportSize({ width: viewport.width, height: 900 });
      const { violations } = await new AxeBuilder({ page }).withTags(wcagTags).analyze();
      const summary = summarizeViolations(violations);
      expect(summary, `Accessibility violations in ${workspace.role} at ${viewport.name}`).toEqual([]);
    }
  });
}
