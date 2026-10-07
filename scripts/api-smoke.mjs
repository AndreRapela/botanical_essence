const baseUrl = (process.env.API_BASE_URL ?? 'http://localhost:4300').replace(/\/$/, '');

class SessionClient {
  cookies = new Map();

  async request(path, init = {}) {
    const headers = new Headers(init.headers);
    if (this.cookies.size) {
      headers.set('Cookie', [...this.cookies].map(([name, value]) => `${name}=${value}`).join('; '));
    }
    const response = await fetch(`${baseUrl}${path}`, { ...init, headers, redirect: 'manual' });
    const setCookies = typeof response.headers.getSetCookie === 'function'
      ? response.headers.getSetCookie()
      : [response.headers.get('set-cookie')].filter(Boolean);
    for (const value of setCookies) {
      const [pair] = value.split(';', 1);
      const separator = pair.indexOf('=');
      if (separator > 0) {
        this.cookies.set(pair.slice(0, separator), pair.slice(separator + 1));
      }
    }
    return response;
  }

  async json(path, init = {}) {
    const response = await this.request(path, init);
    const body = await response.json().catch(() => null);
    return { response, body };
  }
}

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const tests = [];
function test(name, operation) {
  tests.push({ name, operation });
}

test('health, request id and security headers', async () => {
  const client = new SessionClient();
  const { response, body } = await client.json('/api/health');
  assert(response.status === 200, `health returned ${response.status}`);
  assert(body?.status === 'ok', 'health payload is invalid');
  assert(response.headers.get('x-request-id'), 'X-Request-ID is missing');
  assert(response.headers.get('x-content-type-options') === 'nosniff', 'nosniff is missing');
  assert(response.headers.get('content-security-policy')?.includes("default-src 'self'"), 'frontend CSP is missing');
});

test('router distinguishes unknown route and unsupported method', async () => {
  const client = new SessionClient();
  const missing = await client.json('/api/not-a-route');
  assert(missing.response.status === 404, `unknown route returned ${missing.response.status}`);
  const method = await client.json('/api/products', { method: 'PUT' });
  assert(method.response.status === 405, `unsupported method returned ${method.response.status}`);
  assert(method.response.headers.get('allow')?.includes('GET'), 'Allow header does not advertise GET');
});

test('session and CSRF protection reject unsafe requests without a token', async () => {
  const anonymous = new SessionClient();
  const rejected = await anonymous.json('/api/shipping/quotes', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: '{}'
  });
  assert(rejected.response.status === 403, `missing CSRF returned ${rejected.response.status}`);
  assert(rejected.body?.code === 'csrf_invalid', 'missing CSRF did not return stable error code');

  const client = new SessionClient();
  const session = await client.json('/api/auth/session');
  assert(session.response.status === 200, 'session bootstrap failed');
  assert(typeof session.body?.csrfToken === 'string' && session.body.csrfToken.length === 64, 'CSRF token is invalid');
  assert(client.cookies.has('botanical_session'), 'session cookie is missing');
});

test('concurrent public reads cannot overwrite session bootstrap cookies', async () => {
  const client = new SessionClient();
  const paths = ['/api', '/api/health', '/api/products', '/api/sellers', '/api/auth/demo-accounts', '/api/product-reviews?productId=1'];
  const [session, ...publicReads] = await Promise.all([
    client.json('/api/auth/session'),
    ...paths.map((path) => client.json(path))
  ]);
  assert(session.response.status === 200, 'concurrent session bootstrap failed');
  publicReads.forEach(({ response }, index) => {
    assert(response.status === 200, `${paths[index]} returned ${response.status}`);
    assert(!response.headers.has('set-cookie'), `${paths[index]} replaced the session cookie`);
  });
  const protectedResponse = await client.json('/api/payments/order-status', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.body.csrfToken },
    body: JSON.stringify({ orderNumber: 'BE-20261004-ABCDEF1234' })
  });
  assert(protectedResponse.response.status === 401, 'the bootstrap CSRF token did not survive concurrent public reads');
});

test('Stripe webhook payloads above the regular API limit reach signature validation', async () => {
  const client = new SessionClient();
  const payload = JSON.stringify({ type: 'test.large_payload', data: { object: { note: 'x'.repeat(70 * 1024) } } });
  const response = await client.request('/api/payments/webhook', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Stripe-Signature': 't=1,v1=invalid' },
    body: payload
  });
  assert(response.status === 400, `large webhook payload returned ${response.status}; expected signature validation after proxying`);
});

test('checkout recovery endpoints require an authenticated owner', async () => {
  const client = new SessionClient();
  const session = await client.json('/api/auth/session');
  const orderNumber = 'BE-20261004-ABCDEF1234';
  const getStatus = await client.json(`/api/payments/order-status?orderNumber=${encodeURIComponent(orderNumber)}`);
  assert(getStatus.response.status === 405, `state-reconciling status GET returned ${getStatus.response.status}; expected GET to be disabled`);
  assert(getStatus.response.headers.get('allow')?.includes('POST'), 'status GET does not advertise its CSRF-protected replacement');
  const status = await client.json('/api/payments/order-status', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.body.csrfToken },
    body: JSON.stringify({ orderNumber })
  });
  assert(status.response.status === 401, `anonymous order status returned ${status.response.status}`);
  const noCsrfStatus = await client.json('/api/payments/session-status', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ sessionId: 'cs_test_not-owned' })
  });
  assert(noCsrfStatus.response.status === 403, `status POST without CSRF returned ${noCsrfStatus.response.status}`);
  const resume = await client.json('/api/payments/resume-checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.body.csrfToken },
    body: JSON.stringify({ orderNumber })
  });
  assert(resume.response.status === 401, `anonymous checkout resume returned ${resume.response.status}`);
  const cancellation = await client.json('/api/payments/cancel-checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.body.csrfToken },
    body: JSON.stringify({ orderNumber })
  });
  assert(cancellation.response.status === 401, `anonymous checkout cancellation returned ${cancellation.response.status}`);
});

test('withdrawal submission requires an authenticated owner and admin actions require an administrator', async () => {
  const client = new SessionClient();
  const session = await client.json('/api/auth/session');
  const csrfToken = session.body.csrfToken;
  const submission = await client.json('/api/orders/withdrawal', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ orderNumber: 'BE-20261004-ABCDEF1234', type: 'withdrawal' })
  });
  assert(submission.response.status === 401, `anonymous withdrawal submission returned ${submission.response.status}`);

  const adminQueue = await client.json('/api/admin/withdrawals');
  assert(adminQueue.response.status === 401, `anonymous withdrawal queue returned ${adminQueue.response.status}`);
  const contactInbox = await client.json('/api/admin/contact-messages');
  assert(contactInbox.response.status === 401, `anonymous contact inbox returned ${contactInbox.response.status}`);
  const auditLog = await client.json('/api/admin/audit-log');
  assert(auditLog.response.status === 401, `anonymous audit log returned ${auditLog.response.status}`);
  const disputes = await client.json('/api/admin/payment-disputes');
  assert(disputes.response.status === 401, `anonymous dispute queue returned ${disputes.response.status}`);
  const paymentNotifications = await client.json('/api/admin/payment-notifications');
  assert(paymentNotifications.response.status === 401, `anonymous payment notification queue returned ${paymentNotifications.response.status}`);
  const externalRefunds = await client.json('/api/admin/refund-reconciliation');
  assert(externalRefunds.response.status === 401, 'anonymous external refund queue was not protected');
  const externalResolution = await client.json('/api/admin/refund-reconciliation/resolve', {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ refundId: 're_test', action: 'record', reason: 'Unauthorized external refund review' })
  });
  assert(externalResolution.response.status === 401, 'anonymous external refund resolution was not protected');
  const partialRefund = await client.json('/api/admin/orders/refund', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ orderNumber: 'BE-20261004-ABCDEF1234', amountCents: 50, reason: 'Customer service adjustment' })
  });
  assert(partialRefund.response.status === 401, `anonymous partial refund returned ${partialRefund.response.status}`);
  const adminAction = await client.json('/api/admin/withdrawals/action', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ requestId: 1, action: 'review' })
  });
  assert(adminAction.response.status === 401, `anonymous withdrawal action returned ${adminAction.response.status}`);
});

test('catalog and shipping estimate use server-owned products and strict input', async () => {
  const client = new SessionClient();
  const session = await client.json('/api/auth/session');
  const csrfToken = session.body.csrfToken;
  const catalog = await client.json('/api/products?limit=1&page=1');
  assert(catalog.response.status === 200, 'catalog failed');
  const product = catalog.body?.data?.[0];
  assert(Number.isInteger(product?.id), 'catalog did not return a product');
  const reviews = await client.json(`/api/product-reviews?productId=${product.id}&page=1&limit=1`);
  assert(reviews.response.status === 200, `product reviews returned ${reviews.response.status}`);
  assert(reviews.body?.page === 1 && reviews.body?.limit === 1, 'product review pagination metadata is invalid');
  assert(Number.isInteger(reviews.body?.total) && typeof reviews.body?.hasMore === 'boolean', 'product review total/hasMore metadata is invalid');
  const badReviewPage = await client.json(`/api/product-reviews?productId=${product.id}&page=0`);
  assert(badReviewPage.response.status === 422, `invalid product reviews page returned ${badReviewPage.response.status}`);
  assert(catalog.body?.meta?.currencyRateSource === 'ecb-daily-reference', 'catalog currency source is not identified');
  assert(catalog.body?.meta?.currencyRateType === 'daily_reference', 'catalog does not distinguish a reference rate from live FX');
  assert(['current', 'recent_cache_provider_unavailable', 'unavailable'].includes(catalog.body?.meta?.currencyRateStatus), 'currency rate monitoring status is missing');
  if (catalog.body?.meta?.currencyRateStatus === 'unavailable') {
    assert(catalog.body?.meta?.currencyRates?.EUR === null && catalog.body?.meta?.currencyRateToken === null, 'unavailable FX must not expose a fallback conversion');
  } else {
    assert(Number.isFinite(catalog.body?.meta?.currencyRates?.EUR) && catalog.body?.meta?.currencyRateToken?.length === 64, 'current FX reference or checkout token is invalid');
  }

  const quote = await client.json('/api/shipping/quotes', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ country: 'BE', postalCode: '1000', items: [{ productId: product.id, quantity: 1 }] })
  });
  assert(quote.response.status === 200, `shipping quote returned ${quote.response.status}`);
  assert(quote.body?.carrier === 'bpost', 'estimated carrier reference is invalid');
  assert(quote.body?.quoteType === 'estimate', 'shipping response is not marked as an estimate');
  assert(quote.body?.liveCarrierQuote === false, 'shipping response falsely claims a live carrier quote');
  assert(quote.body?.contractualRate === false, 'shipping response falsely claims a contract rate');
  assert(quote.body?.labelCreationAvailable === false, 'shipping response falsely claims label creation');
  assert(quote.body?.trackingAvailable === false, 'shipping response falsely claims tracking');
  assert(
    quote.body?.source?.startsWith('bpost-public-business-2026-') || quote.body?.source === 'payment-test-free-shipping',
    'shipping source is not identified as an estimate or isolated payment-test override'
  );
  assert(quote.body?.tariffReviewedAt === '2026-10-04', 'shipping tariff review date is missing');
  assert(quote.body?.tariffValidThrough === '2026-12-31', 'shipping tariff review deadline is missing');
  assert(quote.body?.tariffVersion === 'bpost-public-business-online-2026-reviewed-2026-10-04-v1', 'shipping tariff version is missing');
  assert(quote.body?.quotes?.length >= 1, 'shipping estimate has no methods');
  assert(!('tracked' in quote.body.quotes[0]), 'an estimate falsely claims tracking');
  assert(quote.body?.parcel?.weightGrams === product.shippingWeightGrams + 250, 'parcel weight was not recalculated');

  const unexpected = await client.json('/api/shipping/quotes', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ country: 'BE', postalCode: '1000', items: [{ productId: product.id, quantity: 1, price: 0.01 }] })
  });
  assert(unexpected.response.status === 422, `unexpected cart field returned ${unexpected.response.status}`);
});

test('CORS allowlist and role authorization are enforced', async () => {
  const foreign = new SessionClient();
  const cors = await foreign.json('/api/products', { headers: { Origin: 'https://attacker.invalid' } });
  assert(cors.response.status === 403, `foreign origin returned ${cors.response.status}`);
  assert(!cors.response.headers.get('access-control-allow-origin'), 'foreign origin was reflected');

  const client = new SessionClient();
  const session = await client.json('/api/auth/session');
  const demo = await client.json('/api/auth/demo-accounts');
  if (!demo.body?.enabled) {
    assert(process.env.REQUIRE_DEMO_AUTH_TESTS !== 'true', 'required CI demo authentication tests are disabled');
    return;
  }
  const login = await client.json('/api/auth/demo-login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.body.csrfToken },
    body: JSON.stringify({ role: 'customer' })
  });
  assert(login.response.status === 200, `demo login returned ${login.response.status}`);
  const accountOrders = await client.json('/api/orders?page=1&limit=10');
  assert(accountOrders.response.status === 200, `customer orders returned ${accountOrders.response.status}`);
  assert(Array.isArray(accountOrders.body?.data), 'customer orders payload is invalid');
  assert(accountOrders.body?.page === 1 && accountOrders.body?.limit === 10, 'customer orders pagination metadata is invalid');
  assert(Number.isInteger(accountOrders.body?.total) && typeof accountOrders.body?.hasMore === 'boolean', 'customer orders total/hasMore metadata is invalid');
  const olderOrders = await client.json('/api/orders?page=2&limit=10');
  assert(olderOrders.response.status === 200 && olderOrders.body?.page === 2, `older customer orders returned ${olderOrders.response.status}`);
  const badOrderPage = await client.json('/api/orders?page=0');
  assert(badOrderPage.response.status === 422, `invalid customer orders page returned ${badOrderPage.response.status}`);
  const admin = await client.json('/api/admin/overview');
  assert(admin.response.status === 403, `customer admin access returned ${admin.response.status}`);
  const contactInbox = await client.json('/api/admin/contact-messages');
  assert(contactInbox.response.status === 403, `customer contact inbox access returned ${contactInbox.response.status}`);
  const auditLog = await client.json('/api/admin/audit-log');
  assert(auditLog.response.status === 403, `customer audit log access returned ${auditLog.response.status}`);
  const paymentNotifications = await client.json('/api/admin/payment-notifications');
  assert(paymentNotifications.response.status === 403, `customer payment notification access returned ${paymentNotifications.response.status}`);
  const externalRefunds = await client.json('/api/admin/refund-reconciliation');
  assert(externalRefunds.response.status === 403, 'customer external refund queue was not forbidden');
  const afterLogin = await client.json('/api/auth/session');
  const externalResolution = await client.json('/api/admin/refund-reconciliation/resolve', {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': afterLogin.body.csrfToken },
    body: JSON.stringify({ refundId: 're_test', action: 'record', reason: 'Unauthorized external refund review' })
  });
  assert(externalResolution.response.status === 403, 'customer external refund resolution was not forbidden');
  const userMutation = await client.json('/api/admin/users', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': afterLogin.body.csrfToken },
    body: JSON.stringify({ id: 2147483647, name: 'Audit Test', email: 'audit-test@example.invalid', role: 'customer', status: 'active', emailVerified: true })
  });
  assert(userMutation.response.status === 403, 'customer admin user mutation was not forbidden');
});

test('administrator can load orders for manual shipping follow-up', async () => {
  const client = new SessionClient();
  const session = await client.json('/api/auth/session');
  const demo = await client.json('/api/auth/demo-accounts');
  if (!demo.body?.enabled) {
    assert(process.env.REQUIRE_DEMO_AUTH_TESTS !== 'true', 'required CI demo authentication tests are disabled');
    return;
  }

  const login = await client.json('/api/auth/demo-login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.body.csrfToken },
    body: JSON.stringify({ role: 'admin' })
  });
  assert(login.response.status === 200, `admin demo login returned ${login.response.status}`);
  const overview = await client.json('/api/admin/overview?page=1&limit=1');
  assert(overview.response.status === 200, `admin overview returned ${overview.response.status}`);
  assert(overview.body?.page === 1 && overview.body?.limit === 1, 'admin overview pagination parameters are missing');
  assert(overview.body?.users?.length <= 1 && overview.body?.products?.length <= 1 && overview.body?.reviews?.length <= 1, 'admin overview exceeded the requested page size');
  assert(Number.isInteger(overview.body?.usersTotal) && Number.isInteger(overview.body?.productsTotal) && Number.isInteger(overview.body?.reviewsTotal), 'admin overview totals are missing');
  assert(typeof overview.body?.usersHasMore === 'boolean' && typeof overview.body?.productsHasMore === 'boolean' && typeof overview.body?.reviewsHasMore === 'boolean', 'admin overview continuation metadata is missing');
  const nextOverviewPage = await client.json('/api/admin/overview?page=2&limit=1');
  assert(nextOverviewPage.response.status === 200 && nextOverviewPage.body?.page === 2, 'admin overview could not load a later page');
  const sellers = await client.json('/api/admin/sellers?page=1&limit=1');
  assert(sellers.response.status === 200 && Array.isArray(sellers.body?.data), `admin seller list returned ${sellers.response.status}`);
  assert(sellers.body?.page === 1 && sellers.body?.limit === 1, 'admin seller pagination parameters are missing');
  assert(Number.isInteger(sellers.body?.total) && typeof sellers.body?.hasMore === 'boolean', 'admin seller pagination metadata is missing');
  const nextSellers = await client.json('/api/admin/sellers?page=2&limit=1');
  assert(nextSellers.response.status === 200 && nextSellers.body?.page === 2, 'admin seller list could not load a later page');
  const orders = await client.json('/api/admin/orders');
  assert(orders.response.status === 200, `admin orders returned ${orders.response.status}`);
  assert(Array.isArray(orders.body?.data), 'admin orders payload is invalid');
  const externalRefunds = await client.json('/api/admin/refund-reconciliation?page=1&limit=1');
  assert(externalRefunds.response.status === 200 && Array.isArray(externalRefunds.body?.data), 'admin external refund queue is invalid');
  assert(externalRefunds.body?.page === 1 && externalRefunds.body?.limit === 1 && Number.isInteger(externalRefunds.body?.total) && typeof externalRefunds.body?.hasMore === 'boolean', 'external refund queue pagination metadata is invalid');
  const invalidExternalPage = await client.json('/api/admin/refund-reconciliation?page=0');
  assert(invalidExternalPage.response.status === 422, 'external refund queue accepted an invalid page');
  assert(orders.body?.page === 1 && orders.body?.limit <= 100, 'admin order pagination metadata is invalid');
  assert(typeof orders.body?.hasMore === 'boolean' && Number.isInteger(orders.body?.total), 'admin order pagination is missing its total/continuation fields');
  const disputes = await client.json('/api/admin/payment-disputes?page=1&limit=1');
  assert(disputes.response.status === 200, `admin payment disputes returned ${disputes.response.status}`);
  assert(Array.isArray(disputes.body?.data), 'admin payment disputes payload is invalid');
  assert(disputes.body?.page === 1 && disputes.body?.limit === 1 && Number.isInteger(disputes.body?.total) && typeof disputes.body?.hasMore === 'boolean', 'admin dispute pagination metadata is invalid');
  const nextDisputes = await client.json('/api/admin/payment-disputes?page=2&limit=1');
  assert(nextDisputes.response.status === 200 && nextDisputes.body?.page === 2, 'admin disputes could not load a later page');
  const paymentNotifications = await client.json('/api/admin/payment-notifications?page=1&limit=1');
  assert(paymentNotifications.response.status === 200, `admin payment notification queue returned ${paymentNotifications.response.status}`);
  assert(Array.isArray(paymentNotifications.body?.data), 'admin payment notification queue payload is invalid');
  assert(paymentNotifications.body?.page === 1 && paymentNotifications.body?.limit === 1, 'admin payment notification pagination parameters are missing');
  assert(Number.isInteger(paymentNotifications.body?.total) && typeof paymentNotifications.body?.hasMore === 'boolean', 'admin payment notification pagination metadata is missing');
  const withdrawals = await client.json('/api/admin/withdrawals?page=1&limit=1');
  assert(withdrawals.response.status === 200, `admin withdrawal queue returned ${withdrawals.response.status}`);
  assert(Array.isArray(withdrawals.body?.data), 'admin withdrawal queue payload is invalid');
  assert(withdrawals.body?.page === 1 && withdrawals.body?.limit === 1 && Number.isInteger(withdrawals.body?.total) && typeof withdrawals.body?.hasMore === 'boolean', 'admin withdrawal pagination metadata is invalid');
  const nextWithdrawals = await client.json('/api/admin/withdrawals?page=2&limit=1');
  assert(nextWithdrawals.response.status === 200 && nextWithdrawals.body?.page === 2, 'admin withdrawal queue could not load a later page');
  const contactMessages = await client.json('/api/admin/contact-messages?page=1&limit=25');
  assert(contactMessages.response.status === 200, `admin contact inbox returned ${contactMessages.response.status}`);
  assert(Array.isArray(contactMessages.body?.data), 'admin contact inbox payload is invalid');
  assert(contactMessages.body?.page === 1 && contactMessages.body?.limit === 25, 'admin contact inbox pagination is invalid');
  assert(Number.isInteger(contactMessages.body?.total) && typeof contactMessages.body?.hasMore === 'boolean', 'admin contact inbox pagination metadata is missing');
  const auditLog = await client.json('/api/admin/audit-log?page=1&limit=20');
  assert(auditLog.response.status === 200, `admin audit log returned ${auditLog.response.status}`);
  assert(Array.isArray(auditLog.body?.data) && auditLog.body?.page === 1 && auditLog.body?.limit === 20, 'admin audit log pagination is invalid');
  assert(Number.isInteger(auditLog.body?.total) && typeof auditLog.body?.hasMore === 'boolean', 'admin audit log metadata is missing');
  const afterLogin = await client.json('/api/auth/session');
  const missingAccount = await client.json('/api/admin/users', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': afterLogin.body.csrfToken },
    body: JSON.stringify({ id: 2147483647, name: 'Audit Test', email: 'audit-test@example.invalid', role: 'customer', status: 'active', emailVerified: true })
  });
  assert(missingAccount.response.status === 404, `non-existent admin target returned ${missingAccount.response.status}; admin membership guard may have failed`);
});

let failures = 0;
for (const { name, operation } of tests) {
  try {
    await operation();
    console.log(`PASS  ${name}`);
  } catch (error) {
    failures++;
    console.error(`FAIL  ${name}: ${error instanceof Error ? error.message : String(error)}`);
  }
}

console.log(`\n${tests.length - failures}/${tests.length} API integration tests passed against ${baseUrl}.`);
process.exitCode = failures === 0 ? 0 : 1;
