import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap } from '@angular/router';
import { of } from 'rxjs';

import { StorefrontComponent } from './app.component';
import { AuthService } from './core/auth.service';
import { CatalogService } from './core/catalog.service';
import { CommerceService } from './core/commerce.service';
import { StoreService } from './core/store.service';
import { AuthUser, OrderSummary, Product, ShippingQuoteResponse } from './storefront.models';

const verifiedCustomer: AuthUser = {
  id: 7, name: 'Ana', email: 'ana@example.com', role: 'customer', emailVerifiedAt: '2026-10-01'
};

function prepareValidCheckout(component: StorefrontComponent, addProduct = true): void {
  component.auth.replaceUser(verifiedCustomer);
  if (addProduct) component.store.add(product);
  component.checkoutForm = {
    name: verifiedCustomer.name, email: verifiedCustomer.email, address: 'Rue du Marché aux Herbes 18',
    city: 'Brussels', postalCode: '1000', region: 'Brussels-Capital', country: 'BE', delivery: 'bpost_home'
  };
  component.selectedShippingQuote = {
    id: 'bpost_home', label: 'Home delivery', amount: 0, amountCents: 0, currency: 'EUR',
    estimatedDays: '1-2 business days', description: 'Estimate only', kind: 'home'
  };
  component.shippingQuoteResponse = { tariffVersion: 'bpost-public-2026-v1' } as ShippingQuoteResponse;
  component.shippingQuoteRequestKey = component.currentShippingQuoteKey();
}

function expectPaymentStatusPost(http: HttpTestingController, url: string, body: object) {
  const request = http.expectOne(url);
  expect(request.request.method).toBe('POST');
  expect(request.request.headers.get('X-CSRF-Token')).toBe('csrf-shell-test');
  expect(request.request.body).toEqual(body);
  return request;
}

const product: Product = {
  id: 10,
  slug: 'monstera',
  name: 'Monstera',
  subtitle: 'Vaso de 18 cm',
  origin: null,
  dimensions: null,
  materials: null,
  careInstructions: null,
  category: 'plants',
  price: 129.9,
  shippingWeightGrams: 1500,
  stock: 2,
  sprite: 1,
  badge: null,
  seller: 'Botanical Essence',
  marketplace: false,
  averageRating: 0,
  reviewCount: 0,
  currency: 'USD'
};

function bootStorefront(page: string = 'home', queryParams: Record<string, string> = {}) {
  window.localStorage.clear();
  window.sessionStorage.clear();
  const router = {
    navigate: vi.fn().mockResolvedValue(true),
    navigateByUrl: vi.fn().mockResolvedValue(true)
  };
  TestBed.configureTestingModule({
    imports: [StorefrontComponent],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      {
        provide: ActivatedRoute,
        useValue: {
          data: of({ page }),
          paramMap: of(convertToParamMap({})),
          queryParamMap: of(convertToParamMap(queryParams)),
          fragment: of(null)
        }
      },
      { provide: Router, useValue: router }
    ]
  });

  const fixture = TestBed.createComponent(StorefrontComponent);
  const http = TestBed.inject(HttpTestingController);
  http.expectOne('/api/auth/session').flush({ user: null, csrfToken: 'csrf-shell-test' });
  http.expectOne('/api/auth/demo-accounts').flush({ enabled: false, accounts: [] });
  http.expectOne('/api/products?page=1&limit=100').flush({
    data: [product],
    meta: {
      page: 1, limit: 100, total: 1, pages: 1, baseCurrency: 'USD', currencyRates: { USD: 1, EUR: 0.89 },
      currencyRateDate: '2026-10-02', currencyRateSource: 'ecb-daily-reference', currencyRateType: 'daily_reference',
      currencyRateToken: 'c'.repeat(64), currencyRateStatus: 'current', currencyRateCheckedAt: '2026-10-04T12:00:00Z'
    }
  });
  fixture.detectChanges();
  return { fixture, http, router };
}

describe('StorefrontComponent', () => {
  it('clears checkout contact and provider state on sign-out while preserving the prior account’s recovery keys', () => {
    const { fixture, http, router } = bootStorefront('account');
    const component = fixture.componentInstance;
    component.auth.replaceUser(verifiedCustomer);
    component.checkoutForm = {
      name: verifiedCustomer.name, email: verifiedCustomer.email, address: 'Rue du Marché aux Herbes 18',
      city: 'Brussels', postalCode: '1000', region: 'Brussels-Capital', country: 'BE', delivery: 'bpost_home'
    };
    component.checkoutIdempotencyKey = 'account-7-recovery-key';
    component.writeCheckoutStorage('key', component.checkoutIdempotencyKey);
    component.rememberPendingCheckout('BE-20261005-PRIVATE1234');
    component.pendingCheckoutOrder = {
      number: 'BE-20261005-PRIVATE1234', status: 'awaiting_payment', paymentStatus: 'pending',
      subtotal: 25, shipping: 0, total: 25, currency: 'EUR'
    };
    component.checkoutResumeUrl = 'https://checkout.stripe.com/c/pay/private-session';
    component.checkoutCanCancel = true;
    component.profileForm = { name: 'Ana', bio: 'Private profile note', city: 'Brussels', region: 'Brussels-Capital', country: 'Belgium' };
    component.profileSuccess = 'Saved privately';
    component.resetForm = { password: 'private-password', confirmPassword: 'private-password' };
    component.verificationPassword = 'private-verification-password';
    component.newsletterEmail = 'ana@example.com';
    component.toast = 'Private account notification';
    component.adminUsers = [{
      id: 99, name: 'Private admin record', email: 'private@example.com', role: 'customer', status: 'active',
      isDemo: false, emailVerified: true, emailVerifiedAt: '2026-10-01', createdAt: '2026-10-01T12:00:00Z'
    }];

    component.logout();
    http.expectOne('/api/auth/logout').flush({ message: 'Signed out.', csrfToken: 'csrf-after-logout' });

    expect(component.user).toBeNull();
    expect(component.checkoutForm).toEqual({ name: '', email: '', address: '', city: '', postalCode: '', region: '', country: 'BE', delivery: '' });
    expect(component.pendingCheckoutOrder).toBeNull();
    expect(component.pendingCheckoutOrderNumber).toBe('');
    expect(component.checkoutResumeUrl).toBeNull();
    expect(component.checkoutPending).toBe(false);
    expect(component.checkoutIdempotencyKey).toBe('');
    expect(component.profileForm).toEqual({ name: '', bio: '', city: '', region: '', country: 'Belgium' });
    expect(component.profileSuccess).toBe('');
    expect(component.resetForm).toEqual({ password: '', confirmPassword: '' });
    expect(component.verificationPassword).toBe('');
    expect(component.newsletterEmail).toBe('');
    expect(component.toast).toBe('');
    expect(component.adminUsers).toEqual([]);
    expect(window.sessionStorage.getItem('botanical-checkout-key-7')).toBe('account-7-recovery-key');
    expect(window.sessionStorage.getItem('botanical-checkout-order-7')).toBe('BE-20261005-PRIVATE1234');
    expect(router.navigateByUrl).toHaveBeenCalledWith('/login');
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it('cancels old account profile and order requests before a new login can reuse their responses', () => {
    const { fixture, http } = bootStorefront('account');
    const component = fixture.componentInstance;
    component.auth.replaceUser(verifiedCustomer);
    component.loadProfile();
    component.loadOrders();
    const oldProfile = http.expectOne('/api/account/profile');
    const oldOrders = http.expectOne('/api/orders?page=1&limit=50');

    component.loginForm = { email: 'leo@example.com', password: 'Strong-pass1!' };
    component.submitLogin();
    http.expectOne('/api/auth/login').flush({
      message: 'Authenticated.',
      csrfToken: 'csrf-leo',
      user: { id: 8, name: 'Léo', email: 'leo@example.com', role: 'customer', emailVerifiedAt: '2026-10-01' }
    });

    expect(oldProfile.cancelled).toBe(true);
    expect(oldOrders.cancelled).toBe(true);
    expect(component.user?.id).toBe(8);
    expect(component.accountProfile).toBeNull();
    expect(component.orders).toEqual([]);
    expect(component.profileLoading).toBe(true);
    const newProfile = http.expectOne('/api/account/profile');
    const newOrders = http.expectOne('/api/orders?page=1&limit=50');
    const newSellerDashboard = http.expectOne('/api/seller/dashboard');
    newProfile.flush({ profile: { name: 'Léo', email: 'leo@example.com', bio: null, city: 'Liège', region: 'Wallonia', country: 'Belgium' } });
    newOrders.flush({ data: [], page: 1, limit: 50, total: 0, hasMore: false });
    newSellerDashboard.flush({ store: null, products: [] });
    expect(component.accountProfile?.name).toBe('Léo');
    expect(component.profileLoading).toBe(false);
    expect(component.ordersLoading).toBe(false);

    http.verify();
    fixture.destroy();
  });

  it('does not abort an account write on identity change, but ignores its stale response', () => {
    const { fixture, http } = bootStorefront('account');
    const component = fixture.componentInstance;
    component.auth.replaceUser(verifiedCustomer);
    component.profileForm = { name: 'Ana edited', bio: 'Old account note', city: 'Brussels', region: 'Brussels-Capital', country: 'Belgium' };
    component.saveProfile();
    const oldProfileSave = http.expectOne('/api/account/profile');

    component.loginForm = { email: 'leo@example.com', password: 'Strong-pass1!' };
    component.submitLogin();
    http.expectOne('/api/auth/login').flush({
      message: 'Authenticated.',
      csrfToken: 'csrf-leo',
      user: { id: 8, name: 'Léo', email: 'leo@example.com', role: 'customer', emailVerifiedAt: '2026-10-01' }
    });

    expect(oldProfileSave.cancelled).toBe(false);
    oldProfileSave.flush({
      message: 'Saved.',
      profile: { name: 'Ana edited', email: verifiedCustomer.email, bio: 'Old account note', city: 'Brussels', region: 'Brussels-Capital', country: 'Belgium' },
      user: verifiedCustomer
    });

    expect(component.user?.id).toBe(8);
    expect(component.accountProfile).toBeNull();
    expect(component.profileForm.name).toBe('');
    const newProfile = http.expectOne('/api/account/profile');
    const newOrders = http.expectOne('/api/orders?page=1&limit=50');
    const newSellerDashboard = http.expectOne('/api/seller/dashboard');
    newProfile.flush({ profile: { name: 'Léo', email: 'leo@example.com', bio: null, city: 'Liège', region: 'Wallonia', country: 'Belgium' } });
    newOrders.flush({ data: [], page: 1, limit: 50, total: 0, hasMore: false });
    newSellerDashboard.flush({ store: null, products: [] });

    http.verify();
    fixture.destroy();
  });

  it('ignores an old account’s in-flight payment status after checkout state is reset for another account', () => {
    const { fixture, http } = bootStorefront('checkout');
    const component = fixture.componentInstance;
    component.auth.replaceUser(verifiedCustomer);
    const orderNumber = 'BE-20261005-PRIVATE5678';
    component.checkoutIdempotencyKey = 'account-7-pending-key';
    component.writeCheckoutStorage('key', component.checkoutIdempotencyKey);
    component.loadCheckoutOrderStatus(orderNumber);
    const oldRequest = expectPaymentStatusPost(http, '/api/payments/order-status', { orderNumber });
    expect(window.sessionStorage.getItem('botanical-checkout-order-7')).toBe(orderNumber);

    component.auth.markSignedOut();
    component.resetCheckoutForAnonymousUser();
    component.auth.replaceUser({ id: 8, name: 'Léo', email: 'leo@example.com', role: 'customer', emailVerifiedAt: '2026-10-01' });
    const revisionAfterSwitch = component.checkoutStateRevision;
    oldRequest.flush({
      order: {
        number: orderNumber, status: 'awaiting_payment', paymentStatus: 'pending',
        subtotal: 25, shipping: 0, total: 25, currency: 'EUR'
      },
      paymentStatus: 'pending', checkoutUrl: 'https://checkout.stripe.com/c/pay/private-session',
      canResume: true, canRetry: false, canCancel: true, providerStatus: 'open'
    });

    expect(component.user?.id).toBe(8);
    expect(component.checkoutStateRevision).toBe(revisionAfterSwitch);
    expect(component.checkoutForm.name).toBe('');
    expect(component.checkoutForm.address).toBe('');
    expect(component.checkoutPending).toBe(false);
    expect(component.pendingCheckoutOrderNumber).toBe('');
    expect(component.pendingCheckoutOrder).toBeNull();
    expect(component.checkoutResumeUrl).toBeNull();
    expect(component.checkoutCanCancel).toBe(false);
    expect(component.checkoutIdempotencyKey).toBe('');
    expect(window.sessionStorage.getItem('botanical-checkout-key-7')).toBe('account-7-pending-key');
    expect(window.sessionStorage.getItem('botanical-checkout-order-7')).toBe(orderNumber);
    expect(window.sessionStorage.getItem('botanical-checkout-key-8')).toBeNull();
    expect(window.sessionStorage.getItem('botanical-checkout-order-8')).toBeNull();
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it('ignores an in-flight delivery quote after checkout address data is cleared', () => {
    const { fixture, http } = bootStorefront('checkout');
    const component = fixture.componentInstance;
    component.store.add(product);
    component.checkoutForm = {
      name: 'Ana Silva', email: 'ana@example.com', address: 'Rue du Marché aux Herbes 18',
      city: 'Brussels', postalCode: '1000', region: 'Brussels-Capital', country: 'BE', delivery: ''
    };

    component.calculateShipping();
    const quoteRequest = http.expectOne('/api/shipping/quotes');
    component.resetCheckoutForAnonymousUser();
    quoteRequest.flush({
      tariffVersion: 'bpost-public-2026-v1', tariffReviewedAt: '2026-01-01',
      tariffValidThrough: '2026-12-31', tariffReference: 'https://www.bpost.be/',
      parcel: { weightKilograms: 1.75 },
      quotes: [{ id: 'bpost_home', label: 'Home delivery', amount: 5, amountCents: 500, currency: 'EUR', estimatedDays: '1-2 days', description: 'Delivery', kind: 'home' }]
    } as ShippingQuoteResponse);

    expect(component.checkoutForm.postalCode).toBe('');
    expect(component.shippingQuoteResponse).toBeNull();
    expect(component.shippingQuotes).toEqual([]);
    expect(component.selectedShippingQuote).toBeNull();
    expect(component.shippingQuoteLoading).toBe(false);
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it('fails closed for a signed-in checkout status error but clears private checkout state on a 401', () => {
    const { fixture, http } = bootStorefront('checkout');
    const component = fixture.componentInstance;
    component.auth.replaceUser(verifiedCustomer);
    component.checkoutIdempotencyKey = 'keep-for-recovery';
    component.writeCheckoutStorage('key', component.checkoutIdempotencyKey);
    component.loadCheckoutOrderStatus('BE-20261005-UNAUTHORIZED1');
    expectPaymentStatusPost(http, '/api/payments/order-status', { orderNumber: 'BE-20261005-UNAUTHORIZED1' }).flush(
      { error: 'Authentication required.' }, { status: 401, statusText: 'Unauthorized' }
    );

    expect(component.user).toBeNull();
    expect(component.checkoutPending).toBe(false);
    expect(component.checkoutForm.email).toBe('');
    expect(component.checkoutIdempotencyKey).toBe('');
    expect(window.sessionStorage.getItem('botanical-checkout-key-7')).toBe('keep-for-recovery');
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it('shows the account email verification state instead of a fabricated membership year', () => {
    const { fixture } = bootStorefront('account');
    const component = fixture.componentInstance;
    component.auth.replaceUser(verifiedCustomer);
    fixture.detectChanges();

    const details = fixture.nativeElement.querySelector('.account-details') as HTMLElement;
    expect(details.textContent).toContain('EMAIL STATUS');
    expect(details.textContent).toContain('Verified');
    expect(details.textContent).not.toContain('MEMBER SINCE');
    expect(details.textContent).not.toContain('2026');

    component.auth.replaceUser({ ...verifiedCustomer, emailVerifiedAt: null });
    fixture.detectChanges();
    expect(details.textContent).toContain('Not verified');
    fixture.destroy();
  });

  it('keeps a pending provider return locked to the same order instead of starting another order', () => {
    const { fixture, http } = bootStorefront('checkout');
    const component = fixture.componentInstance;
    component.auth.replaceUser({ id: 7, name: 'Ana', email: 'ana@example.com', role: 'customer', emailVerifiedAt: '2026-10-01' });
    component.paymentReturnSessionId = 'cs_test_pending';
    component.verifyCheckoutReturn();

    const order: OrderSummary = {
      number: 'BE-20261005-ABCDEF1234', status: 'awaiting_payment', paymentStatus: 'pending',
      subtotal: 25, shipping: 0, total: 25, currency: 'EUR', testPayment: false
    };
    const providerStatus = expectPaymentStatusPost(http, '/api/payments/session-status', { sessionId: 'cs_test_pending' });
    providerStatus.flush({ order, paymentStatus: 'pending' });

    const orderStatus = expectPaymentStatusPost(http, '/api/payments/order-status', { orderNumber: order.number });
    orderStatus.flush({
      order, paymentStatus: 'pending', checkoutUrl: null, canResume: false, canRetry: false, canCancel: false,
      providerStatus: 'processing'
    });

    expect(component.checkoutPending).toBe(true);
    expect(component.pendingCheckoutOrderNumber).toBe(order.number);
    expect(component.checkoutCanRetry).toBe(false);
    expect(component.checkoutError).toContain('We will not create another order');
    expect(window.sessionStorage.getItem('botanical-checkout-order-7')).toBe(order.number);
    http.expectNone('/api/orders');
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it('reconciles a generic Stripe success return through the user-scoped saved order number', () => {
    const { fixture, http } = bootStorefront('checkout', { payment: 'success' });
    const component = fixture.componentInstance;
    const orderNumber = 'BE-20261006-RETURN12345';
    const order: OrderSummary = {
      number: orderNumber, status: 'paid', paymentStatus: 'paid',
      subtotal: 25, shipping: 0, total: 25, currency: 'EUR'
    };
    component.auth.replaceUser(verifiedCustomer);
    component.writeCheckoutStorage('order', orderNumber);
    component.syncAuthenticatedUser();

    expectPaymentStatusPost(http, '/api/payments/order-status', { orderNumber }).flush({
      order, paymentStatus: 'paid', checkoutUrl: null, canResume: false, canRetry: false, canCancel: false,
      providerStatus: 'complete'
    });

    expect(component.checkoutCompleted).toBe(true);
    expect(component.checkoutOrderNumber).toBe(orderNumber);
    http.expectNone('/api/payments/session-status');
    http.expectOne('/api/orders?page=1&limit=50').flush({ data: [], page: 1, limit: 50, total: 0, hasMore: false });
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it('removes legacy Stripe session and order identifiers from the return URL immediately', () => {
    const { fixture, http, router } = bootStorefront('checkout', {
      payment: 'cancelled', session_id: 'cs_test_legacyreturn123', order_number: 'BE-20261006-LEGACY12345'
    });
    const component = fixture.componentInstance;

    expect(router.navigate).toHaveBeenCalledWith([], expect.objectContaining({
      relativeTo: expect.anything(),
      queryParams: { payment: 'cancelled' },
      replaceUrl: true
    }));
    expect(component.paymentReturnSessionId).toBe('cs_test_legacyreturn123');
    expect(component.pendingCheckoutOrderNumber).toBe('BE-20261006-LEGACY12345');
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it('preserves the idempotency key and blocks retry when a cancelled order needs manual provider reconciliation', () => {
    const { fixture, http } = bootStorefront('checkout');
    const component = fixture.componentInstance;
    component.auth.replaceUser({ id: 8, name: 'Léo', email: 'leo@example.com', role: 'customer', emailVerifiedAt: '2026-10-01' });
    component.checkoutIdempotencyKey = 'same-order-idempotency-key';
    component.writeCheckoutStorage('key', component.checkoutIdempotencyKey);
    const order: OrderSummary = {
      number: 'BE-20261005-ABCDEF5678', status: 'cancelled', paymentStatus: 'pending',
      subtotal: 25, shipping: 0, total: 25, currency: 'EUR', testPayment: false
    };

    component.loadCheckoutOrderStatus(order.number);
    expectPaymentStatusPost(http, '/api/payments/order-status', { orderNumber: order.number }).flush({
      order, paymentStatus: 'pending', checkoutUrl: null, canResume: false, canRetry: false, canCancel: false,
      providerStatus: 'manual_reconciliation_required'
    });

    expect(component.checkoutPending).toBe(true);
    expect(component.checkoutCanRetry).toBe(false);
    expect(component.checkoutCanCancel).toBe(false);
    expect(component.checkoutIdempotencyKey).toBe('same-order-idempotency-key');
    expect(window.sessionStorage.getItem('botanical-checkout-key-8')).toBe('same-order-idempotency-key');
    expect(component.checkoutError).toContain('payment or refund still needs to be reconciled');
    http.expectNone('/api/orders');
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it.each([
    ['unverified account', (component: StorefrontComponent) => {
      component.auth.replaceUser({ ...verifiedCustomer, emailVerifiedAt: null });
    }, 'Confirm your account email'],
    ['invalid contact details', (component: StorefrontComponent) => { component.checkoutForm.name = ' '; }, 'Enter a valid full name and email address'],
    ['incomplete address', (component: StorefrontComponent) => { component.checkoutForm.city = ''; }, 'Complete the street, city, postal code and country'],
    ['missing shipping selection', (component: StorefrontComponent) => {
      component.selectedShippingQuote = null;
      component.checkoutForm.delivery = '';
    }, 'Calculate delivery before continuing'],
    ['stale shipping quote', (component: StorefrontComponent) => { component.shippingQuoteRequestKey = 'old-address|old-cart'; }, 'Recalculate delivery for the current address and cart'],
    ['unavailable exchange reference', (component: StorefrontComponent) => { component.catalog.currencyRateMetadata.set(null); }, 'Checkout is paused because the recent ECB exchange reference is unavailable'],
    ['missing tariff version', (component: StorefrontComponent) => { component.shippingQuoteResponse = null; }, 'Recalculate the shipping estimate before continuing'],
    ['euro-only method with USD', (component: StorefrontComponent) => {
      component.currentCurrency = 'USD';
      component.paymentPreviewMethod = 'bancontact';
    }, 'Bancontact and SEPA Direct Debit are charged in euros']
  ] as const)('refuses to create an order when checkout has %s', (_case, alter, expectedMessage) => {
    const { fixture, http } = bootStorefront('checkout');
    const component = fixture.componentInstance;
    prepareValidCheckout(component, false);
    alter(component);

    component.startPurchase();

    expect(component.checkoutError).toContain(expectedMessage);
    expect(component.paymentStarting).toBe(false);
    http.expectNone('/api/orders');
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it('sends one CSRF-protected order with the displayed currency, exchange reference and tariff version', () => {
    const { fixture, http } = bootStorefront('checkout');
    const component = fixture.componentInstance;
    prepareValidCheckout(component);
    const cartQuantity = component.cart[0].quantity;

    component.startPurchase();
    expect(component.paymentStarting).toBe(true);
    const request = http.expectOne('/api/orders');
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('X-CSRF-Token')).toBe('csrf-shell-test');
    expect(request.request.body).toMatchObject({
      currency: 'EUR', exchangeRateToken: 'c'.repeat(64), shippingTariffVersion: 'bpost-public-2026-v1',
      delivery: 'bpost_home', payment: 'bancontact', items: [{ productId: product.id, quantity: cartQuantity }]
    });
    const order: OrderSummary = {
      number: 'BE-20261005-LOCAL123456', status: 'paid', paymentStatus: 'paid', subtotal: 25,
      shipping: 0, total: 25, currency: 'EUR', testPayment: true
    };
    request.flush({ order, checkoutUrl: null, simulated: true, alreadyPaid: false });
    http.expectOne('/api/orders?page=1&limit=50').flush({ data: [order], page: 1, limit: 50, total: 1, hasMore: false });

    expect(component.checkoutCompleted).toBe(true);
    expect(component.checkoutIsSimulation).toBe(true);
    expect(component.checkoutOrderNumber).toBe(order.number);
    expect(component.cart).toEqual([]);
    expect(window.sessionStorage.getItem('botanical-checkout-key-7')).toBeNull();
    http.expectNone('/api/orders');
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it('recovers the existing order after checkout_in_progress and never posts a duplicate on retry', () => {
    const { fixture, http } = bootStorefront('checkout');
    const component = fixture.componentInstance;
    prepareValidCheckout(component);

    component.startPurchase();
    const createOrder = http.expectOne('/api/orders');
    const idempotencyKey = createOrder.request.body.idempotencyKey as string;
    expect(idempotencyKey).toBeTruthy();
    createOrder.flush(
      { error: 'Checkout already active.', code: 'checkout_in_progress', details: { orderNumber: 'BE-20261005-EXISTING1234' } },
      { status: 409, statusText: 'Conflict' }
    );

    const firstStatus = expectPaymentStatusPost(http, '/api/payments/order-status', { orderNumber: 'BE-20261005-EXISTING1234' });
    const pendingOrder: OrderSummary = {
      number: 'BE-20261005-EXISTING1234', status: 'awaiting_payment', paymentStatus: 'pending',
      subtotal: 25, shipping: 0, total: 25, currency: 'EUR'
    };
    firstStatus.flush({
      order: pendingOrder, paymentStatus: 'pending', checkoutUrl: 'https://checkout.stripe.com/c/pay/test',
      canResume: true, canRetry: false, canCancel: true, providerStatus: 'open'
    });
    expect(component.pendingCheckoutOrderNumber).toBe(pendingOrder.number);
    expect(component.checkoutCanRetry).toBe(false);
    expect(component.checkoutIdempotencyKey).toBe(idempotencyKey);

    component.startPurchase();
    const recoveredStatus = expectPaymentStatusPost(http, '/api/payments/order-status', { orderNumber: 'BE-20261005-EXISTING1234' });
    recoveredStatus.flush({
      order: pendingOrder, paymentStatus: 'pending', checkoutUrl: 'https://checkout.stripe.com/c/pay/test',
      canResume: true, canRetry: false, canCancel: true, providerStatus: 'open'
    });

    expect(component.checkoutPending).toBe(true);
    expect(window.sessionStorage.getItem('botanical-checkout-key-7')).toBe(idempotencyKey);
    expect(window.sessionStorage.getItem('botanical-checkout-order-7')).toBe(pendingOrder.number);
    http.expectNone('/api/orders');
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it('rechecks server state after an unconfirmed customer cancellation before restoring payment actions', () => {
    const { fixture, http } = bootStorefront('checkout');
    const component = fixture.componentInstance;
    component.auth.replaceUser(verifiedCustomer);
    const orderNumber = 'BE-20261005-CANCELCHECK1';
    component.pendingCheckoutOrderNumber = orderNumber;
    component.checkoutPending = true;
    component.checkoutCanCancel = true;
    component.checkoutResumeUrl = 'https://checkout.stripe.com/c/pay/stale-session';
    component.checkoutIdempotencyKey = 'cancel-checkout-key';
    component.writeCheckoutStorage('key', component.checkoutIdempotencyKey);

    component.cancelPendingCheckout();
    const cancellation = http.expectOne('/api/payments/cancel-checkout');
    expect(cancellation.request.headers.get('X-CSRF-Token')).toBe('csrf-shell-test');
    expect(cancellation.request.body).toEqual({ orderNumber });
    const pendingOrder: OrderSummary = {
      number: orderNumber, status: 'awaiting_payment', paymentStatus: 'pending',
      subtotal: 25, shipping: 0, total: 25, currency: 'EUR'
    };
    cancellation.flush({ order: pendingOrder, cancelled: false, alreadyPaid: false });

    const refreshedStatus = expectPaymentStatusPost(http, '/api/payments/order-status', { orderNumber });
    refreshedStatus.flush({
      order: pendingOrder, paymentStatus: 'pending', checkoutUrl: null,
      canResume: false, canRetry: false, canCancel: false, providerStatus: 'processing'
    });

    expect(component.checkoutPending).toBe(true);
    expect(component.checkoutResumeUrl).toBeNull();
    expect(component.checkoutCanCancel).toBe(false);
    expect(component.checkoutError).toContain('We will not create another order');
    expect(component.checkoutIdempotencyKey).toBe('cancel-checkout-key');
    expect(window.sessionStorage.getItem('botanical-checkout-key-7')).toBe('cancel-checkout-key');
    http.expectNone('/api/orders');
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it('fails closed and verifies payment again when customer cancellation times out', () => {
    const { fixture, http } = bootStorefront('checkout');
    const component = fixture.componentInstance;
    component.auth.replaceUser(verifiedCustomer);
    const orderNumber = 'BE-20261005-CANCELTIMEOUT1';
    component.pendingCheckoutOrderNumber = orderNumber;
    component.checkoutPending = true;
    component.checkoutCanCancel = true;
    component.checkoutResumeUrl = 'https://checkout.stripe.com/c/pay/stale-session';

    component.cancelPendingCheckout();
    http.expectOne('/api/payments/cancel-checkout').flush(
      { error: 'Gateway timeout.' },
      { status: 503, statusText: 'Unavailable' }
    );
    const refreshedStatus = expectPaymentStatusPost(http, '/api/payments/order-status', { orderNumber });
    refreshedStatus.flush(
      { error: 'Payment status unavailable.' },
      { status: 503, statusText: 'Unavailable' }
    );

    expect(component.checkoutPending).toBe(true);
    expect(component.checkoutResumeUrl).toBeNull();
    expect(component.checkoutCanCancel).toBe(false);
    expect(component.checkoutError).toContain('A new checkout is blocked');
    http.expectNone('/api/orders');
    http.verify();
    fixture.destroy();
  });

  it('reconciles cancellation against an already-paid order without presenting it as cancelled', () => {
    const { fixture, http } = bootStorefront('checkout');
    const component = fixture.componentInstance;
    component.auth.replaceUser(verifiedCustomer);
    component.store.add(product);
    component.pendingCheckoutOrderNumber = 'BE-20261005-PAIDCANCEL1234';
    component.checkoutPending = true;
    component.checkoutCanCancel = true;
    const paidOrder: OrderSummary = {
      number: 'BE-20261005-PAIDCANCEL1234', status: 'awaiting_fulfilment', paymentStatus: 'paid',
      subtotal: 25, shipping: 0, total: 25, currency: 'EUR', testPayment: false
    };

    component.cancelPendingCheckout();
    http.expectOne('/api/payments/cancel-checkout').flush({ order: paidOrder, cancelled: false, alreadyPaid: true });
    http.expectOne('/api/orders?page=1&limit=50').flush({ data: [paidOrder], page: 1, limit: 50, total: 1, hasMore: false });

    expect(component.checkoutCompleted).toBe(true);
    expect(component.checkoutOrderNumber).toBe(paidOrder.number);
    expect(component.checkoutPending).toBe(false);
    expect(component.checkoutError).toBe('');
    expect(component.cart).toEqual([]);
    expect(component.toast).toContain('This order was already paid');
    http.expectNone('/api/orders');
    http.verify();
    fixture.destroy();
    window.sessionStorage.clear();
  });

  it('renders labeled checkout fields and places the final action after the order summary', () => {
    window.localStorage.clear();
    window.localStorage.setItem('botanical-cart', JSON.stringify([{ id: product.id, quantity: 1 }]));
    const router = {
      navigate: vi.fn().mockResolvedValue(true),
      navigateByUrl: vi.fn().mockResolvedValue(true)
    };
    TestBed.configureTestingModule({
      imports: [StorefrontComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        {
          provide: ActivatedRoute,
          useValue: {
            data: of({ page: 'checkout' }),
            paramMap: of(convertToParamMap({})),
            queryParamMap: of(convertToParamMap({})),
            fragment: of(null)
          }
        },
        { provide: Router, useValue: router }
      ]
    });

    const fixture = TestBed.createComponent(StorefrontComponent);
    const http = TestBed.inject(HttpTestingController);
    http.expectOne('/api/auth/session').flush({ user: null, csrfToken: 'csrf-checkout' });
    http.expectOne('/api/auth/demo-accounts').flush({ enabled: false, accounts: [] });
    http.expectOne('/api/products?page=1&limit=100').flush({
      data: [product],
      meta: { page: 1, limit: 100, total: 1, pages: 1, baseCurrency: 'USD', currencyRates: { USD: 1, EUR: 0.89 }, currencyRateDate: '2026-10-02', currencyRateSource: 'ecb-daily-reference', currencyRateType: 'daily_reference', currencyRateToken: 'b'.repeat(64), currencyRateStatus: 'current', currencyRateCheckedAt: '2026-10-04T12:00:00Z' }
    });
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    const emptySummaryItems = fixture.componentInstance.checkoutSummaryItems;
    expect(fixture.componentInstance.checkoutSummaryItems).toBe(emptySummaryItems);
    fixture.componentInstance.store.add(product);
    fixture.componentInstance.currentCurrency = 'EUR';
    const populatedSummaryItems = fixture.componentInstance.checkoutSummaryItems;
    expect(populatedSummaryItems).not.toBe(emptySummaryItems);
    expect(populatedSummaryItems[0]).toMatchObject({ id: product.id, name: product.name, quantity: 2 });
    expect(fixture.componentInstance.checkoutSummaryItems).toBe(populatedSummaryItems);
    const rates = fixture.componentInstance.catalog.currencyRates();
    fixture.componentInstance.catalog.currencyRates.set({ ...rates, EUR: 0.55 });
    const repricedSummaryItems = fixture.componentInstance.checkoutSummaryItems;
    expect(repricedSummaryItems).not.toBe(populatedSummaryItems);
    expect(repricedSummaryItems[0].lineTotal).not.toBe(populatedSummaryItems[0].lineTotal);
    expect(fixture.componentInstance.checkoutSummaryItems).toBe(repricedSummaryItems);

    for (const name of ['checkoutName', 'checkoutEmail', 'address', 'postalCode', 'city', 'checkoutRegion', 'checkoutCountry']) {
      const field = element.querySelector<HTMLInputElement | HTMLSelectElement>(`[name="${name}"]`);
      expect(field?.labels?.length).toBeGreaterThan(0);
    }
    const summary = element.querySelector('.order-summary');
    const finalReview = element.querySelector('.purchase-final');
    expect(summary).not.toBeNull();
    expect(finalReview).not.toBeNull();
    expect(summary!.compareDocumentPosition(finalReview!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(element.querySelector('.checkout-page')?.lastElementChild).toBe(element.querySelector('app-checkout-final-review'));
    expect(element.querySelector('app-checkout-final-review')?.lastElementChild).toBe(finalReview);
    expect(element.querySelectorAll('input[name="securePayment"]')).toHaveLength(3);
    const contactForm = element.querySelector('app-checkout-details-form');
    const delivery = element.querySelector('app-checkout-delivery');
    const paymentChoices = element.querySelector('app-checkout-payment-methods');
    expect(contactForm).not.toBeNull();
    expect(delivery).not.toBeNull();
    expect(paymentChoices).not.toBeNull();
    expect(contactForm!.compareDocumentPosition(delivery!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(delivery!.compareDocumentPosition(paymentChoices!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(element.querySelectorAll('input[name="paymentPreview"]')).toHaveLength(0);
    expect(element.querySelector('.qr-placeholder, .disabled-payment-fields')).toBeNull();

    fixture.componentInstance.startPurchase();
    fixture.componentRef.changeDetectorRef.detectChanges();
    expect(fixture.componentInstance.checkoutError).toContain('Sign in and confirm your email');
    http.expectNone('/api/orders');
    http.verify();
    fixture.destroy();
    window.localStorage.clear();
  });

  it('boots the public storefront with server-backed session and catalog data', () => {
    const router = {
      navigate: vi.fn().mockResolvedValue(true),
      navigateByUrl: vi.fn().mockResolvedValue(true)
    };
    TestBed.configureTestingModule({
      imports: [StorefrontComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        {
          provide: ActivatedRoute,
          useValue: {
            data: of({ page: 'home' }),
            paramMap: of(convertToParamMap({})),
            queryParamMap: of(convertToParamMap({})),
            fragment: of(null)
          }
        },
        { provide: Router, useValue: router }
      ]
    });

    const fixture = TestBed.createComponent(StorefrontComponent);
    const http = TestBed.inject(HttpTestingController);
    http.expectOne('/api/auth/session').flush({ user: null, csrfToken: 'csrf-shell' });
    http.expectOne('/api/auth/demo-accounts').flush({ enabled: false, accounts: [] });
    http.expectOne('/api/products?page=1&limit=100').flush({
      data: [product],
      meta: { page: 1, limit: 100, total: 1, pages: 1, baseCurrency: 'USD', currencyRates: { USD: 1, EUR: 0.89 }, currencyRateDate: '2026-10-02', currencyRateSource: 'ecb-daily-reference', currencyRateType: 'daily_reference', currencyRateToken: 'a'.repeat(64), currencyRateStatus: 'current', currencyRateCheckedAt: '2026-10-04T12:00:00Z' }
    });
    fixture.detectChanges();

    expect(fixture.componentInstance.currentPage).toBe('home');
    expect(fixture.nativeElement.querySelector('#hero-title')?.textContent).toContain('Nature');
    expect(fixture.nativeElement.querySelectorAll('.product-card')).toHaveLength(1);
    const order: OrderSummary = {
      number: 'ORD-12345678', status: 'delivered', paymentStatus: 'partially_refunded',
      subtotal: 100, shipping: 0, total: 100, currency: 'EUR', refundedTotalCents: 2500
    };
    expect(fixture.componentInstance.canRequestWithdrawal(order)).toBe(true);
    expect(fixture.componentInstance.orderRefundRemaining(order)).toBe(75);
    expect(fixture.componentInstance.canRequestWithdrawal({ ...order, refundedTotalCents: 10_000 })).toBe(false);
    expect(fixture.componentInstance.canRequestWithdrawal({ ...order, status: 'cancelled' })).toBe(false);
    fixture.componentInstance.selectedProduct = product;
    fixture.componentInstance.loadProductReviews(product);
    const firstReviews = http.expectOne(`/api/product-reviews?productId=${product.id}&page=1&limit=20`);
    const review = {
      id: 1, rating: 5, comment: 'A useful review for the plant.', authorName: 'Verified customer',
      verifiedPurchase: true, createdAt: '2026-10-01T00:00:00Z', updatedAt: '2026-10-01T00:00:00Z'
    };
    firstReviews.flush({
      data: [review], summary: { average: 4.8, count: 21 }, page: 1, limit: 20,
      total: 21, hasMore: true, canReview: false, myReview: null
    });
    fixture.componentInstance.loadOlderProductReviews();
    const olderReviews = http.expectOne(`/api/product-reviews?productId=${product.id}&page=2&limit=20`);
    olderReviews.flush({
      data: [review, { ...review, id: 2 }], summary: { average: 4.8, count: 21 }, page: 2,
      limit: 20, total: 21, hasMore: false, canReview: false, myReview: null
    });
    expect(fixture.componentInstance.productReviews.map((item) => item.id)).toEqual([1, 2]);
    expect(fixture.componentInstance.productReviewsHasMore).toBe(false);
    http.verify();
  });

  it('ignores late review responses after switching products or closing the product page', () => {
    const { fixture, http } = bootStorefront();
    const component = fixture.componentInstance;
    const otherProduct = { ...product, id: 11, slug: 'ficus' };

    component.selectedProduct = product;
    component.loadProductReviews(product);
    const firstRequest = http.expectOne(`/api/product-reviews?productId=${product.id}&page=1&limit=20`);

    component.selectedProduct = otherProduct;
    component.loadProductReviews(otherProduct);
    const secondRequest = http.expectOne(`/api/product-reviews?productId=${otherProduct.id}&page=1&limit=20`);

    const review = {
      id: 11, rating: 5, comment: 'Current product review.', authorName: 'Verified customer',
      verifiedPurchase: true, createdAt: '2026-10-01T00:00:00Z', updatedAt: '2026-10-01T00:00:00Z'
    };
    firstRequest.flush({
      data: [{ ...review, id: 10, comment: 'Stale review.' }], summary: { average: 1, count: 1 },
      page: 1, limit: 20, total: 1, hasMore: false, canReview: false, myReview: null
    });
    expect(component.reviewLoading).toBe(true);
    expect(component.productReviews).toEqual([]);

    secondRequest.flush({
      data: [review], summary: { average: 5, count: 1 },
      page: 1, limit: 20, total: 1, hasMore: false, canReview: false, myReview: null
    });
    expect(component.productReviews.map((item) => item.id)).toEqual([11]);
    expect(component.productReviewSummary).toEqual({ average: 5, count: 1 });

    component.loadProductReviews(product);
    const closingRequest = http.expectOne(`/api/product-reviews?productId=${product.id}&page=1&limit=20`);
    component.closeProductDetails();
    closingRequest.flush({
      data: [{ ...review, id: 12 }], summary: { average: 5, count: 1 },
      page: 1, limit: 20, total: 1, hasMore: false, canReview: false, myReview: null
    });
    expect(component.selectedProduct).toBeNull();
    expect(component.productReviews).toEqual([]);
    expect(component.reviewLoading).toBe(false);
    http.verify();
    fixture.destroy();
  });

  it('resolves an unknown product slug once and reports a not-found page', () => {
    const { fixture, http } = bootStorefront();
    const component = fixture.componentInstance;
    component.currentPage = 'product';
    component.productSlug = 'does-not-exist';

    component.resolveProductPage();
    const lookup = http.expectOne('/api/products?slug=does-not-exist&page=1&limit=1');
    lookup.flush({
      data: [],
      meta: {
        page: 1, limit: 1, total: 0, pages: 1, baseCurrency: 'USD', currencyRates: { USD: 1, EUR: 0.89 },
        currencyRateDate: '2026-10-02', currencyRateSource: 'ecb-daily-reference', currencyRateType: 'daily_reference',
        currencyRateToken: 'd'.repeat(64), currencyRateStatus: 'current', currencyRateCheckedAt: '2026-10-04T12:00:00Z'
      }
    });

    expect(component.productPageLoading).toBe(false);
    expect(component.productPageNotFound).toBe(true);
    component.resolveProductPage();
    http.expectNone('/api/products?slug=does-not-exist&page=1&limit=1');
    http.verify();
    fixture.destroy();
  });

  it('does not apply a submitted review to another product when navigation changes mid-request', () => {
    const { fixture, http } = bootStorefront();
    const component = fixture.componentInstance;
    const otherProduct = { ...product, id: 11, slug: 'ficus' };
    component.auth.replaceUser(verifiedCustomer);
    component.selectedProduct = product;
    component.canReviewProduct = true;
    component.reviewForm = { rating: 4, comment: 'Healthy plant.' };
    const refreshCatalog = vi.spyOn(component, 'refreshCatalog').mockImplementation(() => undefined);

    component.submitProductReview();
    const request = http.expectOne('/api/product-reviews');
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('X-CSRF-Token')).toBe('csrf-shell-test');
    expect(request.request.body).toEqual({ productId: product.id, rating: 4, comment: 'Healthy plant.' });

    component.selectedProduct = otherProduct;
    component.ownReviewStatus = null;
    component.reviewSuccess = '';
    component.reviewLoading = true;
    component.canReviewProduct = false;
    request.flush({ message: 'Saved.', reviewId: 21, status: 'visible' });

    expect(component.ownReviewStatus).toBeNull();
    expect(component.reviewSuccess).toBe('');
    expect(component.reviewLoading).toBe(true);
    expect(component.canReviewProduct).toBe(false);
    expect(refreshCatalog).toHaveBeenCalledOnce();
    http.verify();
    fixture.destroy();
  });
});

describe('StoreService', () => {
  let service: StoreService;

  beforeEach(() => {
    window.localStorage.clear();
    TestBed.configureTestingModule({ providers: [StoreService] });
    service = TestBed.inject(StoreService);
  });

  it('keeps cart quantity within current stock and persists it', () => {
    service.add(product);
    service.add(product);
    service.add(product);

    expect(service.cart()).toHaveLength(1);
    expect(service.cart()[0].quantity).toBe(2);
    expect(JSON.parse(window.localStorage.getItem('botanical-cart') ?? '[]')[0].quantity).toBe(2);
  });

  it('removes stale cart and favorite entries when reconciling the catalog', () => {
    service.add(product);
    service.toggleFavorite(product.id);

    service.reconcile([]);

    expect(service.cart()).toEqual([]);
    expect(service.favorites().size).toBe(0);
  });

  it('restores only product ids and quantities after validating them against the catalog', () => {
    service.add(product);
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ providers: [StoreService] });
    const restored = TestBed.inject(StoreService);

    expect(restored.cart()).toEqual([]);
    restored.reconcile([product]);

    expect(restored.cart()).toEqual([{ ...product, quantity: 1 }]);
  });

  it('preserves unresolved cart and favorite ids while the catalog is only partially loaded', () => {
    service.add(product);
    service.toggleFavorite(product.id);

    service.reconcile([], false);

    expect(service.cart()).toEqual([]);
    expect(service.favorites().has(product.id)).toBe(true);
    expect(JSON.parse(window.localStorage.getItem('botanical-cart') ?? '[]')).toEqual([{ id: product.id, quantity: 1 }]);

    service.reconcile([product], false);
    expect(service.cart()).toEqual([{ ...product, quantity: 1 }]);
  });

  it('removes a line at zero and clears the persisted cart', () => {
    service.add(product);
    service.changeQuantity(product.id, -1);
    expect(service.cart()).toEqual([]);
    expect(JSON.parse(window.localStorage.getItem('botanical-cart') ?? '[]')).toEqual([]);
  });

  it('ignores malformed and out-of-range persisted values', () => {
    window.localStorage.setItem('botanical-cart', JSON.stringify([
      { id: product.id, quantity: 21 },
      { id: '10', quantity: 1 },
      null
    ]));
    window.localStorage.setItem('botanical-favorites', JSON.stringify([1, 1, -2, '3']));
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ providers: [StoreService] });
    const restored = TestBed.inject(StoreService);
    restored.reconcile([product]);
    expect(restored.cart()).toEqual([]);
    expect([...restored.favorites()]).toEqual([]);
  });
});

describe('CatalogService', () => {
  let service: CatalogService;
  let http: HttpTestingController;
  const meta = (page: number, total: number, pages: number) => ({
    page, limit: 100, total, pages, baseCurrency: 'USD' as const, currencyRates: { USD: 1, EUR: 0.89 },
    currencyRateDate: '2026-10-02', currencyRateSource: 'ecb-daily-reference' as const, currencyRateType: 'daily_reference' as const,
    currencyRateToken: 'a'.repeat(64), currencyRateStatus: 'current' as const, currencyRateCheckedAt: '2026-10-04T12:00:00Z'
  });

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), CatalogService]
    });
    service = TestBed.inject(CatalogService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads additional catalog pages without duplicating products', () => {
    service.load().subscribe();
    const first = http.expectOne((request) => request.url === '/api/products' && request.params.get('page') === '1');
    first.flush({ data: [product], meta: meta(1, 101, 2) });

    service.loadMore().subscribe();
    const second = http.expectOne((request) => request.url === '/api/products' && request.params.get('page') === '2');
    second.flush({ data: [product, { ...product, id: 11, slug: 'ficus' }], meta: meta(2, 101, 2) });

    expect(service.products().map((item) => item.id)).toEqual([10, 11]);
    expect(service.page()).toBe(2);
    expect(service.loaded()).toBe(true);
  });

  it('can resolve a product by slug even when it is outside the loaded page', () => {
    service.loadProductBySlug('monstera').subscribe((value) => expect(value?.id).toBe(10));
    const request = http.expectOne((candidate) => candidate.url === '/api/products' && candidate.params.get('slug') === 'monstera');
    request.flush({ data: [product], meta: meta(1, 1, 1) });

    expect(service.products()).toEqual([product]);
  });

  it('exposes a recoverable state when catalog loading fails', () => {
    service.load().subscribe({ error: () => undefined });
    http.expectOne('/api/products?page=1&limit=100').flush(
      { error: 'offline' },
      { status: 503, statusText: 'Unavailable' }
    );
    expect(service.loading()).toBe(false);
    expect(service.loaded()).toBe(false);
    expect(service.error()).toBe('catalog-unavailable');
  });

  it('does not fall back to a fixed EUR rate when the ECB reference is unavailable', () => {
    service.load().subscribe();
    http.expectOne('/api/products?page=1&limit=100').flush({
      data: [product],
      meta: {
        ...meta(1, 1, 1),
        currencyRates: { USD: 1, EUR: null },
        currencyRateDate: null,
        currencyRateToken: null,
        currencyRateStatus: 'unavailable'
      }
    });

    expect(service.currencyRates()).toEqual({ USD: 1, EUR: null });
    expect(service.currencyRateMetadata()?.currencyRateToken).toBeNull();
    expect(service.currencyRateMetadata()?.currencyRateStatus).toBe('unavailable');
  });
});

describe('AuthService', () => {
  let service: AuthService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), AuthService]
    });
    service = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('bootstraps the server session and sends its CSRF token on login', () => {
    service.initialize().subscribe();
    http.expectOne('/api/auth/session').flush({ user: null, csrfToken: 'csrf-123' });

    service.login({ email: 'ana@example.com', password: 'Strong-pass1!', rememberMe: true }).subscribe();
    const login = http.expectOne('/api/auth/login');
    expect(login.request.headers.get('X-CSRF-Token')).toBe('csrf-123');
    login.flush({
      message: 'Authenticated.',
      csrfToken: 'csrf-456',
      user: { id: 7, name: 'Ana', email: 'ana@example.com', role: 'customer', emailVerifiedAt: '2026-09-24' }
    });

    expect(service.user()?.email).toBe('ana@example.com');
    expect(service.ready()).toBe(true);
  });

  it('retries session initialization before a write when the initial request failed', () => {
    service.initialize().subscribe({ error: () => undefined });
    http.expectOne('/api/auth/session').flush({ error: 'offline' }, { status: 503, statusText: 'Unavailable' });

    service.login({ email: 'ana@example.com', password: 'Strong-pass1!', rememberMe: false }).subscribe();
    http.expectOne('/api/auth/session').flush({ user: null, csrfToken: 'csrf-recovered' });
    const login = http.expectOne('/api/auth/login');
    expect(login.request.headers.get('X-CSRF-Token')).toBe('csrf-recovered');
    login.flush({
      message: 'Authenticated.',
      csrfToken: 'csrf-next',
      user: { id: 7, name: 'Ana', email: 'ana@example.com', role: 'customer', emailVerifiedAt: '2026-09-24' }
    });
  });

  it('refreshes CSRF state and safely retries when the server expires the session token', () => {
    service.initialize().subscribe();
    http.expectOne('/api/auth/session').flush({ user: null, csrfToken: 'csrf-old' });

    service.register({ name: 'Ana' }).subscribe();
    const expired = http.expectOne('/api/auth/register');
    expect(expired.request.headers.get('X-CSRF-Token')).toBe('csrf-old');
    expired.flush(
      { error: 'Sessão expirada. Atualize a página e tente novamente.', code: 'csrf_invalid' },
      { status: 403, statusText: 'Forbidden' }
    );

    http.expectOne('/api/auth/session').flush({ user: null, csrfToken: 'csrf-new' });
    const retried = http.expectOne('/api/auth/register');
    expect(retried.request.headers.get('X-CSRF-Token')).toBe('csrf-new');
    retried.flush({ message: 'Accepted.' });
  });

  it('keeps session-bound requests alive when only refreshing CSRF for the same account', () => {
    service.initialize().subscribe();
    http.expectOne('/api/auth/session').flush({
      csrfToken: 'csrf-user-old',
      user: { id: 7, name: 'Ana', email: 'ana@example.com', role: 'customer', emailVerifiedAt: '2026-09-24' }
    });
    const initialVersion = service.sessionVersion;
    let identityChanges = 0;
    service.sessionChanged$.subscribe(() => identityChanges++);

    service.postWithCsrf('/api/test', {}).subscribe();
    http.expectOne('/api/test').flush({ error: 'csrf_invalid', code: 'csrf_invalid' }, { status: 403, statusText: 'Forbidden' });
    http.expectOne('/api/auth/session').flush({
      csrfToken: 'csrf-user-refreshed',
      user: { id: 7, name: 'Ana', email: 'ana@example.com', role: 'customer', emailVerifiedAt: '2026-09-24' }
    });
    http.expectOne('/api/test').flush({ message: 'Accepted.' });

    expect(service.sessionVersion).toBe(initialVersion);
    expect(identityChanges).toBe(0);
  });

  it('does not replay a CSRF retry under a different account', () => {
    service.initialize().subscribe();
    http.expectOne('/api/auth/session').flush({
      csrfToken: 'csrf-ana',
      user: { id: 7, name: 'Ana', email: 'ana@example.com', role: 'customer', emailVerifiedAt: '2026-09-24' }
    });
    const failures: HttpErrorResponse[] = [];
    service.register({ name: 'Ana' }).subscribe({ error: (error: HttpErrorResponse) => { failures.push(error); } });
    http.expectOne('/api/auth/register').flush(
      { error: 'CSRF token expired.', code: 'csrf_invalid' },
      { status: 403, statusText: 'Forbidden' }
    );
    http.expectOne('/api/auth/session').flush({
      csrfToken: 'csrf-leo',
      user: { id: 8, name: 'Léo', email: 'leo@example.com', role: 'customer', emailVerifiedAt: '2026-10-01' }
    });

    expect(failures[0]?.status).toBe(403);
    expect(service.user()?.id).toBe(8);
    http.expectNone('/api/auth/register');
  });

  it('clears and revalidates local auth when another tab changes the shared session', () => {
    service.initialize().subscribe();
    const oldSessionRequest = http.expectOne('/api/auth/session');
    const originalVersion = service.sessionVersion;

    window.dispatchEvent(new StorageEvent('storage', {
      key: 'botanical-auth-session-change', newValue: 'changed-in-another-tab'
    }));
    expect(service.user()).toBeNull();
    expect(oldSessionRequest.cancelled).toBe(true);
    http.expectOne('/api/auth/session').flush({
      csrfToken: 'csrf-leo',
      user: { id: 8, name: 'Léo', email: 'leo@example.com', role: 'customer', emailVerifiedAt: '2026-10-01' }
    });

    expect(service.user()?.id).toBe(8);
    expect(service.sessionVersion).toBeGreaterThan(originalVersion);
  });

  it('verifies email with a CSRF-protected POST instead of a state-changing GET', () => {
    service.initialize().subscribe();
    http.expectOne('/api/auth/session').flush({ user: null, csrfToken: 'csrf-verify' });

    service.verifyEmail('a'.repeat(64), 'Strong-pass1!').subscribe();
    const request = http.expectOne('/api/auth/verify-email');
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('X-CSRF-Token')).toBe('csrf-verify');
    expect(request.request.body).toEqual({ token: 'a'.repeat(64), password: 'Strong-pass1!' });
    request.flush({ message: 'Verified.' });
  });

  it('uses a development-only role selector for one-click demo login without exposing a password', () => {
    service.demoAccounts().subscribe((response) => expect(response.accounts).toHaveLength(3));
    http.expectOne('/api/auth/demo-accounts').flush({
      enabled: true,
      accounts: [
        { role: 'admin', label: 'Entrar como administrador' },
        { role: 'customer', label: 'Entrar como usuário' },
        { role: 'seller', label: 'Entrar como vendedor' }
      ]
    });

    service.initialize().subscribe();
    http.expectOne('/api/auth/session').flush({ user: null, csrfToken: 'csrf-demo' });
    service.demoLogin('admin').subscribe();
    const request = http.expectOne('/api/auth/demo-login');
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('X-CSRF-Token')).toBe('csrf-demo');
    expect(request.request.body).toEqual({ role: 'admin' });
    expect(request.request.body.password).toBeUndefined();
    request.flush({
      message: 'Demo.',
      csrfToken: 'csrf-demo-next',
      user: { id: 20, name: 'Admin demo', email: 'demo-admin@botanical.local', role: 'admin', emailVerifiedAt: '2026-10-01' }
    });
    expect(service.user()?.role).toBe('admin');
  });

  it('clears client authentication state after a protected endpoint reports unauthorized', () => {
    service.initialize().subscribe();
    http.expectOne('/api/auth/session').flush({
      csrfToken: 'csrf-ready',
      user: { id: 7, name: 'Ana', email: 'ana@example.com', role: 'customer', emailVerifiedAt: '2026-09-24' }
    });
    expect(service.user()).not.toBeNull();
    service.markSignedOut();
    expect(service.user()).toBeNull();
    service.login({ email: 'ana@example.com', password: 'Strong-pass1!', rememberMe: false }).subscribe();
    http.expectOne('/api/auth/session').flush({ user: null, csrfToken: 'csrf-new' });
    http.expectOne('/api/auth/login').flush({
      message: 'Authenticated.',
      csrfToken: 'csrf-final',
      user: { id: 7, name: 'Ana', email: 'ana@example.com', role: 'customer', emailVerifiedAt: '2026-09-24' }
    });
  });
});

describe('CommerceService API contracts', () => {
  let service: CommerceService;
  let auth: AuthService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), AuthService, CommerceService]
    });
    service = TestBed.inject(CommerceService);
    auth = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
    auth.initialize().subscribe();
    http.expectOne('/api/auth/session').flush({ user: null, csrfToken: 'csrf-commerce' });
  });

  afterEach(() => http.verify());

  function expectCsrfPost(url: string, response: object, body?: object): void {
    const request = http.expectOne(url);
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('X-CSRF-Token')).toBe('csrf-commerce');
    if (body !== undefined) expect(request.request.body).toEqual(body);
    request.flush(response);
  }

  it('uses public reads and protected writes for reviews, shipping and messages', () => {
    service.productReviews(product.id).subscribe();
    const reviews = http.expectOne(`/api/product-reviews?productId=${product.id}&page=1&limit=20`);
    expect(reviews.request.method).toBe('GET');
    reviews.flush({ data: [], summary: { average: 0, count: 0 }, page: 1, limit: 20, total: 0, hasMore: false, canReview: false, myReview: null });
    service.productReviews(product.id, 2, 10).subscribe();
    const olderReviews = http.expectOne(`/api/product-reviews?productId=${product.id}&page=2&limit=10`);
    olderReviews.flush({ data: [], summary: { average: 0, count: 0 }, page: 2, limit: 10, total: 0, hasMore: false, canReview: false, myReview: null });

    service.shippingQuotes({ country: 'BE', postalCode: '1000', items: [{ productId: product.id, quantity: 1 }] }).subscribe();
    expectCsrfPost('/api/shipping/quotes', { carrier: 'bpost', quotes: [] });
    service.saveProductReview({ productId: product.id, rating: 5, comment: 'Excellent product.' }).subscribe();
    expectCsrfPost('/api/product-reviews', { message: 'Saved.', reviewId: 1, status: 'visible' });
    service.subscribe('ana@example.com', 'fr').subscribe();
    expectCsrfPost('/api/newsletter', { message: 'Subscribed.' });
    service.contact({ name: 'Ana', email: 'ana@example.com', subject: 'other', message: 'A useful message.' }).subscribe();
    expectCsrfPost('/api/contact', { message: 'Received.' });
  });

  it('keeps account and order mutations behind CSRF', () => {
    service.orders().subscribe();
    const orders = http.expectOne('/api/orders?page=1&limit=50');
    expect(orders.request.method).toBe('GET');
    orders.flush({ data: [], page: 1, limit: 50, total: 0, hasMore: false });
    service.orders(2, 25).subscribe();
    const olderOrders = http.expectOne('/api/orders?page=2&limit=25');
    expect(olderOrders.request.method).toBe('GET');
    olderOrders.flush({ data: [], page: 2, limit: 25, total: 0, hasMore: false });
    service.profile().subscribe();
    const profile = http.expectOne('/api/account/profile');
    expect(profile.request.method).toBe('GET');
    profile.flush({ profile: {} });
    service.createOrder({ idempotencyKey: crypto.randomUUID() }).subscribe();
    expectCsrfPost('/api/orders', { order: {} });
    service.checkoutOrderStatus('BE-20261004-ABCDEF1234').subscribe();
    expectCsrfPost('/api/payments/order-status', { order: {}, paymentStatus: 'pending', checkoutUrl: null, canResume: false, canRetry: false, canCancel: true, providerStatus: 'open' }, { orderNumber: 'BE-20261004-ABCDEF1234' });
    service.paymentSessionStatus('cs_test_checkout-status').subscribe();
    expectCsrfPost('/api/payments/session-status', { order: {}, paymentStatus: 'pending' }, { sessionId: 'cs_test_checkout-status' });
    service.resumeCheckoutOrder('BE-20261004-ABCDEF1234').subscribe();
    expectCsrfPost('/api/payments/resume-checkout', { order: {}, checkoutUrl: 'https://checkout.stripe.com/c/pay/test' });
    service.cancelCheckoutOrder('BE-20261004-ABCDEF1234').subscribe();
    expectCsrfPost('/api/payments/cancel-checkout', { order: {}, cancelled: true, alreadyPaid: false });
    service.updateProfile({ name: 'Ana' }).subscribe();
    expectCsrfPost('/api/account/profile', { message: 'Saved.', profile: {}, user: {} });
    service.submitWithdrawal({ orderNumber: 'BE-20261004-ABCDEF1234', type: 'withdrawal', language: 'fr' }).subscribe();
    expectCsrfPost('/api/orders/withdrawal', { request: null, alreadySubmitted: false, emailAcknowledged: true });
  });

  it('maps seller reads and ownership mutations to their dedicated endpoints', () => {
    service.sellerDashboard().subscribe();
    const dashboard = http.expectOne('/api/seller/dashboard');
    expect(dashboard.request.method).toBe('GET');
    dashboard.flush({ store: null, products: [] });
    service.updateSellerStore({ displayName: 'Ana Plants' }).subscribe();
    expectCsrfPost('/api/seller/store', { message: 'Saved.', store: {} });
    service.saveSellerProduct({ name: 'Fern' }).subscribe();
    expectCsrfPost('/api/seller/products', { message: 'Saved.', product: {} });
    service.archiveSellerProduct(12).subscribe();
    const archive = http.expectOne('/api/seller/products/archive');
    expect(archive.request.body).toEqual({ id: 12 });
    expect(archive.request.headers.get('X-CSRF-Token')).toBe('csrf-commerce');
    archive.flush({ message: 'Archived.' });
  });

  it('maps every administrative mutation to an admin-only endpoint', () => {
    service.adminSellers().subscribe();
    const sellers = http.expectOne((request) => request.url === '/api/admin/sellers');
    expect(sellers.request.method).toBe('GET');
    expect(sellers.request.params.get('page')).toBe('1');
    expect(sellers.request.params.get('limit')).toBe('50');
    sellers.flush({ data: [], page: 1, limit: 50, total: 0, hasMore: false });
    service.adminOverview().subscribe();
    const overview = http.expectOne((request) => request.url === '/api/admin/overview');
    expect(overview.request.method).toBe('GET');
    expect(overview.request.params.get('page')).toBe('1');
    expect(overview.request.params.get('limit')).toBe('50');
    overview.flush({
      users: [], products: [], reviews: [], page: 1, limit: 50,
      usersTotal: 0, usersHasMore: false,
      productsTotal: 0, productsHasMore: false,
      reviewsTotal: 0, reviewsHasMore: false
    });
    service.adminOrders().subscribe();
    const orders = http.expectOne((request) => request.url === '/api/admin/orders');
    expect(orders.request.method).toBe('GET');
    expect(orders.request.params.get('page')).toBe('1');
    expect(orders.request.params.get('limit')).toBe('50');
    orders.flush({ data: [], page: 1, limit: 50, total: 0, hasMore: false });
    service.adminPaymentDisputes().subscribe();
    const disputes = http.expectOne((request) => request.url === '/api/admin/payment-disputes');
    expect(disputes.request.method).toBe('GET');
    expect(disputes.request.params.get('page')).toBe('1');
    expect(disputes.request.params.get('limit')).toBe('50');
    disputes.flush({ data: [], page: 1, limit: 50, total: 0, hasMore: false });
    service.adminPaymentNotifications(2, 25).subscribe();
    const paymentNotifications = http.expectOne((request) => request.url === '/api/admin/payment-notifications');
    expect(paymentNotifications.request.method).toBe('GET');
    expect(paymentNotifications.request.params.get('page')).toBe('2');
    expect(paymentNotifications.request.params.get('limit')).toBe('25');
    paymentNotifications.flush({ data: [], page: 2, limit: 25, total: 0, hasMore: false });
    service.retryAdminPaymentNotification(27, 'Corrected the SMTP relay credentials.').subscribe();
    expectCsrfPost('/api/admin/payment-notifications/retry', { message: 'Queued.' });
    service.partialRefund('BE-20261004-ABCDEF1234', 250, 'Customer service adjustment').subscribe();
    expectCsrfPost('/api/admin/orders/refund', { message: 'Refund accepted.' });
    service.adminWithdrawals().subscribe();
    const withdrawals = http.expectOne((request) => request.url === '/api/admin/withdrawals');
    expect(withdrawals.request.method).toBe('GET');
    expect(withdrawals.request.params.get('page')).toBe('1');
    expect(withdrawals.request.params.get('limit')).toBe('50');
    withdrawals.flush({ data: [], page: 1, limit: 50, total: 0, hasMore: false });
    service.adminWithdrawalAction({ requestId: 14, action: 'review' }).subscribe();
    expectCsrfPost('/api/admin/withdrawals/action', { message: 'Reviewed.' });
    service.cancelAdminOrder('BE-20261004-ABCDEF1234').subscribe();
    expectCsrfPost('/api/admin/orders/cancel', { message: 'Cancelled.' });
    service.cancelAdminOrder('BE-20261004-ABCDEF1234', 'resolve_refund', 'refunded', 'Verified with Stripe records').subscribe();
    const resolution = http.expectOne('/api/admin/orders/cancel');
    expect(resolution.request.body).toEqual({
      orderNumber: 'BE-20261004-ABCDEF1234',
      action: 'resolve_refund',
      resolution: 'refunded',
      reason: 'Verified with Stripe records'
    });
    expect(resolution.request.headers.get('X-CSRF-Token')).toBe('csrf-commerce');
    resolution.flush({ message: 'Resolved.' });
    service.setSellerVerification(4, true).subscribe();
    expectCsrfPost('/api/admin/sellers/verification', { message: 'Approved.' });
    service.updateAdminUser({ id: 7 }).subscribe();
    expectCsrfPost('/api/admin/users', { message: 'Saved.', user: {} });
    service.updateAdminProduct({ id: 9 }).subscribe();
    expectCsrfPost('/api/admin/products', { message: 'Saved.', product: {} });
    service.updateAdminStore({ id: 4 }).subscribe();
    expectCsrfPost('/api/admin/stores', { message: 'Saved.' });
    service.moderateReview(5, 'hidden').subscribe();
    expectCsrfPost('/api/admin/reviews/moderate', { message: 'Hidden.' });
  });
});
