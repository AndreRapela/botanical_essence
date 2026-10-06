import { withAdminContactMessageStatus } from './admin-support.actions';
import type { AdminContactMessage } from './storefront.models';

describe('admin contact message state updates', () => {
  it('returns new list and item references without mutating the source message', () => {
    const first = { id: 1, status: 'new' } as AdminContactMessage;
    const second = { id: 2, status: 'in_progress' } as AdminContactMessage;
    const messages = [first, second];

    const updated = withAdminContactMessageStatus(messages, first.id, 'closed');

    expect(updated).not.toBe(messages);
    expect(updated[0]).not.toBe(first);
    expect(updated[0].status).toBe('closed');
    expect(first.status).toBe('new');
    expect(updated[1]).toBe(second);
  });

  it('preserves the original list when the message is not present', () => {
    const messages = [{ id: 1, status: 'new' } as AdminContactMessage];

    expect(withAdminContactMessageStatus(messages, 99, 'closed')).toBe(messages);
  });
});
