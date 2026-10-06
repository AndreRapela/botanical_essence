import { TestBed } from '@angular/core/testing';

import { CheckoutFinalReviewComponent } from './checkout-final-review.component';

describe('CheckoutFinalReviewComponent', () => {
  function create(overrides: Partial<CheckoutFinalReviewComponent> = {}) {
    TestBed.configureTestingModule({ imports: [CheckoutFinalReviewComponent] });
    const fixture = TestBed.createComponent(CheckoutFinalReviewComponent);
    fixture.componentRef.setInput('language', overrides.language ?? 'en');
    fixture.componentRef.setInput('itemCount', overrides.itemCount ?? 2);
    fixture.componentRef.setInput('deliveryLabel', overrides.deliveryLabel ?? 'bpost home delivery');
    fixture.componentRef.setInput('paymentMethodLabel', overrides.paymentMethodLabel ?? 'Credit or debit card');
    fixture.componentRef.setInput('totalLabel', overrides.totalLabel ?? '€18.41');
    fixture.componentRef.setInput('paymentReady', overrides.paymentReady ?? true);
    fixture.componentRef.setInput('hasShippingQuote', overrides.hasShippingQuote ?? true);
    fixture.componentRef.setInput('checkoutError', overrides.checkoutError ?? '');
    fixture.componentRef.setInput('paymentStarting', overrides.paymentStarting ?? false);
    fixture.componentRef.setInput('paymentVerificationLoading', overrides.paymentVerificationLoading ?? false);
    fixture.detectChanges();
    return fixture;
  }

  it('shows the complete final order facts and the final secure-payment action', () => {
    const fixture = create();
    const element: HTMLElement = fixture.nativeElement;
    const button = element.querySelector<HTMLButtonElement>('.purchase-final-button');

    expect(element.querySelector('#purchase-final-title')?.textContent).toContain('Everything looks right?');
    expect(element.querySelector('.purchase-final-facts')?.textContent).toContain('2');
    expect(element.querySelector('.purchase-final-facts')?.textContent).toContain('bpost home delivery');
    expect(element.querySelector('.purchase-final-facts')?.textContent).toContain('Credit or debit card');
    expect(button?.textContent).toContain('Continue to secure payment');
    expect(button?.textContent).toContain('€18.41');
    expect(button?.disabled).toBe(false);
    fixture.destroy();
  });

  it('keeps the address-incomplete prompt actionable and emits only the user action', () => {
    const fixture = create({ hasShippingQuote: false });
    const component = fixture.componentInstance;
    const started = vi.fn();
    component.startPurchase.subscribe(started);
    const button = (fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>('.purchase-final-button');

    expect(button?.textContent).toContain('Calculate delivery to continue');
    expect(button?.disabled).toBe(false);
    button?.click();
    expect(started).toHaveBeenCalledOnce();
    fixture.destroy();
  });

  it('disables checkout while provider readiness, payment start, or verification is unresolved', () => {
    const fixture = create({ paymentReady: false });
    const button = (fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>('.purchase-final-button');
    expect(button?.disabled).toBe(true);

    fixture.componentRef.setInput('paymentReady', true);
    fixture.componentRef.setInput('paymentStarting', true);
    fixture.detectChanges();
    expect(button?.disabled).toBe(true);

    fixture.componentRef.setInput('paymentStarting', false);
    fixture.componentRef.setInput('paymentVerificationLoading', true);
    fixture.detectChanges();
    expect(button?.disabled).toBe(true);
    fixture.destroy();
  });

  it('shows errors and pending states as accessible live announcements', () => {
    const fixture = create({ checkoutError: 'Delivery quote has expired', paymentStarting: true, paymentVerificationLoading: true });
    const element: HTMLElement = fixture.nativeElement;

    expect(element.querySelector('[role="alert"]')?.textContent).toContain('Delivery quote has expired');
    expect(element.querySelectorAll('[role="status"]').length).toBe(2);
    expect(element.textContent).toContain('Your order is being validated and reserved.');
    expect(element.textContent).toContain('We are checking the payment confirmation with the provider.');
    fixture.destroy();
  });

  it('localizes the withdrawal link and purchase prompt in French', () => {
    const fixture = create({ language: 'fr', hasShippingQuote: false });
    const component = fixture.componentInstance;
    const openReturns = vi.fn();
    component.openReturns.subscribe(openReturns);
    const element: HTMLElement = fixture.nativeElement;

    expect(element.querySelector('#purchase-final-title')?.textContent).toContain('Tout est correct ?');
    expect(element.querySelector('.withdrawal-checkout-note')?.textContent).toContain('procédure de rétractation et de retour');
    expect(element.querySelector('.purchase-final-button')?.textContent).toContain('Calculez la livraison pour continuer');
    element.querySelector<HTMLButtonElement>('.withdrawal-checkout-note button')?.click();
    expect(openReturns).toHaveBeenCalledOnce();
    fixture.destroy();
  });
});
