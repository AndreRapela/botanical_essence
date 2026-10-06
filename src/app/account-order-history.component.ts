import { CommonModule } from '@angular/common';
import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { canRequestWithdrawal, orderRefundRemaining, withdrawalStatusText } from './order.view';
import { Currency, Language, OrderSummary } from './storefront.models';

export type CustomerOrderAction = { order: OrderSummary; type: 'withdrawal' | 'problem' };

@Component({
  selector: 'app-account-order-history',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './account-order-history.component.html',
  styleUrl: './account-order-history.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class AccountOrderHistoryComponent {
  @Input() orders: OrderSummary[] = [];
  @Input() language: Language = 'en';
  @Input() loading = false;
  @Input() hasMore = false;
  @Input() error = '';
  @Input() withdrawalSuccess = '';
  @Input() priceFormatter: (value: number, sourceCurrency?: Currency) => string = (value, currency = 'EUR') =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(value);

  @Output() readonly loadMore = new EventEmitter<void>();
  @Output() readonly requestAction = new EventEmitter<CustomerOrderAction>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }

  orderRefundRemaining(order: OrderSummary): number {
    return orderRefundRemaining(order);
  }

  canRequestWithdrawal(order: OrderSummary): boolean {
    return canRequestWithdrawal(order);
  }

  withdrawalStatusLabel(status: string | null | undefined): string {
    return withdrawalStatusText(status, this.language);
  }

  askForAction(order: OrderSummary, type: CustomerOrderAction['type']): void {
    this.requestAction.emit({ order, type });
  }
}
