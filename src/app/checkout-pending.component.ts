import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { Currency, Language } from './storefront.models';

@Component({
  selector: 'app-checkout-pending',
  standalone: true,
  templateUrl: './checkout-pending.component.html',
  styleUrl: './checkout-pending.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class CheckoutPendingComponent {
  @Input() orderNumber = '';
  @Input() orderTotal: number | null = null;
  @Input() currency: Currency = 'EUR';
  @Input() error = '';
  @Input() hasResumeUrl = false;
  @Input() canRetry = false;
  @Input() canCancel = false;
  @Input() orderCancelled = false;
  @Input() statusLoading = false;
  @Input() verificationLoading = false;
  @Input() paymentStarting = false;
  @Input() language: Language = 'en';
  @Input() priceFormatter: (value: number, currency: Currency) => string = (value, currency) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(value);

  @Output() readonly resume = new EventEmitter<void>();
  @Output() readonly cancel = new EventEmitter<void>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }

  get resumeLabel(): string {
    if (this.hasResumeUrl) return this.copy('Resume secure payment', 'Reprendre le paiement sécurisé');
    if (this.canRetry) return this.copy('Retry secure payment setup', 'Relancer le paiement sécurisé');
    return this.copy('Check payment status', 'Vérifier le paiement');
  }
}
