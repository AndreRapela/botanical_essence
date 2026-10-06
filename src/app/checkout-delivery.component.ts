import { CommonModule } from '@angular/common';
import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { CheckoutPreviewData, DeliveryCountry } from './checkout/checkout-policy';
import { shippingDescription as shippingDescriptionText, shippingLabel as shippingLabelText } from './checkout/shipping-display';
import { Currency, Language, ShippingQuote, ShippingQuoteResponse } from './storefront.models';

@Component({
  selector: 'app-checkout-delivery',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './checkout-delivery.component.html',
  styleUrl: './checkout-delivery.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class CheckoutDeliveryComponent {
  @Input({ required: true }) checkoutForm!: CheckoutPreviewData;
  @Input({ required: true }) countries: readonly DeliveryCountry[] = [];
  @Input() language: Language = 'en';
  @Input() shippingQuoteResponse: ShippingQuoteResponse | null = null;
  @Input() shippingQuotes: ShippingQuote[] = [];
  @Input() selectedShippingQuote: ShippingQuote | null = null;
  @Input() shippingQuoteLoading = false;
  @Input() shippingQuoteError = '';
  @Input() priceFormatter: (value: number, sourceCurrency?: Currency) => string = (value, currency = 'EUR') =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(value);

  @Output() readonly invalidateQuote = new EventEmitter<void>();
  @Output() readonly calculateQuote = new EventEmitter<void>();
  @Output() readonly selectQuote = new EventEmitter<ShippingQuote>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }

  countryLabel(country: DeliveryCountry): string {
    return country[this.language];
  }

  shippingLabel(quote: ShippingQuote): string {
    return shippingLabelText(quote, this.language);
  }

  shippingDescription(quote: ShippingQuote): string {
    return shippingDescriptionText(quote, this.language);
  }
}
