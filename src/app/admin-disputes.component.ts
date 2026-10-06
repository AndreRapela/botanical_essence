import { CommonModule } from '@angular/common';
import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { stripeDisputeUrl } from './order.view';
import { AdminPaymentDispute, Currency, Language } from './storefront.models';

@Component({
  selector: 'app-admin-disputes',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './admin-disputes.component.html',
  styleUrl: './admin-disputes.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class AdminDisputesComponent {
  @Input() disputes: AdminPaymentDispute[] = [];
  @Input() language: Language = 'en';
  @Input() loading = false;
  @Input() hasMore = false;
  @Input() total = 0;
  @Input() priceFormatter: (value: number, sourceCurrency?: Currency) => string = (value, currency = 'EUR') =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(value);
  @Output() readonly loadMore = new EventEmitter<void>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }

  disputeUrl(dispute: AdminPaymentDispute): string {
    return stripeDisputeUrl(dispute);
  }

  formatAmount(dispute: AdminPaymentDispute): string {
    return this.priceFormatter(dispute.amountCents / 100, dispute.currency);
  }
}
