import { TestBed } from '@angular/core/testing';

import { CheckoutOrderSummaryComponent, CheckoutOrderSummaryItem } from './checkout-order-summary.component';

describe('CheckoutOrderSummaryComponent', () => {
  const items: CheckoutOrderSummaryItem[] = [
    { id: 7, sprite: 3, quantity: 2, seller: 'Botanical Essence', name: 'Monstera Essential', lineTotal: '€18.00' },
    { id: 9, sprite: 1, quantity: 1, seller: 'Jardim Demo', name: 'Ceramic planter', lineTotal: '€4.50' }
  ];

  function create(overrides: Partial<CheckoutOrderSummaryComponent> = {}) {
    TestBed.configureTestingModule({ imports: [CheckoutOrderSummaryComponent] });
    const fixture = TestBed.createComponent(CheckoutOrderSummaryComponent);
    fixture.componentRef.setInput('language', overrides.language ?? 'en');
    fixture.componentRef.setInput('items', overrides.items ?? items);
    fixture.componentRef.setInput('subtotalLabel', overrides.subtotalLabel ?? 'Subtotal');
    fixture.componentRef.setInput('subtotalAmount', overrides.subtotalAmount ?? '€22.50');
    fixture.componentRef.setInput('totalAmount', overrides.totalAmount ?? '€27.50');
    fixture.componentRef.setInput('shippingLoading', overrides.shippingLoading ?? false);
    fixture.componentRef.setInput('hasShippingQuote', overrides.hasShippingQuote ?? true);
    fixture.componentRef.setInput('shippingAmount', overrides.shippingAmount ?? '€5.00');
    fixture.detectChanges();
    return fixture;
  }

  it('renders each item and the authoritative summary values provided by checkout', () => {
    const fixture = create();
    const element: HTMLElement = fixture.nativeElement;
    const aside = element.querySelector<HTMLElement>('.order-summary');

    expect(aside?.getAttribute('aria-labelledby')).toBe('checkout-order-summary-title');
    expect(aside?.querySelectorAll('article')).toHaveLength(2);
    expect(aside?.textContent).toContain('2 × Botanical Essence');
    expect(aside?.textContent).toContain('Monstera Essential');
    expect(aside?.textContent).toContain('€22.50');
    expect(aside?.textContent).toContain('€5.00');
    expect(aside?.textContent).toContain('€27.50');
    expect(aside?.querySelector('.sprite-3')).not.toBeNull();
    fixture.destroy();
  });

  it('shows the calculating status ahead of any stale shipping amount', () => {
    const fixture = create({ shippingLoading: true });
    const aside: HTMLElement = fixture.nativeElement.querySelector('.order-summary');

    expect(aside.textContent).toContain('Calculating…');
    expect(aside.textContent).not.toContain('€5.00');
    fixture.destroy();
  });

  it('explains that delivery is still to be calculated when no quote is selected', () => {
    const fixture = create({ hasShippingQuote: false });
    const aside: HTMLElement = fixture.nativeElement.querySelector('.order-summary');

    expect(aside.textContent).toContain('Enter address');
    expect(aside.textContent).toContain('Delivery will be added after the address is validated.');
    fixture.destroy();
  });

  it('emits the cart edit action without modifying item or price data', () => {
    const fixture = create();
    const editCart = vi.fn();
    fixture.componentInstance.editCart.subscribe(editCart);
    (fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>('.order-summary-head button')?.click();

    expect(editCart).toHaveBeenCalledOnce();
    expect(fixture.componentInstance.items).toEqual(items);
    expect(fixture.componentInstance.totalAmount).toBe('€27.50');
    fixture.destroy();
  });

  it('localizes summary labels and assurance copy in French', () => {
    const fixture = create({ language: 'fr', subtotalLabel: 'Sous-total', hasShippingQuote: false });
    const aside: HTMLElement = fixture.nativeElement.querySelector('.order-summary');

    expect(aside.textContent).toContain('VOTRE SÉLECTION');
    expect(aside.textContent).toContain('Récapitulatif');
    expect(aside.textContent).toContain('Livraison estimée');
    expect(aside.textContent).toContain('Emballé avec soin');
    expect(aside.textContent).toContain('Sous-total');
    fixture.destroy();
  });
});
