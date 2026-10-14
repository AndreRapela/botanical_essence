export type Currency = 'USD' | 'EUR';
export type RefundOutcome = 'none' | 'pending' | 'unknown' | 'definitive_failure' | 'succeeded' | 'manual_refunded' | 'manual_not_refunded';

export interface RefundResolutionSnapshot {
  readonly orderNumber: string;
  readonly attempt: number;
  readonly refundId: string | null;
  readonly kind: 'full' | 'partial';
  readonly amountCents: number | null;
  readonly refundedTotalCents: number;
  readonly outcome: RefundOutcome;
  readonly totalCents: number;
  readonly currency: Currency;
}
