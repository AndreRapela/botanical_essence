import { AdminPaymentNotificationsComponent } from './admin-payment-notifications.component';
import { AdminPaymentNotification } from './storefront.models';

const notification: AdminPaymentNotification = {
  id: 9,
  orderId: 4,
  orderNumber: 'BE-20261005-ABC1234567',
  customerName: 'Ana Customer',
  email: 'ana@example.test',
  notificationType: 'payment_confirmation',
  referenceId: '',
  status: 'failed',
  attempts: 12,
  consecutiveFailures: 12,
  createdAt: '2026-10-05T10:00:00Z',
  availableAt: '2026-10-05T10:00:00Z',
  lockedUntil: null,
  lastError: 'Temporary mail relay failure.',
  retryAvailable: true
};

describe('admin payment notification issues', () => {
  it('localizes notification and status labels', () => {
    const component = new AdminPaymentNotificationsComponent();
    expect(component.typeLabel('payment_confirmation')).toBe('Payment confirmation');
    expect(component.typeLabel('refund_confirmation')).toBe('Refund confirmation');
    expect(component.typeLabel('full_refund_confirmation')).toBe('Full refund confirmation');
    expect(component.statusLabel('failed')).toBe('Needs attention');
    expect(component.statusLabel('pending')).toBe('Waiting to retry');
    expect(component.statusLabel('processing')).toBe('Retry in progress');
    component.language = 'fr';
    expect(component.typeLabel('payment_confirmation')).toBe('Confirmation de paiement');
    expect(component.statusLabel('failed')).toBe('Action requise');
  });

  it('emits only the selected notification for manual retry', () => {
    const component = new AdminPaymentNotificationsComponent();
    const received: AdminPaymentNotification[] = [];
    component.retry.subscribe((item) => received.push(item));
    component.retry.emit(notification);
    expect(received).toEqual([notification]);
  });

  it('emits a request for the next page', () => {
    const component = new AdminPaymentNotificationsComponent();
    const received: boolean[] = [];
    component.loadMore.subscribe(() => received.push(true));
    component.loadMore.emit();
    expect(received).toEqual([true]);
  });
});
