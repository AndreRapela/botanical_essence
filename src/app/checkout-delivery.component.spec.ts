import { ComponentFixture, TestBed } from '@angular/core/testing';

import { CheckoutPreviewData, DELIVERY_COUNTRIES } from './checkout/checkout-policy';
import { CheckoutDeliveryComponent } from './checkout-delivery.component';
import { ShippingQuote, ShippingQuoteResponse } from './storefront.models';

const form: CheckoutPreviewData = {
  name: 'Ana Customer', email: 'ana@example.com', address: '', city: '', postalCode: '', region: '', country: 'BE', delivery: ''
};

const homeQuote: ShippingQuote = {
  id: 'bpost_home', label: 'Home delivery', amount: 4.9, amountCents: 490, currency: 'EUR',
  estimatedDays: '1-2 business days', description: 'Estimate only', kind: 'home'
};

const response: ShippingQuoteResponse = {
  carrier: 'bpost', quoteType: 'estimate', referenceYear: 2026, source: 'Public rates', tariffVersion: '2026-v1',
  tariffReviewedAt: '2026-10-01', tariffValidThrough: '2026-12-31', tariffBasis: 'Reference', tariffReference: 'https://www.bpost.be/en',
  liveCarrierQuote: false, contractualRate: false, labelCreationAvailable: false, trackingAvailable: false, currency: 'EUR',
  destination: { countryCode: 'BE', postalCode: '1000', zone: 'belgium' },
  parcel: { weightGrams: 1250, weightKilograms: 1.25, packagingWeightGrams: 250 }, quotes: [homeQuote]
};

describe('checkout delivery form', () => {
  let fixture: ComponentFixture<CheckoutDeliveryComponent>;
  let component: CheckoutDeliveryComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [CheckoutDeliveryComponent] }).compileComponents();
    fixture = TestBed.createComponent(CheckoutDeliveryComponent);
    component = fixture.componentInstance;
    component.checkoutForm = { ...form };
    component.countries = DELIVERY_COUNTRIES;
  });

  it('renders a country-aware address form and clearly identifies the local carrier estimate', () => {
    component.shippingQuoteResponse = response;
    component.shippingQuotes = [homeQuote];
    component.selectedShippingQuote = homeQuote;
    component.priceFormatter = (amount, currency) => `${currency} ${amount.toFixed(2)}`;
    fixture.detectChanges();

    const countryOptions = Array.from(fixture.nativeElement.querySelectorAll('select[name="checkoutCountry"] option')) as HTMLOptionElement[];
    expect(countryOptions.length).toBeGreaterThan(20);
    expect(countryOptions.find((option) => option.value === 'BE')?.textContent).toContain('Belgium');
    expect(fixture.nativeElement.textContent).toContain('Not a live bpost quote');
    expect(fixture.nativeElement.textContent).toContain('EUR 4.90');
    expect(fixture.nativeElement.textContent).toContain('does not book a shipment');
  });

  it('invalidates changed destinations and emits explicit quote-calculation requests', () => {
    const invalidations: boolean[] = [];
    const calculations: boolean[] = [];
    component.invalidateQuote.subscribe(() => invalidations.push(true));
    component.calculateQuote.subscribe(() => calculations.push(true));
    fixture.detectChanges();

    const country = fixture.nativeElement.querySelector('select[name="checkoutCountry"]') as HTMLSelectElement;
    country.value = 'FR';
    country.dispatchEvent(new Event('change', { bubbles: true }));
    const postalCode = fixture.nativeElement.querySelector('input[name="postalCode"]') as HTMLInputElement;
    postalCode.value = '75001';
    postalCode.dispatchEvent(new Event('input', { bubbles: true }));
    postalCode.dispatchEvent(new Event('blur'));
    (fixture.nativeElement.querySelector('.shipping-empty .button') as HTMLButtonElement).click();

    expect(component.checkoutForm.country).toBe('FR');
    expect(component.checkoutForm.postalCode).toBe('75001');
    expect(invalidations.length).toBeGreaterThanOrEqual(2);
    expect(calculations).toEqual([true, true]);
  });

  it('emits the selected server quote while preserving localized labels', () => {
    const pickupQuote = { ...homeQuote, id: 'bpost_pickup', kind: 'pickup' as const };
    component.language = 'fr';
    component.shippingQuotes = [pickupQuote];
    component.priceFormatter = (amount, currency) => `${currency} ${amount.toFixed(2)}`;
    const selected: ShippingQuote[] = [];
    component.selectQuote.subscribe((quote) => selected.push(quote));
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Point d’enlèvement ou Distributeur de Colis');
    const radio = fixture.nativeElement.querySelector('input[name="delivery"]') as HTMLInputElement;
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));

    expect(selected).toEqual([pickupQuote]);
    expect(component.checkoutForm.delivery).toBe('bpost_pickup');
  });

  it('keeps guaranteed and cross-border estimate labels explicit in both languages', () => {
    const guaranteed = { ...homeQuote, id: 'bpost_home_guaranteed' };
    const international = { ...homeQuote, id: 'eu_home_delivery' };

    expect(component.shippingLabel(guaranteed)).toBe('Home delivery with warranty');
    expect(component.shippingDescription(guaranteed)).toBe('Estimate only · warranty is not purchased here');
    expect(component.shippingLabel(international)).toBe('International home delivery');
    expect(component.shippingDescription(international)).toBe('Estimate only · no label or tracking is created here');

    component.language = 'fr';
    expect(component.shippingLabel(guaranteed)).toBe('Livraison à domicile avec garantie');
    expect(component.shippingDescription(guaranteed)).toBe('Estimation uniquement · la garantie n’est pas achetée ici');
    expect(component.shippingLabel(international)).toBe('Livraison internationale à domicile');
  });
});
