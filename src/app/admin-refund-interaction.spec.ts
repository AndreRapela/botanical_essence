import { afterEach, describe, expect, it, vi } from 'vitest';

import { AdminOrder } from './storefront.models';
import { confirmAdminOrderCancellation, handleAdminRefundInteraction } from './admin-refund-interaction';

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

  it('does not offer cancellation for shipped, delivered or cancelled orders', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    for (const status of ['shipped', 'delivered', 'cancelled'] as const) {
      expect(confirmAdminOrderCancellation(makeOrder({ status }), copy)).toBe(false);
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

    handleAdminRefundInteraction(makeOrder(), 'resolve_refund', 'refunded', frenchCopy, submit, vi.fn(), false);
    expect(prompt).toHaveBeenCalledWith(expect.stringContaining('Indiquez pourquoi'));
    expect(prompt).toHaveBeenCalledWith(expect.not.stringContaining('Registre'));
  });

  it('validates and confirms the audit reason for manual refund resolution', () => {
    const submit = vi.fn();
    const showError = vi.fn();
    const prompt = vi.spyOn(window, 'prompt');
    const confirm = vi.spyOn(window, 'confirm');

    prompt.mockReturnValueOnce(null);
    handleAdminRefundInteraction(makeOrder(), 'resolve_refund', 'refunded', copy, submit, showError, false);
    expect(submit).not.toHaveBeenCalled();

    prompt.mockReturnValueOnce('too short');
    handleAdminRefundInteraction(makeOrder(), 'resolve_refund', 'not_refunded', copy, submit, showError, false);
    expect(showError).toHaveBeenCalledWith('Enter a reason between 10 and 500 characters.', expect.any(String));

    showError.mockClear();
    prompt.mockReturnValueOnce('  Checked the provider records  ');
    confirm.mockReturnValueOnce(true);
    handleAdminRefundInteraction(makeOrder(), 'resolve_refund', 'refunded', copy, submit, showError, false);
    expect(submit).toHaveBeenCalledWith('resolve_refund', 'refunded', 'Checked the provider records');
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
