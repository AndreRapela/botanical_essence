import { CommonModule } from '@angular/common';
import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { AdminPaymentNotification, Language } from './storefront.models';

@Component({
  selector: 'app-admin-payment-notifications',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './admin-payment-notifications.component.html',
  styleUrl: './admin-payment-notifications.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class AdminPaymentNotificationsComponent {
  @Input() notifications: AdminPaymentNotification[] = [];
  @Input() language: Language = 'en';
  @Input() loading = false;
  @Input() hasMore = false;
  @Input() total = 0;
  @Output() readonly loadMore = new EventEmitter<void>();
  @Output() readonly retry = new EventEmitter<AdminPaymentNotification>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }

  typeLabel(type: string): string {
    switch (type) {
      case 'payment_confirmation': return this.copy('Payment confirmation', 'Confirmation de paiement');
      case 'refund_confirmation': return this.copy('Refund confirmation', 'Confirmation de remboursement');
      case 'full_refund_confirmation': return this.copy('Full refund confirmation', 'Confirmation de remboursement intégral');
      case 'refund_reversal_notice': return this.copy('Refund status changed', 'Statut du remboursement modifié');
      default: return this.copy('Order notification', 'Notification de commande');
    }
  }

  statusLabel(status: AdminPaymentNotification['status']): string {
    switch (status) {
      case 'failed': return this.copy('Needs attention', 'Action requise');
      case 'processing': return this.copy('Retry in progress', 'Nouvel essai en cours');
      default: return this.copy('Waiting to retry', 'Nouvel essai en attente');
    }
  }
}
