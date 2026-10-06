import { ComponentFixture, TestBed } from '@angular/core/testing';

import { CheckoutPendingComponent } from './checkout-pending.component';

describe('checkout pending status', () => {
  let fixture: ComponentFixture<CheckoutPendingComponent>;
  let component: CheckoutPendingComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [CheckoutPendingComponent] }).compileComponents();
    fixture = TestBed.createComponent(CheckoutPendingComponent);
    component = fixture.componentInstance;
  });

  it('does not offer a new order or cancellation while the payment outcome is unresolved', () => {
    component.orderNumber = 'BE-ORDER-1';
    component.canRetry = false;
    component.canCancel = false;
    component.error = 'The payment status is still being checked.';
    fixture.detectChanges();

    const buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    expect(fixture.nativeElement.textContent).toContain('One order at a time.');
    expect(fixture.nativeElement.textContent).toContain('BE-ORDER-1');
    expect(buttons).toHaveLength(1);
    expect(buttons[0].textContent).toContain('Check payment status');
    expect(buttons[0].textContent).not.toContain('new order');
  });

  it('shows and emits only the recovery actions allowed by the authoritative status', () => {
    const resumed: boolean[] = [];
    const cancelled: boolean[] = [];
    component.hasResumeUrl = true;
    component.canRetry = true;
    component.canCancel = true;
    component.orderNumber = 'BE-ORDER-2';
    component.orderTotal = 12.5;
    component.currency = 'EUR';
    component.language = 'fr';
    component.priceFormatter = (value, currency) => `${currency} ${value.toFixed(2)}`;
    component.resume.subscribe(() => resumed.push(true));
    component.cancel.subscribe(() => cancelled.push(true));
    fixture.detectChanges();

    const buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    expect(buttons).toHaveLength(2);
    expect(buttons[0].textContent).toContain('Reprendre le paiement sécurisé');
    expect(buttons[1].textContent).toContain('Annuler cette commande');
    expect(fixture.nativeElement.textContent).toContain('EUR 12.50');
    buttons[0].click();
    buttons[1].click();
    expect(resumed).toEqual([true]);
    expect(cancelled).toEqual([true]);
  });

  it('disables resume during status checks and closes cancelled orders without implying another payment', () => {
    component.orderCancelled = true;
    component.canCancel = true;
    component.statusLoading = true;
    component.language = 'fr';
    fixture.detectChanges();

    const buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    expect(buttons[0].disabled).toBe(true);
    expect(buttons[1].disabled).toBe(true);
    expect(buttons[1].textContent).toContain('Fermer l’ancien paiement');
  });
});
