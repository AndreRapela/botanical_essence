import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { CheckoutPaymentMethod } from './checkout/checkout-policy';
import { Currency, Language } from './storefront.models';

@Component({
  selector: 'app-checkout-payment-methods',
  standalone: true,
  templateUrl: './checkout-payment-methods.component.html',
  styleUrl: './checkout-payment-methods.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class CheckoutPaymentMethodsComponent {
  @Input({ required: true }) currency!: Currency;
  @Input({ required: true }) language!: Language;
  @Input({ required: true }) selectedMethod!: CheckoutPaymentMethod;

  @Output() readonly selectedMethodChange = new EventEmitter<CheckoutPaymentMethod>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }

  isDisabled(method: CheckoutPaymentMethod): boolean {
    return method !== 'card' && this.currency !== 'EUR';
  }

  selectPaymentMethod(method: CheckoutPaymentMethod): void {
    if (!this.isDisabled(method) && method !== this.selectedMethod) {
      this.selectedMethodChange.emit(method);
    }
  }
}
