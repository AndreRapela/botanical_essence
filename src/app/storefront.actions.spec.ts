import { signal } from '@angular/core';

import { convertCurrency, formatPrice, initialCurrency, initialLanguage } from './storefront.actions';
import type { CurrencyConversionContext, PriceFormattingContext } from './storefront.actions';
import type { Currency } from './storefront.models';

describe('storefront presentation actions', () => {
  beforeEach(() => window.localStorage.clear());

  it('loads only supported language and currency preferences and falls back safely', () => {
    window.localStorage.setItem('botanical-language', 'fr');
    window.localStorage.setItem('botanical-currency', 'USD');
    expect(initialLanguage()).toBe('fr');
    expect(initialCurrency()).toBe('USD');

    window.localStorage.setItem('botanical-language', 'unsupported');
    window.localStorage.setItem('botanical-currency', 'GBP');
    expect(initialLanguage()).toBe('en');
    expect(initialCurrency()).toBe('EUR');
  });

  it('converts through the current EUR-per-USD reference and fails closed without it', () => {
    const availableRates = {
      catalog: { currencyRates: signal<Record<Currency, number | null>>({ USD: 1, EUR: 0.9 }) }
    } satisfies CurrencyConversionContext;
    const unavailableRates = {
      catalog: { currencyRates: signal<Record<Currency, number | null>>({ USD: 1, EUR: null }) }
    } satisfies CurrencyConversionContext;

    expect(convertCurrency.call(availableRates, 12, 'USD', 'EUR')).toBe(10.8);
    expect(convertCurrency.call(availableRates, 10.8, 'EUR', 'USD')).toBe(12);
    expect(convertCurrency.call(availableRates, 12, 'USD', 'USD')).toBe(12);
    expect(Number.isNaN(convertCurrency.call(unavailableRates, 12, 'USD', 'EUR'))).toBe(true);
  });

  it('uses the selected currency locale and gives a clear fallback for unavailable conversion', () => {
    const context = {
      currentCurrency: 'EUR',
      locale: 'en-IE',
      convertCurrency: () => 9,
      copy: (english: string) => english
    } satisfies PriceFormattingContext;

    expect(formatPrice.call(context, 10, 'USD')).toBe(
      (9).toLocaleString(context.locale, { style: 'currency', currency: 'EUR' })
    );
    context.convertCurrency = () => Number.NaN;
    expect(formatPrice.call(context, 10, 'USD')).toContain('Price unavailable');
  });
});
