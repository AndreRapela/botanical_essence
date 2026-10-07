import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const pagesToCheck = [
  '/',
  '/shop',
  '/marketplace',
  '/product/monstera-essencial',
  '/payment',
  '/returns',
];
let localSimulationOrderNumber = '';

async function loginAs(page, rolePattern) {
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  const demoResponse = await page.request.get('/api/auth/demo-accounts');
  expect(demoResponse.ok(), 'demo-account status endpoint should respond').toBeTruthy();
  const demoState = await demoResponse.json();

  if (!demoState.enabled) {
    if (process.env.REQUIRE_DEMO_AUTH_TESTS === 'true') {
      throw new Error('CI requires seeded demo accounts for browser authentication coverage.');
    }
    test.skip(true, 'Demo role accounts are disabled in this local environment.');
  }

  const roleButton = page.locator('.demo-login button').filter({ hasText: rolePattern });
  await expect(roleButton).toHaveCount(1);
  await roleButton.click();
}

test('storefront, catalog, product, checkout and policy pages fit mobile widths', async ({ page }) => {
  const uncaughtErrors = [];
  page.on('pageerror', (error) => uncaughtErrors.push(error.message));

  for (const width of [320, 390, 768]) {
    await page.setViewportSize({ width, height: 900 });
    for (const path of pagesToCheck) {
      await page.goto(path, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('main:visible').first()).toBeVisible();

      if (path === '/shop' || path === '/marketplace') {
        await expect(page.locator('.catalog-page .product-card').first()).toBeVisible();
      } else if (path.startsWith('/product/')) {
        await expect(page.locator('.product-detail-copy h1')).toBeVisible();
      } else if (path === '/') {
        await expect(page.locator('main:visible h1:visible').first()).toBeVisible();
      } else if (path === '/payment') {
        await expect(page.locator('.checkout-page h1, .checkout-page h2').first()).toBeVisible();
      }

      const dimensions = await page.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        document: document.documentElement.scrollWidth,
      }));
      expect(dimensions.document, `${path} overflows at ${width}px`).toBeLessThanOrEqual(dimensions.viewport);
    }
  }

  expect(uncaughtErrors).toEqual([]);
});

test('clicking a catalog photo opens its product page and Buy now opens checkout', async ({ page }) => {
  await page.goto('/shop', { waitUntil: 'domcontentloaded' });
  const firstProduct = page.locator('.catalog-page .product-card').first();
  await expect(firstProduct).toBeVisible();
  await firstProduct.locator('.product-image-link').click();
  await expect(page).toHaveURL(/\/product\/[a-z0-9-]+$/);
  await expect(page.locator('.product-detail-copy h1')).toBeVisible();
  const productTitle = await page.locator('.product-detail-copy h1').innerText();
  await expect(page.locator('.product-specs')).toBeVisible();

  await page.locator('.product-detail-actions .button-green').click();
  await expect(page).toHaveURL(/\/payment$/);
  await expect(page.locator('.checkout-page .order-summary')).toContainText(productTitle);
});

test('customer can complete the isolated one-cent, free-delivery checkout without contacting Stripe', async ({ page }) => {
  if (process.env.ALLOW_TEST_ORDER_CREATION !== 'true') {
    test.skip(true, 'Order creation is enabled only for a disposable E2E database.');
  }

  await loginAs(page, /enter as user|entrer comme utilisateur/i);
  await expect(page).toHaveURL(/\/account$/);

  await page.goto('/product/monstera-essencial', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.product-detail-copy h1')).toBeVisible();
  await page.locator('.product-detail-actions .button-green').click();
  await expect(page).toHaveURL(/\/payment$/);
  await expect(page.locator('.payment-preview-banner')).toContainText(/0[,.]01/);

  await page.locator('input[name="address"]').fill('Rue du Marché aux Herbes 18');
  await page.locator('input[name="postalCode"]').fill('1000');
  await page.locator('input[name="postalCode"]').press('Tab');
  await page.locator('input[name="city"]').fill('Brussels');

  await expect(page.locator('.shipping-options .shipping-choice').first()).toBeVisible();
  await expect(page.locator('.purchase-final-button')).toBeEnabled();
  await expect(page.locator('.purchase-final-total strong')).toContainText(/0[,.]01/);
  await page.locator('.purchase-final-button').click();

  await expect(page.locator('.checkout-success')).toContainText(/local test complete|test local terminé/i);
  await expect(page.locator('.checkout-success')).toContainText(/no money was moved|aucun argent n’a été transféré/i);
  await expect(page.locator('.checkout-success strong').first()).toContainText(/^BE-/);
  localSimulationOrderNumber = await page.locator('.checkout-success strong').first().innerText();
});

test('administrator demo account opens the protected admin workspace', async ({ page }) => {
  await loginAs(page, /enter as administrator|entrer comme administrateur/i);
  await expect(page).toHaveURL(/\/admin$/);
  await expect(page.locator('main:visible').first()).toBeVisible();
  await expect(page.locator('main:visible').first()).toContainText(/orders & payments|commandes et paiements|administrator|administrateur/i);

  if (process.env.ALLOW_TEST_ORDER_CREATION === 'true') {
    expect(localSimulationOrderNumber).toMatch(/^BE-/);
    const orderRow = page.locator('.admin-order-row').filter({ hasText: localSimulationOrderNumber });
    await expect(orderRow).toHaveCount(1);
    await expect(orderRow).toContainText(/TEST/i);
    const confirmationTexts = [];
    page.once('dialog', async (dialog) => {
      confirmationTexts.push(dialog.message());
      await dialog.accept();
    });
    await orderRow.getByRole('button', { name: /cancel test order|annuler la commande de test/i }).click();
    expect(confirmationTexts.join(' ')).toMatch(/local test purchase|simulation locale/i);
    await expect(orderRow).toContainText(/cancelled/i);
  }
});

test('seller demo account opens the protected seller workspace', async ({ page }) => {
  await loginAs(page, /enter as seller|entrer comme vendeur/i);
  await expect(page).toHaveURL(/\/seller$/);
  await expect(page.locator('main:visible').first()).toBeVisible();
  await expect(page.locator('main:visible').first()).toContainText(/store|boutique|product|produit/i);
});

test('administrator reviews an external refund in an accessible mobile form without sending another refund', async ({ page }, testInfo) => {
  let reviewed = false;
  const issue = { refundId: 're_preview1', orderId: 1, orderNumber: 'PREVIEW-REFUND', paymentIntentId: 'pi_preview1',
    amountCents: 2500, currency: 'EUR', providerStatus: 'succeeded', resolution: 'unmatched',
    createdAt: '2026-10-07T10:00:00Z', lastSyncedAt: '2026-10-07T10:00:00Z',
    attempts: [{ attempt: 1, kind: 'partial', amountCents: 2500, currency: 'EUR', status: 'manual', refundId: null, alreadyCounted: true }] };
  await page.route('**/api/admin/refund-reconciliation?*', (route) => route.fulfill({ json: { data: reviewed ? [] : [issue], page: 1, limit: 50, total: reviewed ? 0 : 1, hasMore: false } }));
  await page.route('**/api/admin/refund-reconciliation/resolve', async (route) => {
    expect(route.request().method()).toBe('POST');
    expect(route.request().postDataJSON()).toEqual({ refundId: 're_preview1', action: 'link', attempt: 1, reason: 'Verified the original manually counted refund' });
    reviewed = true;
    await route.fulfill({ json: { message: 'Reconciliation recorded; no provider operation was sent.' } });
  });
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAs(page, /enter as administrator|entrer comme administrateur/i);
  const queue = page.locator('app-admin-refund-reconciliation');
  await expect(queue).toContainText('PREVIEW-REFUND');
  await queue.getByRole('button', { name: /review & reconcile|vérifier et rapprocher/i }).click();
  await expect(queue.locator('form')).toBeVisible();
  await expect(queue).toContainText(/does not send money|n’envoie pas d’argent/i);
  const audit = await new AxeBuilder({ page }).include('app-admin-refund-reconciliation').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(audit.violations).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  await queue.screenshot({ path: testInfo.outputPath('external-refund-review-mobile.png') });
  for (const width of [320, 1280]) {
    await page.setViewportSize({ width, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  }
  await queue.screenshot({ path: testInfo.outputPath('external-refund-review-desktop.png') });
  await page.setViewportSize({ width: 390, height: 844 });
  await queue.getByLabel(/audit reason|motif pour l’audit/i).fill('Verified the original manually counted refund');
  await queue.getByRole('checkbox').check();
  await queue.getByRole('button', { name: /confirm reconciliation|confirmer le rapprochement/i }).click();
  await expect(queue).toContainText(/No new Stripe refund was sent|Aucun nouveau remboursement Stripe/i);
  await expect(queue).not.toContainText('PREVIEW-REFUND');
  expect(reviewed).toBe(true);
});

test('standard product badges follow the selected language in catalog and product pages', async ({ page }) => {
  await page.goto('/shop', { waitUntil: 'domcontentloaded' });
  const featuredProduct = page.locator('.catalog-page .product-card').filter({ hasText: 'Most loved' });
  await expect(featuredProduct).toHaveCount(1);
  await page.locator('.language-switch').getByRole('button', { name: 'FR', exact: true }).click();
  const frenchProduct = page.locator('.catalog-page .product-card').filter({ hasText: 'Coup de cœur' });
  await expect(frenchProduct.locator('.product-badge')).toHaveText('Coup de cœur');
  await frenchProduct.locator('.product-image-link').click();
  await expect(page).toHaveURL(/\/product\/[a-z0-9-]+$/);
  await expect(page.locator('.product-detail-image .product-badge')).toHaveText('Coup de cœur');
  await page.locator('.language-switch').getByRole('button', { name: 'EN', exact: true }).click();
  await expect(page.locator('.product-detail-image .product-badge')).toHaveText('Most loved');
});
