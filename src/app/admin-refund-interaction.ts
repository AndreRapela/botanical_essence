import { AdminOrder } from './storefront.models';

export type AdminRefundAction = 'check_refund' | 'retry_refund' | 'resolve_refund';
export type AdminOrderAction = 'cancel' | AdminRefundAction;
export type RefundResolution = 'refunded' | 'not_refunded';
type Localize = (english: string, french: string) => string;
type Submit = (action: AdminOrderAction, resolution?: RefundResolution, reason?: string) => void;

export function confirmAdminOrderCancellation(order: AdminOrder, copy: Localize): boolean {
  if (['shipped', 'delivered', 'cancelled'].includes(order.status)) return false;
  const localSimulation = order.paymentProvider === 'local_test' && order.testPayment;
  const message = localSimulation
    ? copy('This is a local test purchase; cancelling it will not move any money. Continue?', 'Cet achat est une simulation locale ; son annulation ne déplacera aucun argent. Continuer ?')
    : order.paymentStatus === 'paid'
      ? order.testPayment
        ? copy('Stripe test mode will issue a test refund; no real money will move. Continue?', 'Le mode test de Stripe émettra un remboursement de test ; aucun argent réel ne sera transféré. Continuer ?')
        : copy('Stripe will be asked to refund this purchase. The order is cancelled only after a successful refund; if Stripe is unavailable, it will remain protected for reconciliation.', 'Stripe sera sollicité pour rembourser cet achat. La commande ne sera annulée qu’après confirmation du remboursement ; si Stripe est indisponible, elle restera protégée pour réconciliation.')
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
    if (order.paymentStatus !== 'paid' || !['definitive_failure', 'manual_not_refunded'].includes(order.refundOutcome)) return;
    const confirmed = window.confirm(copy(
      'Stripe definitively rejected the previous attempt. Start a new refund attempt? This will never be used while the result is unknown.',
      'Stripe a définitivement refusé la tentative précédente. Lancer une nouvelle tentative de remboursement ? Cette option ne sera jamais utilisée si le résultat est inconnu.'
    ));
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
