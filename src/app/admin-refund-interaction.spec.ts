import { afterEach, describe, expect, it, vi } from 'vitest';

import { AdminOrder } from './storefront.models';
import { adminRefundResultMessage, adminRefundSelectionError, confirmAdminOrderCancellation, handleAdminRefundInteraction, manualRefundTarget } from './admin-refund-interaction';
import { adminRefundAction, partialRefundAdminOrder, runAdminOrderAction, type AdminOrdersActionsContext } from './admin-orders.actions';

function makeOrder(values: Partial<AdminOrder> = {}): AdminOrder {
  return {
    number: 'BE-TEST-ORDER',
    status: 'confirmed',
    paymentStatus: 'unpaid',
    paymentProvider: 'stripe',
    testPayment: false,
    refundOutcome: 'unknown',
    refundAttempt: 1,
    refundAttemptIsPartial: false,
    refundId: null,
    refundAttemptAmountCents: 1000,
    total: 10,
    refundedTotalCents: 0,
    currency: 'EUR',
    ...values
  } as AdminOrder;
}

const copy = (english: string): string => english;
const frenchCopy = (_english: string, french: string): string => french;

describe('admin refund interaction safeguards', () => {
  afterEach(() => vi.restoreAllMocks());

  it('localizes a history check without claiming that pending refunds settled or that another refund was sent', () => {
    const result = { code: 'refund_history_checked', message: 'Mensagem bruta da API' };
    expect(adminRefundResultMessage(result, copy)).toContain('without sending a new refund');
    expect(adminRefundResultMessage(result, frenchCopy)).toContain('sans envoyer de nouveau remboursement');
  });

  it('refuses cancellation and retry when a historical refund is unresolved even if the current projection settled', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const submit = vi.fn();
    for (const status of ['confirmed', 'cancelled']) {
      const order = makeOrder({ status, paymentStatus: 'partially_refunded', refundOutcome: 'definitive_failure',
        historicalRefunds: [{ attempt: 1, kind: 'partial', id: 're_older', amountCents: 2500, status: 'pending' }] });
      expect(confirmAdminOrderCancellation(order, copy)).toBe(false);
      handleAdminRefundInteraction(order, 'retry_refund', undefined, copy, submit, vi.fn(), false);
    }
    expect(confirm).not.toHaveBeenCalled();
    expect(submit).not.toHaveBeenCalled();
  });

  it('dispatches only the read-only history action without prompting and respects provider and busy safeguards', () => {
    const confirm = vi.spyOn(window, 'confirm');
    const prompt = vi.spyOn(window, 'prompt');
    const submit = vi.fn();
    handleAdminRefundInteraction(makeOrder(), 'check_refund_history', undefined, copy, submit, vi.fn(), false);
    expect(submit).toHaveBeenCalledExactlyOnceWith('check_refund_history');
    handleAdminRefundInteraction(makeOrder(), 'check_refund_history', undefined, copy, submit, vi.fn(), true);
    handleAdminRefundInteraction(makeOrder({ paymentProvider: 'local_test' }), 'check_refund_history', undefined, copy, submit, vi.fn(), false);
    expect(submit).toHaveBeenCalledTimes(1);
    expect(confirm).not.toHaveBeenCalled();
    expect(prompt).not.toHaveBeenCalled();
  });

  it('refuses the partial-refund action before prompting when an older attempt is unresolved', () => {
    const prompt = vi.spyOn(window, 'prompt');
    const context = { adminLoading: false } as AdminOrdersActionsContext;
    partialRefundAdminOrder.call(context, makeOrder({ paymentStatus: 'partially_refunded',
      historicalRefunds: [{ attempt: 1, kind: 'partial', id: null, amountCents: 2500, status: 'unknown' }] }));
    expect(prompt).not.toHaveBeenCalled();
  });

  it('localizes a settled refund balance in EN and FR without implying automatic fulfillment', () => {
    const result = { code: 'refund_balance_confirmed', message: 'Mensagem bruta da API' };
    expect(adminRefundResultMessage(result, copy)).toContain('refund balance is settled. Check fulfillment');
    expect(adminRefundResultMessage(result, frenchCopy)).toContain('solde du remboursement est réglé. Vérifiez la livraison');
  });

  it('localizes a refund shortfall in EN and FR without authorizing another refund or stock change', () => {
    const result = { code: 'refund_balance_remaining', message: 'Mensagem bruta da API' };
    expect(adminRefundResultMessage(result, copy)).toContain('No additional refund was sent');
    expect(adminRefundResultMessage(result, frenchCopy)).toContain('Aucun remboursement supplémentaire n’a été envoyé');
  });

  it('preserves legacy responses and unknown operation results without falsely confirming a refund', () => {
    expect(adminRefundResultMessage({ message: 'Provider still processing' }, copy)).toBe('Provider still processing');
    expect(adminRefundResultMessage({ message: 'Provider still processing', code: 'unrecognized' }, frenchCopy)).toBe('Provider still processing');
  });

  it('does not offer cancellation for shipped delivered or cancelled orders without a resolved Stripe balance', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    for (const status of ['shipped', 'delivered', 'cancelled'] as const) {
      expect(confirmAdminOrderCancellation(makeOrder({ status }), copy)).toBe(false);
    }
    expect(confirm).not.toHaveBeenCalled();
  });

  it('confirms an already cancelled resolved Stripe balance in EN and FR without a second restock', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const order = makeOrder({ status: 'cancelled', paymentStatus: 'partially_refunded', refundOutcome: 'succeeded' });
    expect(confirmAdminOrderCancellation(order, copy)).toBe(true);
    expect(confirm).toHaveBeenLastCalledWith(expect.stringContaining('stock will not be restored again'));
    expect(confirmAdminOrderCancellation(order, frenchCopy)).toBe(true);
    expect(confirm).toHaveBeenLastCalledWith(expect.stringContaining('uniquement le solde restant'));
    expect(confirmAdminOrderCancellation({ ...order, paymentStatus: 'paid', refundOutcome: 'none', refundAttempt: 0, testPayment: true }, copy)).toBe(true);
    expect(confirm).toHaveBeenLastCalledWith(expect.stringContaining('Stripe test mode'));
  });

  it('never authorizes a cancelled unresolved external or non-Stripe refund balance', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const order = makeOrder({ status: 'cancelled', paymentStatus: 'partially_refunded', refundOutcome: 'succeeded' });
    for (const values of [{ paymentStatus: 'refund_pending' }, { refundOutcome: 'unknown' as const },
      { externalRefundUnmatched: true }, { paymentProvider: 'local_test' }, { paymentStatus: 'refunded' }]) {
      expect(confirmAdminOrderCancellation({ ...order, ...values }, copy)).toBe(false);
    }
    expect(confirm).not.toHaveBeenCalled();
  });

  it('asks for explicit confirmation before cancelling paid and unpaid orders', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
    expect(confirmAdminOrderCancellation(makeOrder(), copy)).toBe(false);
    expect(confirmAdminOrderCancellation(makeOrder({ paymentStatus: 'paid', testPayment: true, paymentProvider: 'local_test' }), copy)).toBe(true);
    expect(confirm).toHaveBeenNthCalledWith(1, 'Cancel this order and release its reserved stock?');
    expect(confirm).toHaveBeenNthCalledWith(2, expect.stringContaining('local test purchase'));
  });

  it('distinguishes a local simulation cancellation from a Stripe test-mode refund', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    expect(confirmAdminOrderCancellation(makeOrder({ paymentStatus: 'paid', testPayment: true, paymentProvider: 'local_test' }), copy)).toBe(true);
    expect(confirmAdminOrderCancellation(makeOrder({ paymentStatus: 'paid', testPayment: true, paymentProvider: 'stripe' }), copy)).toBe(true);
    expect(confirm).toHaveBeenNthCalledWith(1, expect.stringContaining('local test purchase'));
    expect(confirm).toHaveBeenNthCalledWith(2, expect.stringContaining('Stripe test mode will issue a test refund'));
  });

  it('only retries a definitively failed Stripe refund and confirms before dispatch', () => {
    const submit = vi.fn();
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const showError = vi.fn();
    handleAdminRefundInteraction(makeOrder({ paymentProvider: 'manual' }), 'check_refund', undefined, copy, submit, showError, false);
    handleAdminRefundInteraction(makeOrder({ refundOutcome: 'unknown' }), 'retry_refund', undefined, copy, submit, showError, false);
    expect(submit).not.toHaveBeenCalled();

    handleAdminRefundInteraction(makeOrder({ paymentStatus: 'paid', refundOutcome: 'definitive_failure' }), 'retry_refund', undefined, copy, submit, showError, false);
    expect(submit).toHaveBeenCalledWith('retry_refund');
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(showError).not.toHaveBeenCalled();
  });

  it('shows localized French prompts instead of leaking Portuguese copy', () => {
    const submit = vi.fn();
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const prompt = vi.spyOn(window, 'prompt').mockReturnValue('Vérification des registres Stripe');
    handleAdminRefundInteraction(makeOrder({ paymentStatus: 'paid', refundOutcome: 'definitive_failure' }), 'retry_refund', undefined, frenchCopy, submit, vi.fn(), false);
    expect(confirm).toHaveBeenNthCalledWith(1, expect.stringContaining('Stripe a définitivement refusé'));

    handleAdminRefundInteraction(makeOrder({ paymentStatus: 'refund_pending' }), 'resolve_refund', 'refunded', frenchCopy, submit, vi.fn(), false);
    expect(prompt).toHaveBeenCalledWith(expect.stringContaining('Indiquez pourquoi'));
    expect(prompt).toHaveBeenCalledWith(expect.not.stringContaining('Registre'));
  });

  it('validates and confirms the audit reason for manual refund resolution', () => {
    const submit = vi.fn();
    const showError = vi.fn();
    const prompt = vi.spyOn(window, 'prompt');
    const confirm = vi.spyOn(window, 'confirm');

    prompt.mockReturnValueOnce(null);
    handleAdminRefundInteraction(makeOrder({ paymentStatus: 'refund_pending' }), 'resolve_refund', 'refunded', copy, submit, showError, false);
    expect(submit).not.toHaveBeenCalled();

    prompt.mockReturnValueOnce('too short');
    handleAdminRefundInteraction(makeOrder({ paymentStatus: 'refund_pending' }), 'resolve_refund', 'not_refunded', copy, submit, showError, false);
    expect(showError).toHaveBeenCalledWith('Enter a reason between 10 and 500 characters.', expect.any(String));

    showError.mockClear();
    prompt.mockReturnValueOnce('  Checked the provider records  ');
    confirm.mockReturnValueOnce(true);
    handleAdminRefundInteraction(makeOrder({ paymentStatus: 'refund_pending' }), 'resolve_refund', 'refunded', copy, submit, showError, false);
    expect(submit).toHaveBeenCalledWith('resolve_refund', 'refunded', 'Checked the provider records', manualRefundTarget(makeOrder({ paymentStatus: 'refund_pending' })));
    expect(showError).not.toHaveBeenCalled();
  });

  it('ignores busy operations and dispatches a manual status check directly', () => {
    const submit = vi.fn();
    const showError = vi.fn();
    handleAdminRefundInteraction(makeOrder(), 'check_refund', undefined, copy, submit, showError, true);
    expect(submit).not.toHaveBeenCalled();
    handleAdminRefundInteraction(makeOrder(), 'check_refund', undefined, copy, submit, showError, false);
    expect(submit).toHaveBeenCalledWith('check_refund');
  });

  it('confirms only the remaining balance when cancelling a partly refunded order in EN and FR', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const order = makeOrder({ paymentStatus: 'partially_refunded', refundOutcome: 'succeeded', refundedTotalCents: 250 });
    expect(confirmAdminOrderCancellation(order, copy)).toBe(true);
    expect(confirm).toHaveBeenLastCalledWith(expect.stringContaining('only the remaining balance'));
    expect(confirm).toHaveBeenLastCalledWith(expect.stringContaining('previous refunds are preserved'));
    expect(confirmAdminOrderCancellation(order, frenchCopy)).toBe(true);
    expect(confirm).toHaveBeenLastCalledWith(expect.stringContaining('uniquement le solde restant'));
  });

  it('retries a full attempt after a partial credit only on a definite or audited non-refund', () => {
    const submit = vi.fn();
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const order = makeOrder({ paymentStatus: 'partially_refunded', refundOutcome: 'manual_not_refunded' });
    handleAdminRefundInteraction(order, 'retry_refund', undefined, copy, submit, vi.fn(), false);
    expect(submit).toHaveBeenCalledWith('retry_refund');
    expect(confirm).toHaveBeenLastCalledWith(expect.stringContaining('manually verified'));
    handleAdminRefundInteraction(order, 'retry_refund', undefined, frenchCopy, submit, vi.fn(), false);
    expect(confirm).toHaveBeenLastCalledWith(expect.stringContaining('vérifiée manuellement'));
    submit.mockClear(); confirm.mockClear();
    handleAdminRefundInteraction({ ...order, refundOutcome: 'unknown' }, 'retry_refund', undefined, copy, submit, vi.fn(), false);
    expect(submit).not.toHaveBeenCalled(); expect(confirm).not.toHaveBeenCalled();
  });
});

describe('immutable manual refund selection', () => {
  afterEach(() => vi.restoreAllMocks());

  it('captures the exact order, attempt, reference, amount, balance and outcome in an immutable snapshot', () => {
    const order = makeOrder({ paymentStatus: 'refund_pending', refundId: 're_selected1' });
    const target = manualRefundTarget(order);
    expect(target).toEqual({ orderNumber: order.number, attempt: 1, refundId: 're_selected1', kind: 'full',
      amountCents: 1000, refundedTotalCents: 0, outcome: 'unknown', totalCents: 1000, currency: 'EUR' });
    expect(Object.isFrozen(target)).toBe(true);
    order.refundAttempt = 2; order.refundId = 're_new2'; order.refundedTotalCents = 500;
    expect(target?.attempt).toBe(1);
    expect(target?.refundId).toBe('re_selected1');
    expect(target?.refundedTotalCents).toBe(0);
  });

  it('refuses missing, malformed, settled or ineligible selections and permits only explicit legacy full amounts', () => {
    const base = makeOrder({ paymentStatus: 'refund_pending' });
    for (const changes of [{ refundAttemptAmountCents: undefined }, { refundAttemptAmountCents: 0 },
      { refundAttemptAmountCents: 1.5 }, { refundId: 'unbound-reference' }, { refundAttempt: 0 },
      { refundedTotalCents: -1 }, { refundOutcome: 'succeeded' as const }, { status: 'delivered' },
      { paymentProvider: 'local_test' }, { refundAttemptIsPartial: true, refundAttemptAmountCents: null },
      { refundAttemptIsPartial: true, paymentStatus: 'paid' }]) {
      expect(manualRefundTarget({ ...base, ...changes })).toBeNull();
    }
    expect(manualRefundTarget({ ...base, refundAttemptAmountCents: null })?.amountCents).toBeNull();
  });

  it('submits the pre-confirmation full selection even if the displayed object changes during the prompt', () => {
    const order = makeOrder({ paymentStatus: 'refund_pending' });
    const original = manualRefundTarget(order);
    const submit = vi.fn();
    vi.spyOn(window, 'prompt').mockImplementation(() => {
      order.number = 'BE-OTHER-ORDER'; order.refundAttempt = 2;
      order.refundAttemptAmountCents = 500; order.refundedTotalCents = 500;
      return 'Verified the originally displayed attempt';
    });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    handleAdminRefundInteraction(order, 'resolve_refund', 'not_refunded', copy, submit, vi.fn(), false);
    expect(submit).toHaveBeenCalledExactlyOnceWith('resolve_refund', 'not_refunded',
      'Verified the originally displayed attempt', original);
  });

  it('captures the partial selection before its confirmation rather than rebuilding it from a changed object', () => {
    const order = makeOrder({ paymentStatus: 'refund_pending', refundAttemptIsPartial: true, refundAttemptAmountCents: 500 });
    const target = manualRefundTarget(order);
    const run = vi.fn();
    const context = { adminLoading: false, adminError: '', copy, runAdminOrderAction: run } as unknown as AdminOrdersActionsContext;
    vi.spyOn(window, 'prompt').mockImplementation(() => { order.refundAttempt = 3; return 'Verified the selected partial refund'; });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    adminRefundAction.call(context, order, 'resolve_refund', 'not_refunded');
    expect(run).toHaveBeenCalledExactlyOnceWith(order, 'resolve_refund', 'not_refunded', 'Verified the selected partial refund', target);
  });

  it('localizes stale selection errors and never renders the raw provider/API message', () => {
    const payload = { code: 'refund_target_stale', message: 'Mensagem crua não deve aparecer' };
    expect(adminRefundSelectionError(payload, copy)).toContain('Refresh the orders');
    expect(adminRefundSelectionError(payload, frenchCopy)).toContain('Actualisez les commandes');
    expect(adminRefundSelectionError(payload, copy)).not.toContain(payload.message);
    expect(adminRefundSelectionError({ code: 'refund_target_required' }, copy)).toContain('No automatic retry');
    expect(adminRefundSelectionError(null, copy)).toBeNull();
    expect(adminRefundSelectionError({ code: 'other' }, copy)).toBeNull();
    expect(adminRefundResultMessage({ code: 'refund_resolved_manually', message: payload.message }, copy)).toContain('No new Stripe refund');
    expect(adminRefundResultMessage({ code: 'refund_resolved_manually', message: payload.message }, frenchCopy)).toContain('Aucun nouveau remboursement');
  });

  it('refuses dispatch without an already captured selection instead of inventing one at submit time', () => {
    const cancel = vi.fn();
    const context = { adminLoading: false, adminError: '', copy, commerce: { cancelAdminOrder: cancel } } as unknown as AdminOrdersActionsContext;
    runAdminOrderAction.call(context, makeOrder({ paymentStatus: 'refund_pending' }), 'resolve_refund', 'refunded', 'A verified manual reason');
    expect(cancel).not.toHaveBeenCalled();
    expect(context.adminLoading).toBe(false);
    expect(context.adminError).toContain('select the refund attempt');
  });
});
