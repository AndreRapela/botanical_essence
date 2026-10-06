import { AdminOrder, AdminPaymentDispute, OrderSummary } from './storefront.models';
import {
  adminOrderItems,
  adminOrderRefundRemaining,
  canRequestWithdrawal,
  orderRefundRemaining,
  stripeDisputeUrl,
  withdrawalStatusText
} from './order.view';

describe('order view rules', () => {
  const order: OrderSummary = {
    number: 'BE-20261005-ABCDEF1234',
    status: 'paid',
    paymentStatus: 'paid',
    subtotal: 20,
    shipping: 4,
    total: 24,
    currency: 'EUR'
  };

  it('formats order line items without changing quantities', () => {
    const adminOrder = { items: [{ name: 'Monstera', quantity: 2 }, { name: 'Fern', quantity: 1 }] } as AdminOrder;
    expect(adminOrderItems(adminOrder)).toBe('Monstera × 2, Fern × 1');
  });

  it('calculates refund balances in integer cents and clamps over-refunds to zero', () => {
    expect(orderRefundRemaining({ ...order, total: 24.1, refundedTotalCents: 1_235 })).toBe(11.75);
    expect(orderRefundRemaining({ ...order, refundedTotalCents: 3_000 })).toBe(0);

    const adminOrder = { total: 24.1, refundedTotalCents: 1_235 } as AdminOrder;
    expect(adminOrderRefundRemaining(adminOrder)).toBe(11.75);
    expect(adminOrderRefundRemaining({ ...adminOrder, refundedTotalCents: 3_000 } as AdminOrder)).toBe(0);
  });

  it('allows a consumer withdrawal only for paid, non-cancelled orders with refundable value', () => {
    expect(canRequestWithdrawal(order)).toBe(true);
    expect(canRequestWithdrawal({ ...order, paymentStatus: 'partially_refunded', refundedTotalCents: 2_400 })).toBe(false);
    expect(canRequestWithdrawal({ ...order, paymentStatus: 'pending' })).toBe(false);
    expect(canRequestWithdrawal({ ...order, status: 'cancelled' })).toBe(false);
    expect(canRequestWithdrawal({ ...order, withdrawalStatus: 'reviewing' })).toBe(false);
    expect(canRequestWithdrawal({ ...order, withdrawalStatus: 'not_eligible' })).toBe(true);
  });

  it('translates known return states and safely preserves unknown provider states', () => {
    expect(withdrawalStatusText('refund_pending', 'en')).toBe('Refund being confirmed');
    expect(withdrawalStatusText('refunded', 'fr')).toBe('Remboursement confirmé');
    expect(withdrawalStatusText('provider_new_state', 'en')).toBe('provider_new_state');
    expect(withdrawalStatusText(null, 'fr')).toBe('');
  });

  it('links disputes to the correct live or test Stripe dashboard and encodes identifiers', () => {
    const dispute = { id: 'dp_123', livemode: true } as AdminPaymentDispute;
    expect(stripeDisputeUrl(dispute)).toBe('https://dashboard.stripe.com/disputes/dp_123');
    expect(stripeDisputeUrl({ ...dispute, livemode: false, id: 'dp/unsafe' })).toBe('https://dashboard.stripe.com/test/disputes/dp%2Funsafe');
  });
});
