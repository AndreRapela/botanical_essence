import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { Language } from './storefront.models';

export interface CheckoutOrderSummaryItem {
  id: number;
  sprite: number;
  quantity: number;
  seller: string;
  name: string;
  lineTotal: string;
}

@Component({
  selector: 'app-checkout-order-summary',
  standalone: true,
  templateUrl: './checkout-order-summary.component.html',
  styleUrl: './checkout-order-summary.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class CheckoutOrderSummaryComponent {
  @Input({ required: true }) language!: Language;
  @Input({ required: true }) items: readonly CheckoutOrderSummaryItem[] = [];
  @Input({ required: true }) subtotalLabel!: string;
  @Input({ required: true }) subtotalAmount!: string;
  @Input({ required: true }) totalAmount!: string;
  @Input() shippingLoading = false;
  @Input() hasShippingQuote = false;
  @Input() shippingAmount = '';

  @Output() readonly editCart = new EventEmitter<void>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }
}
