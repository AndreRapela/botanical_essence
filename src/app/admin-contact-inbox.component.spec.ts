import { AdminContactInboxComponent } from './admin-contact-inbox.component';
import { AdminContactMessage } from './storefront.models';

describe('admin contact inbox', () => {
  it('localizes subject and status labels', () => {
    const component = new AdminContactInboxComponent();
    expect(component.subjectLabel('order')).toBe('Order');
    expect(component.subjectLabel('care')).toBe('Plant care');
    expect(component.subjectLabel('seller')).toBe('Seller');
    expect(component.subjectLabel('other')).toBe('Other');
    expect(component.statusLabel('new')).toBe('New');
    expect(component.statusLabel('in_progress')).toBe('In progress');
    expect(component.statusLabel('closed')).toBe('Closed');

    component.language = 'fr';
    expect(component.copy('Order', 'Commande')).toBe('Commande');
    expect(component.subjectLabel('care')).toBe('Entretien des plantes');
    expect(component.statusLabel('in_progress')).toBe('En cours');
  });

  it('emits a requested status transition with the source message', () => {
    const component = new AdminContactInboxComponent();
    const message = { id: 42, status: 'new' } as AdminContactMessage;
    const received: unknown[] = [];
    component.changeStatus.subscribe((change) => received.push(change));

    component.setStatus(message, 'in_progress');

    expect(received).toEqual([{ message, status: 'in_progress' }]);
  });
});
