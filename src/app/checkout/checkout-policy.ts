export interface DeliveryCountry {
  readonly code: string;
  readonly en: string;
  readonly fr: string;
}

export interface CheckoutPreviewData {
  name: string;
  email: string;
  address: string;
  city: string;
  postalCode: string;
  region: string;
  country: string;
  delivery: string;
}

export type CheckoutPreviewIssue = 'contact' | 'address' | 'delivery' | null;
export type CheckoutPaymentMethod = 'bancontact' | 'card' | 'sepa_debit';
export type CheckoutCurrency = 'EUR' | 'USD';

export const DELIVERY_COUNTRIES: readonly DeliveryCountry[] = [
  { code: 'BE', en: 'Belgium', fr: 'Belgique' },
  { code: 'NL', en: 'Netherlands', fr: 'Pays-Bas' },
  { code: 'FR', en: 'France', fr: 'France' },
  { code: 'DE', en: 'Germany', fr: 'Allemagne' },
  { code: 'LU', en: 'Luxembourg', fr: 'Luxembourg' },
  { code: 'AT', en: 'Austria', fr: 'Autriche' },
  { code: 'BG', en: 'Bulgaria', fr: 'Bulgarie' },
  { code: 'HR', en: 'Croatia', fr: 'Croatie' },
  { code: 'CY', en: 'Cyprus', fr: 'Chypre' },
  { code: 'CZ', en: 'Czechia', fr: 'Tchéquie' },
  { code: 'DK', en: 'Denmark', fr: 'Danemark' },
  { code: 'EE', en: 'Estonia', fr: 'Estonie' },
  { code: 'FI', en: 'Finland', fr: 'Finlande' },
  { code: 'GR', en: 'Greece', fr: 'Grèce' },
  { code: 'HU', en: 'Hungary', fr: 'Hongrie' },
  { code: 'IE', en: 'Ireland', fr: 'Irlande' },
  { code: 'IT', en: 'Italy', fr: 'Italie' },
  { code: 'LV', en: 'Latvia', fr: 'Lettonie' },
  { code: 'LT', en: 'Lithuania', fr: 'Lituanie' },
  { code: 'MT', en: 'Malta', fr: 'Malte' },
  { code: 'PL', en: 'Poland', fr: 'Pologne' },
  { code: 'PT', en: 'Portugal', fr: 'Portugal' },
  { code: 'RO', en: 'Romania', fr: 'Roumanie' },
  { code: 'SK', en: 'Slovakia', fr: 'Slovaquie' },
  { code: 'SI', en: 'Slovenia', fr: 'Slovénie' },
  { code: 'ES', en: 'Spain', fr: 'Espagne' },
  { code: 'SE', en: 'Sweden', fr: 'Suède' }
];

export function checkoutPreviewIssue(data: CheckoutPreviewData): CheckoutPreviewIssue {
  const email = data.email.trim();
  if (data.name.trim().length < 2 || email.length > 180 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return 'contact';
  }
  if (data.address.trim().length < 5 || data.city.trim().length < 2
      || data.postalCode.trim().length < 3 || data.country.trim().length !== 2) {
    return 'address';
  }
  return data.delivery ? null : 'delivery';
}

export function checkoutPaymentMethodForCurrency(
  currency: CheckoutCurrency,
  method: CheckoutPaymentMethod
): CheckoutPaymentMethod {
  return currency === 'USD' && method !== 'card' ? 'card' : method;
}

export function shippingQuoteKey(
  country: string,
  postalCode: string,
  items: ReadonlyArray<{ id: number; quantity: number }>
): string {
  const normalizedItems = items
    .map(({ id, quantity }) => `${id}:${quantity}`)
    .sort((left, right) => left.localeCompare(right, 'en', { numeric: true }))
    .join(',');
  return `${country.trim().toUpperCase()}|${postalCode.trim().toUpperCase()}|${normalizedItems}`;
}
