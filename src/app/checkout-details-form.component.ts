import { CommonModule } from '@angular/common';
import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { CurrencyRateMetadata } from './core/catalog.service';
import { CheckoutPaymentMethod } from './checkout/checkout-policy';
import { Currency, Language } from './storefront.models';
import { CheckoutPaymentMethodsComponent } from './checkout-payment-methods.component';

export interface CheckoutContactChange {
  field: 'name' | 'email';
  value: string;
}

@Component({
  selector: 'app-checkout-details-form',
  standalone: true,
  imports: [CommonModule, CheckoutPaymentMethodsComponent],
  templateUrl: './checkout-details-form.component.html',
  styleUrl: './checkout-details-form.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class CheckoutDetailsFormComponent {
  @Input() fullName = '';
  @Input() email = '';
  @Input() authenticated = false;
  @Input() language: Language = 'en';
  @Input() currency: Currency = 'EUR';
  @Input() selectedMethod: CheckoutPaymentMethod = 'card';
  @Input() rateMetadata: CurrencyRateMetadata | null = null;
  @Input() euroPerDollar: number | null = null;

  @Output() readonly contactChange = new EventEmitter<CheckoutContactChange>();
  @Output() readonly selectedMethodChange = new EventEmitter<CheckoutPaymentMethod>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }

  updateContact(field: CheckoutContactChange['field'], value: string): void {
    this.contactChange.emit({ field, value });
  }
}
