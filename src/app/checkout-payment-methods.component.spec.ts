import { TestBed } from '@angular/core/testing';

import { CheckoutPaymentMethodsComponent } from './checkout-payment-methods.component';

describe('CheckoutPaymentMethodsComponent', () => {
  function create(currency: 'EUR' | 'USD' = 'EUR', language: 'en' | 'fr' = 'en', selectedMethod: 'bancontact' | 'card' | 'sepa_debit' = 'bancontact') {
    TestBed.configureTestingModule({ imports: [CheckoutPaymentMethodsComponent] });
    const fixture = TestBed.createComponent(CheckoutPaymentMethodsComponent);
    fixture.componentRef.setInput('currency', currency);
    fixture.componentRef.setInput('language', language);
    fixture.componentRef.setInput('selectedMethod', selectedMethod);
    fixture.detectChanges();
    return fixture;
  }

  it('renders the real Belgian EUR choices with an accessible group name', () => {
    const fixture = create();
    const element: HTMLElement = fixture.nativeElement;
    const radios = [...element.querySelectorAll<HTMLInputElement>('input[type="radio"]')];

    expect(element.querySelector('[role="radiogroup"]')?.getAttribute('aria-label')).toBe('Confirm your payment method');
    expect(radios.map((radio) => radio.value)).toEqual(['bancontact', 'card', 'sepa_debit']);
    expect(radios.every((radio) => !radio.disabled)).toBe(true);
    expect(radios[0].checked).toBe(true);
    expect(element.textContent).toContain('Belgian bank app · EUR only');
    fixture.destroy();
  });

  it('disables EUR-only methods for USD without changing the selected card method', () => {
    const fixture = create('USD', 'en', 'card');
    const radios = [...(fixture.nativeElement as HTMLElement).querySelectorAll<HTMLInputElement>('input[type="radio"]')];
    const changed = vi.fn();
    fixture.componentInstance.selectedMethodChange.subscribe(changed);

    expect(radios.map((radio) => radio.disabled)).toEqual([true, false, true]);
    expect(radios[1].checked).toBe(true);
    fixture.componentInstance.selectPaymentMethod('bancontact');
    expect(changed).not.toHaveBeenCalled();
    fixture.destroy();
  });

  it('emits only an enabled user-selected payment method', () => {
    const fixture = create('EUR', 'en', 'bancontact');
    const changed = vi.fn();
    fixture.componentInstance.selectedMethodChange.subscribe(changed);
    const card = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>('input[value="card"]');

    card?.click();
    expect(changed).toHaveBeenCalledOnce();
    expect(changed).toHaveBeenCalledWith('card');
    fixture.destroy();
  });

  it('localizes method details in French', () => {
    const fixture = create('EUR', 'fr', 'sepa_debit');
    const element: HTMLElement = fixture.nativeElement;

    expect(element.querySelector('[role="radiogroup"]')?.getAttribute('aria-label')).toBe('Confirmer le mode de paiement');
    expect(element.textContent).toContain('Prélèvement SEPA');
    expect(element.textContent).toContain('Application bancaire belge · EUR uniquement');
    fixture.destroy();
  });
});
