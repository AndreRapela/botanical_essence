import { CommonModule } from '@angular/common';
import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { AdminOrder, AdminWithdrawalRequest, Currency, Language } from './storefront.models';
import { adminOrderItems, adminOrderRefundRemaining, withdrawalStatusText } from './order.view';
import type { AdminRefundAction } from './admin-refund-interaction';
import { manualRefundTarget } from './admin-refund-interaction';

export type AdminWithdrawalAction = 'review' | 'send_instructions' | 'mark_received' | 'not_eligible' | 'refund' | 'check_refund' | 'retry_refund';
export type { AdminRefundAction } from './admin-refund-interaction';

@Component({
  selector: 'app-admin-financial-operations',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './admin-financial-operations.component.html',
  styleUrl: './admin-financial-operations.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class AdminFinancialOperationsComponent {
  @Input() withdrawals: AdminWithdrawalRequest[] = [];
  @Input() withdrawalsTotal = 0;
  @Input() withdrawalsHasMore = false;
  @Input() orders: AdminOrder[] = [];
  @Input() ordersTotal = 0;
  @Input() ordersHasMore = false;
  @Input() language: Language = 'en';
  @Input() loading = false;
  @Input() priceFormatter: (value: number, sourceCurrency?: Currency) => string = (value, currency = 'EUR') =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(value);

  @Output() readonly loadMoreWithdrawals = new EventEmitter<void>();
  @Output() readonly withdrawalAction = new EventEmitter<{ request: AdminWithdrawalRequest; action: AdminWithdrawalAction }>();
  @Output() readonly loadMoreOrders = new EventEmitter<void>();
  @Output() readonly resolveCheckoutAttempt = new EventEmitter<AdminOrder>();
  @Output() readonly refundOrderAction = new EventEmitter<{
    order: AdminOrder;
    action: AdminRefundAction;
    resolution?: 'refunded' | 'not_refunded';
  }>();
  @Output() readonly partialRefund = new EventEmitter<AdminOrder>();
  @Output() readonly cancelOrder = new EventEmitter<AdminOrder>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }

  adminOrderItems(order: AdminOrder): string {
    return adminOrderItems(order);
  }

  adminOrderRefundRemaining(order: AdminOrder): number {
    return adminOrderRefundRemaining(order);
  }

  canResolveRefund(order: AdminOrder): boolean { return manualRefundTarget(order) !== null; }

  withdrawalStatusLabel(status: string | null | undefined): string {
    return withdrawalStatusText(status, this.language);
  }
}
