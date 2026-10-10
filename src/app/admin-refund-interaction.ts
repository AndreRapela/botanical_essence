import { AdminOrder } from './storefront.models';

export type AdminRefundAction = 'check_refund' | 'retry_refund' | 'resolve_refund';
export type AdminOrderAction = 'cancel' | AdminRefundAction;
export type RefundResolution = 'refunded' | 'not_refunded';
type Localize = (english: string, french: string) => string;
type Submit = (action: AdminOrderAction, resolution?: RefundResolution, reason?: string) => void;

export function adminRefundResultMessage(result: { message: string; code?: string }, copy: Localize): string {
  if (result.code === 'refund_balance_confirmed') {
    return copy('Stripe confirmed the authorized amount and the refund balance is settled. Check fulfillment before any stock operation.',
      'Stripe a confirmé le montant autorisé et le solde du remboursement est réglé. Vérifiez la livraison avant toute opération de stock.');
  }
  if (result.code === 'refund_balance_remaining') {
    return copy('Stripe confirmed the authorized amount, but a balance remains to review. No additional refund was sent and stock was not restored automatically.',
      'Stripe a confirmé le montant autorisé, mais un solde reste à vérifier. Aucun remboursement supplémentaire n’a été envoyé et le stock n’a pas été réintégré automatiquement.');
  }
  return result.message;
}

export function confirmAdminOrderCancellation(order: AdminOrder, copy: Localize): boolean {
  const cancelledBalance = order.status === 'cancelled' && order.paymentProvider === 'stripe'
    && ['paid', 'partially_refunded'].includes(order.paymentStatus)
    && ['none', 'succeeded', 'definitive_failure', 'manual_refunded', 'manual_not_refunded'].includes(order.refundOutcome)
    && !order.externalRefundUnmatched;
  if (['shipped', 'delivered'].includes(order.status) || (order.status === 'cancelled' && !cancelledBalance)) return false;
  const localSimulation = order.paymentProvider === 'local_test' && order.testPayment;
  const message = cancelledBalance
    ? order.testPayment
      ? copy('This order is already cancelled. Stripe test mode will refund only its remaining balance; no real money will move and stock will not be restored again. Continue?', 'Cette commande est déjà annulée. Le mode test de Stripe remboursera uniquement le solde restant ; aucun argent réel ne sera transféré et le stock ne sera pas réintégré une seconde fois. Continuer ?')
      : copy('This order is already cancelled. Refund only the remaining balance? Previous refunds are preserved and stock will not be restored again. If Stripe is unavailable, the attempt remains protected for reconciliation.', 'Cette commande est déjà annulée. Rembourser uniquement le solde restant ? Les remboursements précédents sont conservés et le stock ne sera pas réintégré une seconde fois. Si Stripe est indisponible, la tentative reste protégée pour réconciliation.')
    : localSimulation
    ? copy('This is a local test purchase; cancelling it will not move any money. Continue?', 'Cet achat est une simulation locale ; son annulation ne déplacera aucun argent. Continuer ?')
    : ['paid', 'partially_refunded'].includes(order.paymentStatus)
      ? order.testPayment
        ? copy('Stripe test mode will issue a test refund; no real money will move. Continue?', 'Le mode test de Stripe émettra un remboursement de test ; aucun argent réel ne sera transféré. Continuer ?')
        : copy('Stripe will be asked to refund only the remaining balance of this purchase. Any previous refunds are preserved. The order is cancelled only after a successful refund; if Stripe is unavailable, it will remain protected for reconciliation.', 'Stripe sera sollicité pour rembourser uniquement le solde restant de cet achat. Les remboursements précédents sont conservés. La commande ne sera annulée qu’après confirmation du remboursement ; si Stripe est indisponible, elle restera protégée pour réconciliation.')
      : copy('Cancel this order and release its reserved stock?', 'Annuler cette commande et libérer le stock réservé ?');
  return window.confirm(message);
}

export function handleAdminRefundInteraction(
  order: AdminOrder,
  action: AdminRefundAction,
  resolution: RefundResolution | undefined,
  copy: Localize,
  submit: Submit,
  showError: (english: string, french: string) => void,
  busy: boolean
): void {
  if (busy || order.paymentProvider !== 'stripe') return;
  if (action === 'check_refund') {
    submit(action);
    return;
  }
  if (action === 'retry_refund') {
    if (!['paid', 'partially_refunded'].includes(order.paymentStatus) || !['definitive_failure', 'manual_not_refunded'].includes(order.refundOutcome)) return;
    const message = order.refundOutcome === 'manual_not_refunded'
      ? copy('The previous attempt was manually verified as not refunded. Start a new attempt against the remaining balance only?', 'La tentative précédente a été vérifiée manuellement comme non remboursée. Lancer une nouvelle tentative uniquement pour le solde restant ?')
      : copy('Stripe definitively rejected the previous attempt. Start a new refund attempt? This will never be used while the result is unknown.', 'Stripe a définitivement refusé la tentative précédente. Lancer une nouvelle tentative de remboursement ? Cette option ne sera jamais utilisée si le résultat est inconnu.');
    const confirmed = window.confirm(message);
    if (confirmed) submit(action);
    return;
  }
  if (!resolution) return;

  const reason = window.prompt(copy(
    `Record why you are resolving this as ${resolution === 'refunded' ? 'refunded' : 'not refunded'} (at least 10 characters):`,
    `Indiquez pourquoi vous confirmez ${resolution === 'refunded' ? 'le remboursement' : 'l’absence de remboursement'} (10 caractères minimum) :`
  ));
  if (reason === null) return;
  if (reason.trim().length < 10 || reason.trim().length > 500) {
    showError('Enter a reason between 10 and 500 characters.', 'Saisissez un motif de 10 à 500 caractères.');
    return;
  }
  const confirmation = resolution === 'refunded'
    ? copy('Confirm only if you independently verified that the customer was refunded. The order will be cancelled and its stock released if needed.', 'Confirmez uniquement après avoir vérifié que le client a été remboursé. La commande sera annulée et son stock libéré si nécessaire.')
    : copy('Confirm only after checking Stripe and verifying no refund was issued. Otherwise a later retry could send a duplicate refund. The payment will remain due.', 'Confirmez uniquement après avoir vérifié dans Stripe qu’aucun remboursement n’a été émis. Sinon, une nouvelle tentative pourrait créer un remboursement en double. Le paiement restera dû.');
  if (window.confirm(confirmation)) submit(action, resolution, reason.trim());
}
