import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { Language } from './storefront.models';

@Component({
  selector: 'app-checkout-final-review',
  standalone: true,
  templateUrl: './checkout-final-review.component.html',
  styleUrl: './checkout-final-review.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class CheckoutFinalReviewComponent {
  @Input({ required: true }) language!: Language;
  @Input({ required: true }) itemCount!: number;
  @Input({ required: true }) deliveryLabel!: string;
  @Input({ required: true }) paymentMethodLabel!: string;
  @Input({ required: true }) totalLabel!: string;
  @Input({ required: true }) paymentReady!: boolean;
  @Input() hasShippingQuote = false;
  @Input() checkoutError = '';
  @Input() paymentStarting = false;
  @Input() paymentVerificationLoading = false;

  @Output() readonly startPurchase = new EventEmitter<void>();
  @Output() readonly openReturns = new EventEmitter<void>();

  get purchaseDisabled(): boolean {
    return this.paymentStarting || this.paymentVerificationLoading || !this.paymentReady;
  }

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }
}
