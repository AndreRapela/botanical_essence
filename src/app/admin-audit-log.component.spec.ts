import { AdminAuditLogComponent } from './admin-audit-log.component';

describe('admin audit log', () => {
  it('humanizes action keys and safely formats snapshots', () => {
    const component = new AdminAuditLogComponent();
    expect(component.actionLabel('product.updated')).toBe('product updated');
    expect(component.snapshot('{"status":"draft"}')).toContain('"status": "draft"');
    expect(component.snapshot('legacy value')).toBe('legacy value');
    component.language = 'fr';
    expect(component.snapshot(null)).toBe('Aucun détail enregistré');
  });

  it('emits a request to load older activity', () => {
    const component = new AdminAuditLogComponent();
    const received: unknown[] = [];
    component.loadMore.subscribe(() => received.push(true));
    component.loadMore.emit();
    expect(received).toEqual([true]);
  });
});
