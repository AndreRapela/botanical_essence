import { AdminOrder, AdminPaymentDispute, Language, OrderSummary } from './storefront.models';

export function adminOrderItems(order: AdminOrder): string {
  return order.items.map((item) => `${item.name} × ${item.quantity}`).join(', ');
}

export function adminOrderRefundRemaining(order: AdminOrder): number {
  return Math.max(0, Math.round(order.total * 100) - order.refundedTotalCents) / 100;
}

export function orderRefundRemaining(order: OrderSummary): number {
  return Math.max(0, Math.round(order.total * 100) - (order.refundedTotalCents ?? 0)) / 100;
}

export function canRequestWithdrawal(order: OrderSummary): boolean {
  const refundable = ['paid', 'partially_refunded'].includes(order.paymentStatus)
    && orderRefundRemaining(order) > 0;
  const requestOpen = order.withdrawalStatus !== undefined
    && order.withdrawalStatus !== null
    && order.withdrawalStatus !== 'not_eligible';

  return refundable && order.status !== 'cancelled' && !requestOpen;
}

export function withdrawalStatusText(status: string | null | undefined, language: Language): string {
  const labels: Record<string, { en: string; fr: string }> = {
    submitted: { en: 'Request received', fr: 'Demande reçue' },
    reviewing: { en: 'Under review', fr: 'En cours d’examen' },
    instructions_sent: { en: 'Return instructions sent', fr: 'Instructions de retour envoyées' },
    return_received: { en: 'Return/proof recorded', fr: 'Retour/preuve enregistré' },
    refund_pending: { en: 'Refund being confirmed', fr: 'Remboursement en cours de confirmation' },
    refund_failed: { en: 'Refund needs review', fr: 'Remboursement à vérifier' },
    refunded: { en: 'Refund confirmed', fr: 'Remboursement confirmé' },
    not_eligible: { en: 'Reviewed by support', fr: 'Examiné par le support' }
  };
  const label = status ? labels[status] : undefined;
  return label ? label[language] : (status ?? '');
}

export function stripeDisputeUrl(dispute: AdminPaymentDispute): string {
  const mode = dispute.livemode ? '' : 'test/';
  return `https://dashboard.stripe.com/${mode}disputes/${encodeURIComponent(dispute.id)}`;
}
