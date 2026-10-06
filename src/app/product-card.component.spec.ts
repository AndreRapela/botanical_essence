import { TestBed } from '@angular/core/testing';

import { ProductCardComponent } from './product-card.component';
import { Product } from './storefront.models';

const product: Product = {
  id: 10,
  slug: 'monstera',
  name: 'Monstera',
  subtitle: 'Vaso de 18 cm',
  origin: null,
  dimensions: null,
  materials: null,
  careInstructions: null,
  category: 'plants',
  price: 129.9,
  shippingWeightGrams: 1500,
  stock: 2,
  sprite: 1,
  badge: 'Exclusivo',
  seller: 'Botanical Essence',
  marketplace: true,
  averageRating: 4.6,
  reviewCount: 8,
  currency: 'USD'
};

describe('ProductCardComponent', () => {
  it('renders translated accessible labels and emits parent-owned actions', () => {
    TestBed.configureTestingModule({ imports: [ProductCardComponent] });
    const fixture = TestBed.createComponent(ProductCardComponent);
    fixture.componentRef.setInput('product', product);
    fixture.componentRef.setInput('displayPrice', '€12.34');
    fixture.componentRef.setInput('language', 'fr');
    fixture.detectChanges();

    const component = fixture.componentInstance;
    const open = vi.fn();
    const favoriteToggle = vi.fn();
    const add = vi.fn();
    component.open.subscribe(open);
    component.favoriteToggle.subscribe(favoriteToggle);
    component.add.subscribe(add);

    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelector('.product-image-link')?.getAttribute('aria-label')).toBe('Ouvrir la fiche de Monstera');
    expect(element.querySelector('.seller-badge')?.textContent?.trim()).toBe('marché');
    expect(element.querySelector('.product-badge')?.textContent?.trim()).toBe('Exclusif');
    expect(element.querySelector('.product-rating')?.getAttribute('aria-label')).toBe('Voir les avis et les détails');
    expect(element.querySelector('.product-bottom strong')?.textContent?.trim()).toBe('€12.34');
    expect(element.querySelector('.product-bottom button')?.getAttribute('aria-label')).toBe('Ajouter Monstera au panier');

    element.querySelector('.product-image-link')?.dispatchEvent(new MouseEvent('click'));
    element.querySelector('.favorite-button')?.dispatchEvent(new MouseEvent('click'));
    element.querySelector('.product-bottom button')?.dispatchEvent(new MouseEvent('click'));
    fixture.detectChanges();

    expect(open).toHaveBeenCalledWith(product);
    expect(favoriteToggle).toHaveBeenCalledWith(product.id);
    expect(add).toHaveBeenCalledWith(product);
    fixture.destroy();
  });

  it('can hide badges for saved-product cards while keeping actions labeled', () => {
    TestBed.configureTestingModule({ imports: [ProductCardComponent] });
    const fixture = TestBed.createComponent(ProductCardComponent);
    fixture.componentRef.setInput('product', product);
    fixture.componentRef.setInput('displayPrice', '$12.34');
    fixture.componentRef.setInput('showBadges', false);
    fixture.componentRef.setInput('favorite', true);
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelector('.product-badge, .seller-badge')).toBeNull();
    expect(element.querySelector('.favorite-button')?.classList.contains('active')).toBe(true);
    expect(element.querySelector('.favorite-button')?.getAttribute('aria-label')).toBe('Remove from favorites');
    fixture.destroy();
  });

  it('handles unrated products and missing translation keys without inventing a rating', () => {
    TestBed.configureTestingModule({ imports: [ProductCardComponent] });
    const fixture = TestBed.createComponent(ProductCardComponent);
    fixture.componentRef.setInput('product', { ...product, badge: null, marketplace: false, averageRating: -2, reviewCount: 0 });
    fixture.componentRef.setInput('displayPrice', '$12.34');
    fixture.detectChanges();

    const component = fixture.componentInstance;
    const element: HTMLElement = fixture.nativeElement;
    expect(component.translate('missing.translation')).toBe('missing.translation');
    expect(component.ratingStars()).toBe('☆☆☆☆☆');
    expect(component.ratingSummary()).toBe('New · no reviews');
    expect(element.querySelector('.product-badge, .seller-badge')).toBeNull();
    expect(element.querySelector('.product-rating')?.getAttribute('aria-label')).toBe('View ratings and product details');
    fixture.componentRef.setInput('language', 'fr');
    fixture.detectChanges();
    expect(component.ratingSummary()).toBe('Nouveau · aucun avis');
    fixture.destroy();
  });
});
