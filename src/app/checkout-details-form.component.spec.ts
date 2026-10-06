import { TestBed } from '@angular/core/testing';

import { CurrencyRateMetadata } from './core/catalog.service';
import { CheckoutDetailsFormComponent } from './checkout-details-form.component';

describe('CheckoutDetailsFormComponent', () => {
  const currentRate: CurrencyRateMetadata = {
    currencyRateDate: '2026-10-02',
    currencyRateSource: 'ecb-daily-reference',
    currencyRateType: 'daily_reference',
    currencyRateToken: 'a'.repeat(64),
    currencyRateStatus: 'current',
    currencyRateCheckedAt: '2026-10-04T12:00:00Z'
  };

  function create(overrides: Partial<CheckoutDetailsFormComponent> = {}) {
    TestBed.configureTestingModule({ imports: [CheckoutDetailsFormComponent] });
    const fixture = TestBed.createComponent(CheckoutDetailsFormComponent);
    fixture.componentRef.setInput('fullName', overrides.fullName ?? 'Ana Silva');
    fixture.componentRef.setInput('email', overrides.email ?? 'ana@example.com');
    fixture.componentRef.setInput('authenticated', overrides.authenticated ?? true);
    fixture.componentRef.setInput('language', overrides.language ?? 'en');
    fixture.componentRef.setInput('currency', overrides.currency ?? 'EUR');
    fixture.componentRef.setInput('selectedMethod', overrides.selectedMethod ?? 'bancontact');
    fixture.componentRef.setInput('rateMetadata', overrides.rateMetadata === undefined ? currentRate : overrides.rateMetadata);
    fixture.componentRef.setInput('euroPerDollar', overrides.euroPerDollar === undefined ? 0.92 : overrides.euroPerDollar);
    fixture.detectChanges();
    return fixture;
  }

  it('keeps the existing contact field names, validation constraints, and account email lock', () => {
    const fixture = create();
    const element: HTMLElement = fixture.nativeElement;
    const name = element.querySelector<HTMLInputElement>('[name="checkoutName"]');
    const email = element.querySelector<HTMLInputElement>('[name="checkoutEmail"]');

    expect(name?.value).toBe('Ana Silva');
    expect(name?.required).toBe(true);
    expect(name?.minLength).toBe(2);
    expect(name?.maxLength).toBe(120);
    expect(name?.labels?.length).toBe(1);
    expect(email?.value).toBe('ana@example.com');
    expect(email?.type).toBe('email');
    expect(email?.readOnly).toBe(true);
    expect(email?.labels?.length).toBe(1);
    fixture.destroy();
  });

  it('emits changed contact values and leaves parent state ownership to the host', () => {
    const fixture = create({ authenticated: false });
    const changed = vi.fn();
    fixture.componentInstance.contactChange.subscribe(changed);
    const name = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>('[name="checkoutName"]')!;
    name.value = 'Maria';
    name.dispatchEvent(new Event('input', { bubbles: true }));
    fixture.detectChanges();

    expect(changed).toHaveBeenCalledWith({ field: 'name', value: 'Maria' });
    expect(fixture.componentInstance.fullName).toBe('Ana Silva');
    fixture.destroy();
  });

  it('shows the advisory ECB conversion and distinguishes informational reference from settlement', () => {
    const fixture = create();
    const element: HTMLElement = fixture.nativeElement;
    const note = element.querySelector('.payment-local-note')!;

    expect(note.textContent).toContain('Stripe charges this order in EUR');
    expect(note.textContent).toContain('ECB daily reference dated 2026-10-02');
    expect(note.textContent).toContain('1 USD = 0.9200 EUR');
    expect(note.textContent).toContain('not a live FX quote');
    expect(note.textContent).toContain('Stripe settlement rate');
    fixture.destroy();
  });

  it('clearly states checkout is paused when no current ECB reference is available', () => {
    const fixture = create({
      rateMetadata: { ...currentRate, currencyRateDate: null, currencyRateToken: null, currencyRateStatus: 'unavailable' },
      euroPerDollar: null
    });
    const note: HTMLElement = (fixture.nativeElement as HTMLElement).querySelector('.payment-local-note')!;

    expect(note.textContent).toContain('checkout is paused');
    expect(note.textContent).not.toContain('1 USD =');
    fixture.destroy();
  });

  it('explains when another request is refreshing a still-valid cached ECB reference', () => {
    const fixture = create({ rateMetadata: { ...currentRate, currencyRateStatus: 'refresh_in_progress' } });
    const note: HTMLElement = (fixture.nativeElement as HTMLElement).querySelector('.payment-local-note')!;

    expect(note.textContent).toContain('using the recent cached reference while its refresh completes');
    fixture.destroy();
  });

  it('localizes payment information and forwards only the selected supported method', () => {
    const fixture = create({ language: 'fr', currency: 'EUR', selectedMethod: 'bancontact' });
    const element: HTMLElement = fixture.nativeElement;
    const selected = vi.fn();
    fixture.componentInstance.selectedMethodChange.subscribe(selected);
    const radios = [...element.querySelectorAll<HTMLInputElement>('input[name="securePayment"]')];

    expect(element.querySelector('.contact-fieldset legend')?.textContent).toContain('Contact');
    expect(element.querySelector('.payment-fieldset legend')?.textContent).toContain('Mode de paiement');
    expect(element.querySelector('[role="radiogroup"]')?.getAttribute('aria-label')).toBe('Confirmer le mode de paiement');
    expect(radios.every((radio) => !radio.disabled)).toBe(true);
    radios[1].click();
    expect(selected).toHaveBeenCalledOnce();
    expect(selected).toHaveBeenCalledWith('card');
    expect(element.querySelector('.payment-safety')?.textContent).toContain('sans stockage des données brutes de carte');
    fixture.destroy();
  });
});
