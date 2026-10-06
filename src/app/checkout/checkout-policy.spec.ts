import { checkoutPaymentMethodForCurrency, checkoutPreviewIssue, DELIVERY_COUNTRIES, shippingQuoteKey } from './checkout-policy';

const validCheckout = {
  name: 'Élodie Martin',
  email: 'elodie@example.be',
  address: 'Rue du Marché aux Herbes 18',
  city: 'Bruxelles',
  postalCode: '1000',
  region: 'Bruxelles-Capitale',
  country: 'BE',
  delivery: 'bpost_home'
};

describe('checkout policy', () => {
  it('accepts a complete European checkout preview', () => {
    expect(checkoutPreviewIssue(validCheckout)).toBeNull();
  });

  it.each([
    [{ name: ' ' }, 'contact'],
    [{ email: 'invalid-email' }, 'contact'],
    [{ address: '12' }, 'address'],
    [{ city: '' }, 'address'],
    [{ postalCode: '1' }, 'address'],
    [{ country: 'Belgium' }, 'address'],
    [{ delivery: '' }, 'delivery']
  ] as const)('reports the first incomplete section for %o', (change, issue) => {
    expect(checkoutPreviewIssue({ ...validCheckout, ...change })).toBe(issue);
  });

  it('builds a stable quote key regardless of cart order and input casing', () => {
    const first = shippingQuoteKey('be', ' 1000 ', [{ id: 12, quantity: 1 }, { id: 2, quantity: 3 }]);
    const second = shippingQuoteKey('BE', '1000', [{ id: 2, quantity: 3 }, { id: 12, quantity: 1 }]);
    expect(first).toBe('BE|1000|2:3,12:1');
    expect(second).toBe(first);
  });

  it('keeps the frontend destination allowlist unique and complete for the EU', () => {
    const codes = DELIVERY_COUNTRIES.map(({ code }) => code);
    expect(new Set(codes).size).toBe(codes.length);
    expect(codes).toHaveLength(27);
    expect(codes).toContain('BE');
  });

  it('switches euro-only payment methods to card for USD', () => {
    expect(checkoutPaymentMethodForCurrency('USD', 'bancontact')).toBe('card');
    expect(checkoutPaymentMethodForCurrency('EUR', 'bancontact')).toBe('bancontact');
    expect(checkoutPaymentMethodForCurrency('USD', 'sepa_debit')).toBe('card');
    expect(checkoutPaymentMethodForCurrency('EUR', 'sepa_debit')).toBe('sepa_debit');
    expect(checkoutPaymentMethodForCurrency('USD', 'card')).toBe('card');
  });
});
