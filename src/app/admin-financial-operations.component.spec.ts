import { ComponentFixture, TestBed } from '@angular/core/testing';

import { AdminFinancialOperationsComponent } from './admin-financial-operations.component';
import { AdminOrder, AdminWithdrawalRequest } from './storefront.models';

const order: AdminOrder = {
  id: 41,
  number: 'BE-20261005-ORDER41',
  customerName: 'Ana Example',
  email: 'ana@example.test',
  status: 'processing',
  paymentStatus: 'pending',
  paymentMethod: 'card',
  paymentProvider: 'stripe',
  testPayment: false,
  paymentSessionAttemptedAt: null,
  paymentSessionUnconfirmed: false,
  paymentReconciliationRequired: false,
  refundId: null,
  refundAttempt: 0,
  refundedTotalCents: 0,
  refundAttemptIsPartial: false,
  refundOutcome: 'none',
  refundErrorCode: null,
  refundErrorMessage: null,
  refundErrorHttpStatus: null,
  subtotal: 20,
  shipping: 0,
  total: 20,
  currency: 'EUR',
  createdAt: '2026-10-05T12:00:00Z',
  items: [{ name: 'Monstera', quantity: 1 }]
};

const withdrawal: AdminWithdrawalRequest = {
  id: 72,
  requestNumber: 'WD-72',
  requestType: 'withdrawal',
  status: 'refund_pending',
  customerMessage: null,
  deliveryReceivedDate: null,
  adminNote: null,
  createdAt: '2026-10-05T12:00:00Z',
  orderNumber: order.number,
  orderStatus: 'delivered',
  paymentStatus: 'paid',
  paymentProvider: 'stripe',
  refundAttempt: 1,
  refundOutcome: 'pending',
  refundErrorCode: null,
  refundErrorMessage: null,
  orderItems: 'Monstera × 1',
  total: 20,
  currency: 'EUR',
  customerName: 'Ana Example',
  email: 'ana@example.test'
};

describe('admin financial operations', () => {
  let fixture: ComponentFixture<AdminFinancialOperationsComponent>;
  let component: AdminFinancialOperationsComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [AdminFinancialOperationsComponent] }).compileComponents();
    fixture = TestBed.createComponent(AdminFinancialOperationsComponent);
    component = fixture.componentInstance;
  });

  it('exposes unresolved historical references in EN and FR and offers only history lookup instead of new refunds', () => {
    const historical = { ...order, status: 'confirmed', paymentStatus: 'partially_refunded', refundOutcome: 'definitive_failure' as const,
      refundAttempt: 3, refundedTotalCents: 500, historicalRefunds: [
        { attempt: 1, kind: 'partial' as const, id: 're_historical', amountCents: 500, status: 'pending' as const },
        { attempt: 2, kind: 'full' as const, id: null, amountCents: 1500, status: 'unknown' as const }
      ] };
    component.orders = [historical];
    const actions: string[] = [];
    component.refundOrderAction.subscribe(({ action }) => actions.push(action));
    fixture.detectChanges();
    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('An earlier refund is still pending or unknown');
    expect(text).toContain('re_historical');
    expect(text).toContain('Provider reference not yet known');
    let buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    expect(buttons.some((button) => /Partial refund|Retry refund|Cancel & refund/.test(button.textContent ?? ''))).toBe(false);
    buttons.find((button) => button.textContent?.includes('Check refund history'))!.click();
    expect(actions).toEqual(['check_refund_history']);
    component.language = 'fr';
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('Un remboursement antérieur est encore en attente ou inconnu');
    expect(fixture.nativeElement.textContent).toContain('Référence du prestataire encore inconnue');
    buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    expect(buttons.some((button) => /Remboursement partiel|Réessayer le remboursement|Annuler et rembourser/.test(button.textContent ?? ''))).toBe(false);
    expect(buttons.some((button) => button.textContent?.includes('Vérifier l’historique des remboursements'))).toBe(true);
  });

  it('keeps cancellation unavailable while Stripe session creation is ambiguous', () => {
    component.orders = [{ ...order, paymentSessionUnconfirmed: true }];
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent as string;
    const buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    expect(text).toContain('Only retry this same order');
    expect(buttons.some((button) => button.textContent?.includes('Cancel order'))).toBe(false);
    expect(buttons.some((button) => button.textContent?.includes('Resolve verified unpaid attempt'))).toBe(false);
  });

  it('offers only verified-unpaid resolution for an unresolved reconciliation and emits that order', () => {
    const unresolved = { ...order, paymentReconciliationRequired: true };
    const handled: AdminOrder[] = [];
    component.orders = [unresolved];
    component.resolveCheckoutAttempt.subscribe((value) => handled.push(value));
    fixture.detectChanges();

    const buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    const button = buttons.find((candidate) => candidate.textContent?.includes('Resolve verified unpaid attempt'));
    expect(button).toBeDefined();
    button?.click();
    expect(handled).toEqual([unresolved]);
    expect(buttons.some((candidate) => candidate.textContent?.includes('Cancel order'))).toBe(false);
  });

  it('keeps refund retry limited to terminal failures and routes pending returns to status check', () => {
    const partial = {
      ...order,
      status: 'shipped',
      paymentStatus: 'partially_refunded',
      refundAttempt: 2,
      refundAttemptIsPartial: true,
      refundOutcome: 'definitive_failure' as const,
      refundedTotalCents: 5000
    };
    const refundActions: Array<{ order: AdminOrder; action: string; resolution?: 'refunded' | 'not_refunded' }> = [];
    const withdrawalActions: Array<{ request: AdminWithdrawalRequest; action: string }> = [];
    component.orders = [partial];
    component.withdrawals = [withdrawal];
    component.language = 'fr';
    component.priceFormatter = (amount, currency = 'EUR') => `${currency} ${amount.toFixed(2)}`;
    component.refundOrderAction.subscribe((event) => refundActions.push(event));
    component.withdrawalAction.subscribe((event) => withdrawalActions.push(event));
    fixture.detectChanges();

    const buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    const checkRefund = buttons.find((button) => button.textContent?.includes('Consulter le remboursement'));
    expect(checkRefund).toBeDefined();
    expect(buttons.some((button) => button.textContent?.includes('Réessayer le remboursement'))).toBe(false);
    checkRefund?.click();
    buttons.find((button) => button.textContent?.includes('Vérifier le remboursement Stripe'))?.click();

    expect(refundActions).toEqual([{ order: partial, action: 'check_refund' }]);
    expect(withdrawalActions).toEqual([{ request: withdrawal, action: 'check_refund' }]);
    expect(fixture.nativeElement.textContent).toContain('EUR 20.00');
    expect(component.withdrawalStatusLabel('refund_pending')).toBe('Remboursement en cours de confirmation');
  });
  it('offers remaining-balance cancellation and retry for a full attempt after a partial refund', () => {
    const partial = { ...order, status: 'confirmed', paymentStatus: 'partially_refunded', refundAttempt: 2,
      refundAttemptIsPartial: false, refundOutcome: 'definitive_failure' as const, refundedTotalCents: 5000 };
    const cancelled: AdminOrder[] = [];
    const actions: string[] = [];
    component.orders = [partial];
    component.cancelOrder.subscribe((value) => cancelled.push(value));
    component.refundOrderAction.subscribe((value) => actions.push(value.action));
    fixture.detectChanges();
    const buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    buttons.find((button) => button.textContent?.includes('Cancel & refund balance'))?.click();
    buttons.find((button) => button.textContent?.includes('Retry refund'))?.click();
    expect(cancelled).toEqual([partial]); expect(actions).toEqual(['retry_refund']);
    component.orders = [{ ...partial, externalRefundUnmatched: true }];
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).not.toContain('Cancel & refund balance');
    expect(fixture.nativeElement.textContent).not.toContain('Retry refund');
  });
  it('offers a cancelled settled balance without permitting unknown or externally unreviewed refunds', () => {
    const cancelled = { ...order, status: 'cancelled', paymentStatus: 'partially_refunded', refundAttempt: 2,
      refundAttemptIsPartial: false, refundOutcome: 'succeeded' as const, refundedTotalCents: 500 };
    component.orders = [cancelled];
    const selected: AdminOrder[] = [];
    component.cancelOrder.subscribe((value) => selected.push(value));
    fixture.detectChanges();
    const buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    buttons.find((button) => button.textContent?.includes('Refund remaining balance'))?.click();
    expect(selected).toEqual([cancelled]);
    for (const values of [{ refundOutcome: 'unknown' as const }, { externalRefundUnmatched: true }]) {
      component.orders = [{ ...cancelled, ...values }]; fixture.detectChanges();
      expect(fixture.nativeElement.textContent).not.toContain('Refund remaining balance');
    }
  });
});
