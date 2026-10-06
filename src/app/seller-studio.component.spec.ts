import { ComponentFixture, TestBed } from '@angular/core/testing';

import { ManagedProduct, MarketplaceStore } from './storefront.models';
import { SellerStudioComponent } from './seller-studio.component';

const approvedStore: MarketplaceStore = {
  id: 7,
  displayName: 'Greenhouse',
  slug: 'greenhouse',
  bio: 'Plants from our collection.',
  city: 'Brussels',
  region: 'Brussels-Capital',
  country: 'Belgium',
  rating: 0,
  verified: true
};

const product: ManagedProduct = {
  id: 21,
  name: 'Monstera',
  description: 'A healthy indoor plant.',
  origin: 'Brazil',
  dimensions: '65 cm',
  materials: null,
  careInstructions: 'Bright indirect light.',
  category: 'plants',
  price: 12,
  shippingWeightGrams: 1000,
  stock: 3,
  sprite: 0,
  badge: null,
  status: 'draft',
  updatedAt: '2026-01-01T00:00:00Z'
};

describe('seller studio', () => {
  let fixture: ComponentFixture<SellerStudioComponent>;
  let component: SellerStudioComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [SellerStudioComponent] }).compileComponents();
    fixture = TestBed.createComponent(SellerStudioComponent);
    component = fixture.componentInstance;
    component.store = { ...approvedStore, verified: false };
    component.products = [product];
  });

  it('keeps publication in draft while a seller is awaiting approval', () => {
    component.productForm.status = 'active';
    fixture.detectChanges();

    const activeOption = fixture.nativeElement.querySelector('select[name="productStatus"] option[value="active"]') as HTMLOptionElement;
    expect(fixture.nativeElement.textContent).toContain('Approval pending');
    expect(activeOption.disabled).toBe(true);

    const savedStatuses: string[] = [];
    component.saveProduct.subscribe(() => savedStatuses.push(component.productForm.status));
    const productForm = fixture.nativeElement.querySelector('#seller-product-form') as HTMLFormElement;
    productForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    expect(savedStatuses).toEqual(['draft']);
  });

  it('allows an approved seller to choose active publication', () => {
    component.store = approvedStore;
    fixture.detectChanges();

    const activeOption = fixture.nativeElement.querySelector('select[name="productStatus"] option[value="active"]') as HTMLOptionElement;
    expect(fixture.nativeElement.textContent).toContain('Store approved');
    expect(activeOption.disabled).toBe(false);
  });

  it('emits typed store, product, edit, archive, and reset actions from the seller forms', () => {
    component.productForm.id = product.id;
    fixture.detectChanges();

    const savedStore: boolean[] = [];
    const savedProduct: boolean[] = [];
    const edited: ManagedProduct[] = [];
    const archived: ManagedProduct[] = [];
    const reset: boolean[] = [];
    component.saveStore.subscribe(() => savedStore.push(true));
    component.saveProduct.subscribe(() => savedProduct.push(true));
    component.editProduct.subscribe((value) => edited.push(value));
    component.archiveProduct.subscribe((value) => archived.push(value));
    component.resetProductForm.subscribe(() => reset.push(true));

    (fixture.nativeElement.querySelector('.store-profile-card') as HTMLFormElement)
      .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    (fixture.nativeElement.querySelector('#seller-product-form') as HTMLFormElement)
      .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    const buttons = Array.from(fixture.nativeElement.querySelectorAll('.row-actions button')) as HTMLButtonElement[];
    buttons[0].click();
    buttons[1].click();
    (fixture.nativeElement.querySelector('.management-actions .button-outline') as HTMLButtonElement).click();

    expect(savedStore).toEqual([true]);
    expect(savedProduct).toEqual([true]);
    expect(edited).toEqual([product]);
    expect(archived).toEqual([product]);
    expect(reset).toEqual([true]);
  });
});
