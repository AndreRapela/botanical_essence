import type { CheckoutPaymentMethod } from './checkout/checkout-policy';
import type { Currency, ShippingQuote } from './storefront.models';

export interface CheckoutViewContext {
  cartTotal: number;
  shippingCost: number;
  selectedShippingQuote: ShippingQuote | null;
  currentCurrency: Currency;
  paymentPreviewMethod: CheckoutPaymentMethod;
  convertCurrency(value: number, source: Currency, target: Currency): number;
  copy(english: string, french: string): string;
}

export function checkoutTotal(this: CheckoutViewContext): number {
  return this.cartTotal + this.shippingCost;
}

export function shippingCost(this: CheckoutViewContext): number {
  return this.selectedShippingQuote
    ? this.convertCurrency(this.selectedShippingQuote.amount, 'EUR', this.currentCurrency)
    : 0;
}

export function paymentMethodLabel(this: CheckoutViewContext): string {
  return {
    bancontact: 'Bancontact',
    card: this.copy('Debit or credit card', 'Carte de débit ou crédit'),
    sepa_debit: this.copy('SEPA Direct Debit', 'Prélèvement SEPA')
  }[this.paymentPreviewMethod];
}
