import { AdminDisputesComponent } from './admin-disputes.component';
import { AdminPaymentDispute } from './storefront.models';

describe('admin disputes queue', () => {
  const dispute: AdminPaymentDispute = {
    id: 'dp_123', orderId: 4, orderNumber: 'BE-1004', customerName: 'A. Customer',
    email: 'customer@example.test', paymentIntentId: 'pi_123', chargeId: 'ch_123',
    status: 'needs_response', reason: 'product_not_received', amountCents: 1299,
    currency: 'EUR', evidenceDueAt: '2026-10-12T12:00:00Z', livemode: false,
    createdAt: '2026-10-01T12:00:00Z', syncError: null
  };

  it('keeps test and live review URLs isolated and encodes dispute ids', () => {
    const component = new AdminDisputesComponent();
    expect(component.disputeUrl({ ...dispute, id: 'dp/a', livemode: false })).toBe('https://dashboard.stripe.com/test/disputes/dp%2Fa');
    expect(component.disputeUrl({ ...dispute, livemode: true })).toBe('https://dashboard.stripe.com/disputes/dp_123');
  });

  it('uses the parent currency formatter and localizes labels', () => {
    const component = new AdminDisputesComponent();
    const calls: unknown[][] = [];
    component.priceFormatter = (amount, currency) => {
      calls.push([amount, currency]);
      return '€12.99';
    };
    component.language = 'fr';
    expect(component.formatAmount(dispute)).toBe('€12.99');
    expect(calls).toEqual([[12.99, 'EUR']]);
    expect(component.copy('Live', 'Actif')).toBe('Actif');
  });

  it('emits a request to load older cases', () => {
    const component = new AdminDisputesComponent();
    const received: unknown[] = [];
    component.loadMore.subscribe(() => received.push(true));
    component.loadMore.emit();
    expect(received).toEqual([true]);
  });
});
