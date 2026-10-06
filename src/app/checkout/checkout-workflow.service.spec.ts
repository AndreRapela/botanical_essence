import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { of, Subject, throwError } from 'rxjs';

import { AuthService } from '../core/auth.service';
import { CatalogService } from '../core/catalog.service';
import { CommerceService } from '../core/commerce.service';
import { StoreService } from '../core/store.service';
import type { AuthUser, CartLine, OrderSummary, ShippingQuoteResponse } from '../storefront.models';
import { shippingQuoteKey } from './checkout-policy';
import type { CheckoutWorkflowEffects } from './checkout-workflow.context';
import { CheckoutWorkflowService } from './checkout-workflow.service';
import { CheckoutWorkflowState } from './checkout-workflow.state';

interface CommerceMocks {
  createOrder: ReturnType<typeof vi.fn>;
  paymentSessionStatus: ReturnType<typeof vi.fn>;
  checkoutOrderStatus: ReturnType<typeof vi.fn>;
  resumeCheckoutOrder: ReturnType<typeof vi.fn>;
  cancelCheckoutOrder: ReturnType<typeof vi.fn>;
}

const customer: AuthUser = { id: 7, name: 'Ana', email: 'ana@example.com', role: 'customer', emailVerifiedAt: '2026-10-01' };
const paidOrder: OrderSummary = { number: 'BE-20261005-ABCD1234', status: 'paid', paymentStatus: 'paid', subtotal: 12, shipping: 0, total: 12, currency: 'EUR' };
const cartLine: CartLine = {
  id: 10, slug: 'monstera', name: 'Monstera', subtitle: 'Plant', origin: null, dimensions: null, materials: null,
  careInstructions: null, category: 'plants', price: 12, shippingWeightGrams: 500, stock: 5, sprite: 1, seller: 'Shop',
  marketplace: true, averageRating: 0, reviewCount: 0, currency: 'USD', quantity: 1
};

describe('CheckoutWorkflowService', () => {
  let state: CheckoutWorkflowState;
  let user: ReturnType<typeof signal<AuthUser | null>>;
  let cart: CartLine[];
  let commerce: CommerceMocks;
  let store: { cart: () => CartLine[]; clearCart: ReturnType<typeof vi.fn> };
  let effects: CheckoutWorkflowEffects;
  let workflow: CheckoutWorkflowService;

  function configure(): void {
    state = new CheckoutWorkflowState();
    user = signal<AuthUser | null>(customer);
    cart = [cartLine];
    commerce = {
      createOrder: vi.fn().mockReturnValue(of({ order: paidOrder, checkoutUrl: null })),
      paymentSessionStatus: vi.fn(),
      checkoutOrderStatus: vi.fn(),
      resumeCheckoutOrder: vi.fn(),
      cancelCheckoutOrder: vi.fn()
    };
    store = { cart: () => cart, clearCart: vi.fn(() => { cart = []; }) };
    effects = {
      currency: 'EUR',
      apiError: (_error, fallback) => fallback,
      copy: (english) => english,
      calculateShipping: vi.fn(),
      handleUnauthorized: vi.fn(),
      loadOrders: vi.fn(),
      showToast: vi.fn(),
      markForCheck: vi.fn()
    };
    TestBed.configureTestingModule({ providers: [
      CheckoutWorkflowService,
      { provide: AuthService, useValue: { user } },
      { provide: CatalogService, useValue: { currencyRateMetadata: () => ({ currencyRateToken: 'r'.repeat(64), currencyRateStatus: 'current' }) } },
      { provide: CommerceService, useValue: commerce },
      { provide: StoreService, useValue: store },
      { provide: CheckoutWorkflowState, useValue: state }
    ] });
    workflow = TestBed.inject(CheckoutWorkflowService);
    state.checkoutForm = {
      name: customer.name, email: customer.email, address: 'Rue de Test 1', city: 'Brussels', postalCode: '1000',
      region: 'Brussels-Capital', country: 'BE', delivery: 'bpost_home'
    };
    state.selectedShippingQuote = { id: 'bpost_home', label: 'Home', amount: 0, amountCents: 0, currency: 'EUR', estimatedDays: '1-2 days', description: 'Estimate', kind: 'home' };
    state.shippingQuoteResponse = { tariffVersion: 'bpost-public-2026-v1' } as ShippingQuoteResponse;
    state.shippingQuoteRequestKey = shippingQuoteKey('BE', '1000', cart);
    state.paymentPreviewMethod = 'card';
  }

  beforeEach(() => { window.sessionStorage.clear(); configure(); });

  it('does not create an order without an authenticated verified user', () => {
    user.set(null);
    workflow.startPurchase(effects);
    expect(commerce.createOrder).not.toHaveBeenCalled();
    expect(state.checkoutError).toContain('Sign in');
  });

  it('creates one paid checkout and clears the cart only after authoritative confirmation', () => {
    workflow.startPurchase(effects);
    expect(commerce.createOrder).toHaveBeenCalledTimes(1);
    expect(commerce.createOrder.mock.calls[0][0]).toMatchObject({ currency: 'EUR', payment: 'card', items: [{ productId: 10, quantity: 1 }] });
    expect(state.checkoutCompleted).toBe(true);
    expect(state.checkoutOrderNumber).toBe(paidOrder.number);
    expect(store.clearCart).toHaveBeenCalledOnce();
    expect(effects.loadOrders).toHaveBeenCalledOnce();
  });

  it('confirms a paid Stripe return from the backend before clearing the cart', () => {
    state.paymentReturnSessionId = 'cs_test_return_123';
    commerce.paymentSessionStatus.mockReturnValue(of({ order: paidOrder, paymentStatus: 'paid' }));

    workflow.verifyCheckoutReturn(effects);

    expect(commerce.paymentSessionStatus).toHaveBeenCalledWith('cs_test_return_123');
    expect(state.checkoutCompleted).toBe(true);
    expect(state.paymentReturnSessionId).toBe('');
    expect(store.clearCart).toHaveBeenCalledOnce();
    expect(effects.loadOrders).toHaveBeenCalledOnce();
  });

  it('keeps a returned session locked while the provider is still processing', () => {
    const pendingOrder = { ...paidOrder, status: 'awaiting_payment', paymentStatus: 'pending' };
    state.paymentReturnSessionId = 'cs_test_processing_123';
    commerce.paymentSessionStatus.mockReturnValue(of({ order: pendingOrder, paymentStatus: 'processing' }));
    commerce.checkoutOrderStatus.mockReturnValue(of({
      order: pendingOrder, paymentStatus: 'pending', checkoutUrl: null,
      canResume: false, canRetry: false, canCancel: false, providerStatus: 'processing'
    }));

    workflow.verifyCheckoutReturn(effects);

    expect(commerce.checkoutOrderStatus).toHaveBeenCalledWith(pendingOrder.number);
    expect(state.checkoutPending).toBe(true);
    expect(state.checkoutCompleted).toBe(false);
    expect(store.clearCart).not.toHaveBeenCalled();
  });

  it('fails closed when payment-return verification is unavailable', () => {
    state.paymentReturnSessionId = 'cs_test_unknown_123';
    commerce.paymentSessionStatus.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 503 })));

    workflow.verifyCheckoutReturn(effects);

    expect(state.checkoutPending).toBe(true);
    expect(state.checkoutCanCancel).toBe(false);
    expect(state.checkoutResumeUrl).toBeNull();
    expect(state.paymentVerificationLoading).toBe(false);
    expect(store.clearCart).not.toHaveBeenCalled();
  });

  it('hands an expired customer session to the authentication recovery flow', () => {
    state.paymentReturnSessionId = 'cs_test_auth_123';
    const unauthorized = new HttpErrorResponse({ status: 401 });
    commerce.paymentSessionStatus.mockReturnValue(throwError(() => unauthorized));

    workflow.verifyCheckoutReturn(effects);

    expect(effects.handleUnauthorized).toHaveBeenCalledWith(unauthorized);
    expect(state.paymentVerificationLoading).toBe(false);
    expect(store.clearCart).not.toHaveBeenCalled();
  });

  it('reconciles an existing pending order instead of creating another order', () => {
    state.checkoutPending = true;
    state.pendingCheckoutOrderNumber = paidOrder.number;
    commerce.checkoutOrderStatus.mockReturnValue(of({
      order: { ...paidOrder, status: 'awaiting_payment', paymentStatus: 'pending' }, paymentStatus: 'pending',
      checkoutUrl: null, canResume: false, canRetry: false, canCancel: false, providerStatus: 'processing'
    }));
    workflow.startPurchase(effects);
    expect(commerce.createOrder).not.toHaveBeenCalled();
    expect(commerce.checkoutOrderStatus).toHaveBeenCalledWith(paidOrder.number);
    expect(state.checkoutPending).toBe(true);
  });

  it('ignores an old-account status response after private checkout state is reset', () => {
    const response = new Subject<{
      order: OrderSummary; paymentStatus: string; checkoutUrl: string | null;
      canResume: boolean; canRetry: boolean; canCancel: boolean; providerStatus: string;
    }>();
    state.pendingCheckoutOrderNumber = paidOrder.number;
    commerce.checkoutOrderStatus.mockReturnValue(response);
    workflow.loadCheckoutOrderStatus(paidOrder.number, effects);
    workflow.resetCheckoutForAnonymousUser();
    user.set(null);
    response.next({ order: paidOrder, paymentStatus: 'paid', checkoutUrl: 'https://checkout.stripe.com/secret', canResume: false, canRetry: false, canCancel: false, providerStatus: 'paid' });
    expect(state.checkoutCompleted).toBe(false);
    expect(state.checkoutResumeUrl).toBeNull();
    expect(state.pendingCheckoutOrder).toBeNull();
    expect(store.clearCart).not.toHaveBeenCalled();
  });

  it('fails closed when pending order status cannot be verified', () => {
    state.checkoutPending = true;
    state.pendingCheckoutOrderNumber = paidOrder.number;
    commerce.checkoutOrderStatus.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 503 })));
    workflow.refreshCheckoutOrderStatus(effects);
    expect(state.checkoutPending).toBe(true);
    expect(state.checkoutCanCancel).toBe(false);
    expect(state.checkoutResumeUrl).toBeNull();
    expect(state.checkoutError).toContain('blocked until its payment status can be confirmed');
  });

  it('preserves the idempotency key when checkout preparation fails ambiguously', () => {
    const orderNumber = 'BE-20261005-PEND1234';
    commerce.createOrder.mockReturnValue(throwError(() => new HttpErrorResponse({
      status: 409, error: { code: 'checkout_preparation_failed', details: { orderNumber } }
    })));
    commerce.checkoutOrderStatus.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 503 })));
    workflow.startPurchase(effects);
    expect(commerce.createOrder).toHaveBeenCalledOnce();
    expect(commerce.checkoutOrderStatus).toHaveBeenCalledWith(orderNumber);
    expect(state.checkoutIdempotencyKey).toBeTruthy();
    expect(window.sessionStorage.getItem(`botanical-checkout-key-${customer.id}`)).toBe(state.checkoutIdempotencyKey);
    expect(state.checkoutPending).toBe(true);
  });

  it('recovers an existing retryable order rather than creating a new one', () => {
    const pendingOrder = { ...paidOrder, status: 'awaiting_payment', paymentStatus: 'pending' };
    state.checkoutPending = true;
    state.pendingCheckoutOrderNumber = pendingOrder.number;
    state.checkoutCanRetry = true;
    commerce.resumeCheckoutOrder.mockReturnValue(of({ order: pendingOrder, checkoutUrl: null, alreadyPaid: false }));
    commerce.checkoutOrderStatus.mockReturnValue(of({
      order: pendingOrder, paymentStatus: 'pending', checkoutUrl: 'https://checkout.stripe.com/c/pay/resumed',
      canResume: true, canRetry: false, canCancel: true, providerStatus: 'open'
    }));
    workflow.resumePendingCheckout(effects);
    expect(commerce.resumeCheckoutOrder).toHaveBeenCalledWith(pendingOrder.number);
    expect(commerce.createOrder).not.toHaveBeenCalled();
    expect(state.checkoutResumeUrl).toContain('/c/pay/resumed');
    expect(state.checkoutPending).toBe(true);
  });

  it('finishes a retry when the server says that the same order was already paid', () => {
    const alreadyPaidOrder = { ...paidOrder, paymentStatus: 'paid' };
    state.checkoutPending = true;
    state.pendingCheckoutOrderNumber = alreadyPaidOrder.number;
    state.checkoutCanRetry = true;
    commerce.resumeCheckoutOrder.mockReturnValue(of({ order: alreadyPaidOrder, checkoutUrl: null, alreadyPaid: true }));

    workflow.resumePendingCheckout(effects);

    expect(commerce.resumeCheckoutOrder).toHaveBeenCalledWith(alreadyPaidOrder.number);
    expect(commerce.createOrder).not.toHaveBeenCalled();
    expect(state.checkoutCompleted).toBe(true);
    expect(state.checkoutOrderNumber).toBe(alreadyPaidOrder.number);
    expect(store.clearCart).toHaveBeenCalledOnce();
  });

  it('reconciles a recoverable retry error against the same order, never creating another order', () => {
    const pendingOrder = { ...paidOrder, status: 'awaiting_payment', paymentStatus: 'pending' };
    state.checkoutPending = true;
    state.pendingCheckoutOrderNumber = pendingOrder.number;
    state.checkoutCanRetry = true;
    commerce.resumeCheckoutOrder.mockReturnValue(throwError(() => new HttpErrorResponse({
      status: 409, error: { code: 'checkout_preparation_failed' }
    })));
    commerce.checkoutOrderStatus.mockReturnValue(of({
      order: pendingOrder, paymentStatus: 'pending', checkoutUrl: null,
      canResume: false, canRetry: false, canCancel: false, providerStatus: 'manual_reconciliation_required'
    }));

    workflow.resumePendingCheckout(effects);

    expect(commerce.resumeCheckoutOrder).toHaveBeenCalledWith(pendingOrder.number);
    expect(commerce.checkoutOrderStatus).toHaveBeenCalledWith(pendingOrder.number);
    expect(commerce.createOrder).not.toHaveBeenCalled();
    expect(state.checkoutPending).toBe(true);
    expect(state.checkoutCanCancel).toBe(false);
    expect(store.clearCart).not.toHaveBeenCalled();
  });

  it('does not unlock a cart after an ambiguous cancellation response', () => {
    const pendingOrder = { ...paidOrder, status: 'awaiting_payment', paymentStatus: 'pending' };
    state.checkoutPending = true;
    state.pendingCheckoutOrderNumber = pendingOrder.number;
    state.checkoutCanCancel = true;
    commerce.cancelCheckoutOrder.mockReturnValue(of({ order: pendingOrder, cancelled: false, alreadyPaid: false }));
    commerce.checkoutOrderStatus.mockReturnValue(of({
      order: pendingOrder, paymentStatus: 'pending', checkoutUrl: null,
      canResume: false, canRetry: false, canCancel: false, providerStatus: 'manual_reconciliation_required'
    }));
    workflow.cancelPendingCheckout(effects);
    expect(commerce.cancelCheckoutOrder).toHaveBeenCalledWith(pendingOrder.number);
    expect(commerce.checkoutOrderStatus).toHaveBeenCalledWith(pendingOrder.number);
    expect(store.clearCart).not.toHaveBeenCalled();
    expect(state.checkoutPending).toBe(true);
    expect(state.checkoutCanCancel).toBe(false);
  });

  it('keeps cancelled orders blocked from auto-confirmation until payment and refund reconciliation', () => {
    const cancelledOrder = { ...paidOrder, status: 'cancelled', paymentStatus: 'pending' };
    state.pendingCheckoutOrderNumber = cancelledOrder.number;
    commerce.checkoutOrderStatus.mockReturnValue(of({
      order: cancelledOrder, paymentStatus: 'pending', checkoutUrl: null,
      canResume: false, canRetry: false, canCancel: false, providerStatus: 'unknown'
    }));
    workflow.loadCheckoutOrderStatus(cancelledOrder.number, effects);
    expect(state.checkoutCompleted).toBe(false);
    expect(state.checkoutPending).toBe(true);
    expect(store.clearCart).not.toHaveBeenCalled();
    expect(state.checkoutError).toContain('payment or refund still needs to be reconciled');
  });

  it('unlocks only when the server confirms the pending attempt is closed', () => {
    state.checkoutPending = true;
    state.pendingCheckoutOrderNumber = paidOrder.number;
    state.checkoutCanCancel = true;
    state.checkoutIdempotencyKey = 'old-retry-key';
    workflow.writeCheckoutStorage('key', state.checkoutIdempotencyKey);
    commerce.cancelCheckoutOrder.mockReturnValue(of({
      order: { ...paidOrder, status: 'cancelled', paymentStatus: 'failed' }, cancelled: true, alreadyPaid: false
    }));
    workflow.cancelPendingCheckout(effects);
    expect(state.checkoutPending).toBe(false);
    expect(state.pendingCheckoutOrderNumber).toBe('');
    expect(state.checkoutIdempotencyKey).toBe('');
    expect(window.sessionStorage.getItem(`botanical-checkout-key-${customer.id}`)).toBeNull();
    expect(effects.loadOrders).toHaveBeenCalledOnce();
  });

  it('keeps the order blocked and rechecks payment after cancellation returns an error', () => {
    const pendingOrder = { ...paidOrder, status: 'awaiting_payment', paymentStatus: 'pending' };
    state.checkoutPending = true;
    state.pendingCheckoutOrderNumber = pendingOrder.number;
    state.checkoutCanCancel = true;
    commerce.cancelCheckoutOrder.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 503 })));
    commerce.checkoutOrderStatus.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 503 })));

    workflow.cancelPendingCheckout(effects);

    expect(commerce.cancelCheckoutOrder).toHaveBeenCalledWith(pendingOrder.number);
    expect(commerce.checkoutOrderStatus).toHaveBeenCalledWith(pendingOrder.number);
    expect(state.checkoutPending).toBe(true);
    expect(state.checkoutCanCancel).toBe(false);
    expect(state.checkoutResumeUrl).toBeNull();
    expect(store.clearCart).not.toHaveBeenCalled();
  });

  it('ignores unusable checkout recovery data without letting storage failure block payment', () => {
    const storageRead = vi.spyOn(window.sessionStorage, 'getItem').mockReturnValue('x'.repeat(65));
    expect(workflow.readCheckoutStorage('key')).toBe('');
    storageRead.mockImplementation(() => { throw new Error('Storage is unavailable.'); });
    expect(workflow.readCheckoutStorage('key')).toBe('');
  });
});
