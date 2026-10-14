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
  const mobileNavigation = page.getByRole('navigation', { name: 'Mobile bottom navigation' });
  if (await mobileNavigation.isVisible()) {
    const scrollPolicy = await page.locator('html').evaluate((element) => ({
      behavior: getComputedStyle(element).scrollBehavior,
      paddingBottom: parseFloat(getComputedStyle(element).scrollPaddingBottom),
    }));
    expect(scrollPolicy.behavior).toBe('auto');
    await roleButton.evaluate((element) => element.scrollIntoView({ block: 'center', behavior: 'instant' }));
    const buttonBox = await roleButton.boundingBox();
    const navigationBox = await mobileNavigation.boundingBox();
    expect(buttonBox).not.toBeNull();
    expect(navigationBox).not.toBeNull();
    expect(scrollPolicy.paddingBottom).toBeGreaterThan(navigationBox.height);
    expect(buttonBox.y).toBeGreaterThanOrEqual(0);
    expect(buttonBox.y + buttonBox.height).toBeLessThan(navigationBox.y);
  }
  const [loginResponse] = await Promise.all([
    page.waitForResponse((response) => new URL(response.url()).pathname === '/api/auth/demo-login'
      && response.request().method() === 'POST'),
    roleButton.click(),
  ]);
  expect(loginResponse.ok(), 'demo login must succeed before checking protected content').toBeTruthy();
  const { user } = await loginResponse.json();
  expect(user.role).toBe(loginResponse.request().postDataJSON().role);
  const destination = { admin: 'admin', seller: 'seller', customer: 'account' }[user.role];
  expect(destination).toBeDefined();
  await expect(page).toHaveURL(new RegExp(`/${destination}$`));
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

test('administrator confirms only the remaining balance of a partly refunded order on mobile', async ({ page }) => {
  let cancelled = false;
  const order = { id: 1, number: 'PREVIEW-BALANCE', customerName: 'Preview buyer', email: 'buyer@example.test',
    status: 'confirmed', paymentStatus: 'partially_refunded', paymentProvider: 'stripe', paymentMethod: 'card',
    testPayment: false, refundAttempt: 1, refundAttemptIsPartial: true, refundOutcome: 'succeeded',
    refundedTotalCents: 2500, refundAttemptAmountCents: 2500, externalRefundUnmatched: false,
    paymentSessionUnconfirmed: false, paymentReconciliationRequired: false,
    subtotal: 100, shipping: 0, total: 100, currency: 'EUR', createdAt: '2026-10-10T10:00:00Z', items: [] };
  await page.route('**/api/admin/orders?*', (route) => route.fulfill({ json: {
    data: [{ ...order, ...(cancelled ? { status: 'cancelled', paymentStatus: 'refunded', refundedTotalCents: 10000 } : {}) }],
    page: 1, limit: 50, total: 1, hasMore: false
  } }));
  await page.route('**/api/admin/orders/cancel', async (route) => {
    expect(route.request().method()).toBe('POST');
    expect(route.request().postDataJSON()).toEqual({ orderNumber: 'PREVIEW-BALANCE', action: 'cancel' });
    cancelled = true;
    await route.fulfill({ json: { message: 'Remaining balance refunded in this UI fixture; no provider request was sent.' } });
  });
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAs(page, /enter as administrator|entrer comme administrateur/i);
  const row = page.locator('.admin-order-row').filter({ hasText: 'PREVIEW-BALANCE' });
  const button = row.getByRole('button', { name: /cancel & refund balance|annuler et rembourser le solde/i });
  await expect(button).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  const statusBox = await row.locator(':scope > span').boundingBox();
  const amountBox = await row.locator(':scope > div').nth(2).boundingBox();
  expect(statusBox).not.toBeNull();
  expect(amountBox).not.toBeNull();
  expect(statusBox.y + statusBox.height).toBeLessThanOrEqual(amountBox.y);
  const audit = await new AxeBuilder({ page }).include('app-admin-financial-operations').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(audit.violations).toEqual([]);
  const confirmations = [];
  page.once('dialog', async (dialog) => { confirmations.push(dialog.message()); await dialog.accept(); });
  await button.click();
  await expect(row).toContainText(/cancelled|annulée/i);
  expect(confirmations.join(' ')).toMatch(/remaining balance|solde restant/i);
  expect(cancelled).toBe(true);
});

test('administrator can explicitly refund a cancelled order balance without cancelling its stock again', async ({ page }, testInfo) => {
  let requested = false;
  const order = { id: 1, number: 'PREVIEW-CANCELLED-BALANCE', customerName: 'Preview buyer', email: 'buyer@example.test',
    status: 'cancelled', paymentStatus: 'partially_refunded', paymentProvider: 'stripe', paymentMethod: 'card',
    testPayment: false, refundAttempt: 2, refundAttemptIsPartial: false, refundOutcome: 'succeeded',
    refundedTotalCents: 7500, refundAttemptAmountCents: 7500, externalRefundUnmatched: false,
    refundErrorCode: 'refund_balance_remaining', refundErrorMessage: 'Review the remaining balance before authorizing another refund.',
    paymentSessionUnconfirmed: false, paymentReconciliationRequired: false,
    subtotal: 100, shipping: 0, total: 100, currency: 'EUR', createdAt: '2026-10-10T10:00:00Z', items: [] };
  await page.route('**/api/admin/orders?*', (route) => route.fulfill({ json: {
    data: [{ ...order, ...(requested ? { paymentStatus: 'refunded', refundedTotalCents: 10000 } : {}) }],
    page: 1, limit: 50, total: 1, hasMore: false
  } }));
  await page.route('**/api/admin/orders/cancel', async (route) => {
    expect(route.request().method()).toBe('POST');
    expect(route.request().postDataJSON()).toEqual({ orderNumber: 'PREVIEW-CANCELLED-BALANCE', action: 'cancel' });
    requested = true;
    await route.fulfill({ json: { code: 'refund_balance_confirmed', message: 'Mensagem bruta da API, apenas fixture sem operação financeira.' } });
  });
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAs(page, /enter as administrator|entrer comme administrateur/i);
  const row = page.locator('.admin-order-row').filter({ hasText: 'PREVIEW-CANCELLED-BALANCE' });
  const button = row.getByRole('button', { name: /refund remaining balance|rembourser le solde restant/i });
  await expect(button).toBeVisible();
  const audit = await new AxeBuilder({ page }).include('app-admin-financial-operations').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(audit.violations).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  await row.screenshot({ path: testInfo.outputPath('cancelled-balance-mobile.png') });
  const confirmations = [];
  page.once('dialog', async (dialog) => { confirmations.push(dialog.message()); await dialog.accept(); });
  await button.click();
  await expect(row).toContainText(/refunded|remboursée/i);
  await expect(page.locator('.management-notice.success')).toContainText(/refund balance is settled|solde du remboursement est réglé/i);
  await expect(page.locator('.management-notice.success')).not.toContainText('Mensagem bruta');
  expect(confirmations.join(' ')).toMatch(/stock will not be restored again|stock ne sera pas réintégré une seconde fois/i);
  expect(requested).toBe(true);
});

test('historical unresolved refunds block new admin refunds and allow only provider history lookup on mobile', async ({ page }, testInfo) => {
  let checked = false;
  const oldReference = 're_history' + 'x'.repeat(90);
  const order = { id: 1, number: 'PREVIEW-HISTORICAL-REFUND', customerName: 'Preview buyer', email: 'buyer@example.test',
    status: 'confirmed', paymentStatus: 'partially_refunded', paymentProvider: 'stripe', paymentMethod: 'card',
    testPayment: false, refundAttempt: 2, refundAttemptIsPartial: false, refundOutcome: 'succeeded',
    refundedTotalCents: 2500, refundAttemptAmountCents: 2500, externalRefundUnmatched: false,
    historicalRefunds: [{ attempt: 1, kind: 'partial', id: oldReference, amountCents: 2500, status: 'pending' }],
    refundErrorCode: null, refundErrorMessage: null, paymentSessionUnconfirmed: false, paymentReconciliationRequired: false,
    subtotal: 100, shipping: 0, total: 100, currency: 'EUR', createdAt: '2026-10-10T10:00:00Z', items: [] };
  await page.route('**/api/admin/orders?*', (route) => route.fulfill({ json: { data: [order], page: 1, limit: 50, total: 1, hasMore: false } }));
  await page.route('**/api/admin/orders/cancel', async (route) => {
    expect(route.request().postDataJSON()).toEqual({ orderNumber: order.number, action: 'check_refund_history' });
    checked = true;
    await route.fulfill({ json: { code: 'refund_history_checked', message: 'Mensagem bruta, apenas fixture sem operação financeira.' } });
  });
  await page.setViewportSize({ width: 320, height: 844 });
  await loginAs(page, /enter as administrator|entrer comme administrateur/i);
  const row = page.locator('.admin-order-row').filter({ hasText: order.number });
  await expect(row).toContainText(oldReference);
  await expect(row.getByRole('button', { name: /partial refund|retry refund|cancel & refund|refund remaining balance|remboursement partiel|réessayer le remboursement|annuler et rembourser|rembourser le solde restant/i })).toHaveCount(0);
  const button = row.getByRole('button', { name: /check refund history|vérifier l’historique des remboursements/i });
  await expect(button).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  const statusBox = await row.locator(':scope > span').boundingBox();
  const amountBox = await row.locator(':scope > div').nth(2).boundingBox();
  expect(statusBox).not.toBeNull();
  expect(amountBox).not.toBeNull();
  expect(statusBox.y + statusBox.height).toBeLessThanOrEqual(amountBox.y);
  const audit = await new AxeBuilder({ page }).include('app-admin-financial-operations').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(audit.violations).toEqual([]);
  await row.screenshot({ path: testInfo.outputPath('historical-refund-mobile.png') });
  await button.click();
  await expect(page.locator('.management-notice.success')).toContainText(/without sending a new refund|sans envoyer de nouveau remboursement/i);
  await expect(page.locator('.management-notice.success')).not.toContainText('Mensagem bruta');
  expect(checked).toBe(true);
  await expect(row).toContainText(oldReference);
});

test('manual full and partial decisions require the exact displayed selection and never retry a stale decision automatically', async ({ page }, testInfo) => {
  const reason = 'Independently verified the exact displayed Stripe attempt';
  const orders = ['full', 'partial'].map((kind, index) => ({
    id: index + 1, number: `PREVIEW-MANUAL-${kind.toUpperCase()}`, customerName: 'Preview buyer', email: 'buyer@example.test',
    status: 'confirmed', paymentStatus: 'refund_pending', paymentProvider: 'stripe', paymentMethod: 'card', testPayment: false,
    refundAttempt: 1, refundAttemptIsPartial: kind === 'partial', refundOutcome: 'unknown', refundId: null,
    refundedTotalCents: 0, refundAttemptAmountCents: kind === 'full' ? 10000 : 2500, historicalRefunds: [],
    externalRefundUnmatched: false, paymentSessionUnconfirmed: false, paymentReconciliationRequired: false,
    subtotal: 100, shipping: 0, total: 100, currency: 'EUR', createdAt: '2026-10-14T10:00:00Z', items: []
  }));
  const requests = [];
  const targetFor = (order) => ({ orderNumber: order.number, attempt: order.refundAttempt, refundId: order.refundId,
    kind: order.refundAttemptIsPartial ? 'partial' : 'full', amountCents: order.refundAttemptAmountCents,
    refundedTotalCents: order.refundedTotalCents, outcome: order.refundOutcome, totalCents: 10000, currency: 'EUR' });
  await page.route('**/api/admin/orders?*', (route) => route.fulfill({ json: {
    data: orders, page: 1, limit: 50, total: orders.length, hasMore: false
  } }));
  await page.route('**/api/admin/orders/cancel', async (route) => {
    expect(route.request().method()).toBe('POST');
    const input = route.request().postDataJSON();
    const order = orders.find((candidate) => candidate.number === input.orderNumber);
    expect(order).toBeDefined();
    expect(input).toEqual({ orderNumber: order.number, action: 'resolve_refund', resolution: 'not_refunded',
      reason, refundTarget: targetFor(order) });
    requests.push(input);
    if (order.refundAttempt === 1) {
      order.refundAttempt = 2;
      order.refundId = 're_newselection' + (order.refundAttemptIsPartial ? 'partial' : 'full');
      await route.fulfill({ status: 409, json: { code: 'refund_target_stale', message: 'Mensagem bruta: seleção antiga, apenas fixture.' } });
    } else {
      order.refundOutcome = 'manual_not_refunded';
      order.paymentStatus = 'paid';
      await route.fulfill({ json: { code: 'refund_resolved_manually', message: 'Mensagem bruta: decisão registrada, apenas fixture.' } });
    }
  });
  const handleDialog = async (dialog) => {
    expect(['prompt', 'confirm']).toContain(dialog.type());
    await dialog.accept(dialog.type() === 'prompt' ? reason : undefined);
  };
  page.on('dialog', handleDialog);
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAs(page, /enter as administrator|entrer comme administrateur/i);
  for (const [index, order] of orders.entries()) {
    if (index === 1) {
      await page.setViewportSize({ width: 1280, height: 844 });
      await page.locator('.language-switch').getByRole('button', { name: 'FR', exact: true }).click();
      await page.setViewportSize({ width: 320, height: 844 });
    }
    const row = page.locator('.admin-order-row').filter({ hasText: order.number });
    const button = row.getByRole('button', { name: /resolve as not refunded|marquer comme non remboursé/i });
    await expect(row).toContainText(/unknown #1/);
    await expect(row).toContainText(/attempt amount|montant de la tentative/i);
    await button.click();
    await expect(page.locator('.management-notice.error')).toContainText(/No automatic retry was sent|Aucun nouvel essai automatique n’a été envoyé/i);
    await expect(page.locator('.management-notice.error')).not.toContainText('Mensagem bruta');
    expect(requests).toHaveLength(index * 2 + 1);
    await expect(row).toContainText(/unknown #1/);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(row).toContainText(/unknown #2/);
    await expect(row).toContainText(order.refundId);
    const audit = await new AxeBuilder({ page }).include('app-admin-financial-operations').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(audit.violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await row.screenshot({ path: testInfo.outputPath(`manual-${index === 0 ? 'full' : 'partial'}-selection-mobile.png`) });
    await button.click();
    await expect(page.locator('.management-notice.success')).toContainText(/No new Stripe refund was sent|Aucun nouveau remboursement Stripe n’a été envoyé/i);
    await expect(page.locator('.management-notice.success')).not.toContainText('Mensagem bruta');
    await expect(row).toContainText(/manual_not_refunded #2/);
    await expect(button).toHaveCount(0);
    expect(requests).toHaveLength((index + 1) * 2);
  }
  page.off('dialog', handleDialog);
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
