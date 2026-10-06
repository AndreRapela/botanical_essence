import { ComponentFixture, TestBed } from '@angular/core/testing';

import { AccountOrderHistoryComponent, CustomerOrderAction } from './account-order-history.component';
import { OrderSummary } from './storefront.models';

const paidOrder: OrderSummary = {
  number: 'BE-20261005-ABCDEF1234',
  status: 'delivered',
  paymentStatus: 'partially_refunded',
  subtotal: 20,
  shipping: 4,
  total: 24,
  currency: 'EUR',
  refundedTotalCents: 500,
  createdAt: '2026-10-05T12:00:00Z',
  fxRateDate: '2026-10-05',
  usdPerEur: 1.1732,
  shippingTariffReviewedAt: '2026-10-01'
};

describe('account order history', () => {
  let fixture: ComponentFixture<AccountOrderHistoryComponent>;
  let component: AccountOrderHistoryComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [AccountOrderHistoryComponent] }).compileComponents();
    fixture = TestBed.createComponent(AccountOrderHistoryComponent);
    component = fixture.componentInstance;
    component.priceFormatter = (amount, currency = 'EUR') => `${currency} ${amount.toFixed(2)}`;
  });

  it('shows the exact remaining balance and emits typed consumer actions only for an eligible order', () => {
    const actions: CustomerOrderAction[] = [];
    component.orders = [paidOrder];
    component.language = 'fr';
    component.requestAction.subscribe((action) => actions.push(action));
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('restant : EUR 19.00');
    expect(text).toContain('Référence BCE');
    expect(text).toContain('Tarif bpost vérifié');
    const buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    buttons.find((button) => button.textContent?.includes('Me rétracter'))?.click();
    buttons.find((button) => button.textContent?.includes('Signaler un problème'))?.click();
    expect(actions).toEqual([
      { order: paidOrder, type: 'withdrawal' },
      { order: paidOrder, type: 'problem' }
    ]);
  });

  it('does not show consumer actions for cancelled, unpaid, or already-open requests', () => {
    component.orders = [
      { ...paidOrder, number: 'cancelled', status: 'cancelled' },
      { ...paidOrder, number: 'unpaid', paymentStatus: 'pending' },
      { ...paidOrder, number: 'open', withdrawalStatus: 'reviewing', withdrawalRequestNumber: 'WD-41' }
    ];
    component.language = 'en';
    fixture.detectChanges();

    const buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    expect(buttons.some((button) => button.textContent?.includes('Withdraw from this order'))).toBe(false);
    expect(buttons.some((button) => button.textContent?.includes('Report a problem'))).toBe(false);
    expect(fixture.nativeElement.textContent).toContain('Under review · WD-41');
  });

  it('keeps history errors visible and disables pagination while loading', () => {
    component.orders = [];
    component.error = 'History could not be loaded.';
    component.hasMore = true;
    component.loading = true;
    fixture.detectChanges();

    const button = fixture.nativeElement.querySelector('button') as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(button.textContent).toContain('Loading');
    expect(fixture.nativeElement.textContent).toContain('History could not be loaded.');
  });

  it('requests the next history page without changing the current orders', () => {
    const requested: number[] = [];
    component.orders = [paidOrder];
    component.hasMore = true;
    component.loadMore.subscribe(() => requested.push(component.orders.length));
    fixture.detectChanges();

    const buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    const button = buttons.find((candidate) => candidate.textContent?.includes('Load older orders'));
    expect(button).toBeDefined();
    button?.click();
    expect(requested).toEqual([1]);
    expect(component.orders).toEqual([paidOrder]);
  });
});
