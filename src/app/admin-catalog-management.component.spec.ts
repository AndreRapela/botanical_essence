import { ComponentFixture, TestBed } from '@angular/core/testing';

import { AdminCatalogManagementComponent } from './admin-catalog-management.component';
import { AdminReview, AdminSeller, AdminUser } from './storefront.models';

const demoUser: AdminUser = {
  id: 1, name: 'Demo Admin', email: 'admin@example.test', role: 'admin', status: 'active',
  isDemo: true, emailVerified: true, emailVerifiedAt: '2026-10-01T00:00:00Z', createdAt: '2026-10-01T00:00:00Z'
};

const seller: AdminSeller = {
  id: 12, displayName: 'Green House', bio: null, city: 'Brussels', region: 'Brussels-Capital', country: 'Belgium',
  verified: false, ownerName: 'Sam Seller', email: 'seller@example.test', accountStatus: 'active', productCount: 3
};

const review: AdminReview = {
  id: 8, productId: 2, productName: 'Monstera', authorName: 'A. Customer', authorEmail: 'buyer@example.test',
  rating: 2, comment: 'A review awaiting moderation.', status: 'visible', createdAt: '2026-10-02T00:00:00Z', updatedAt: '2026-10-02T00:00:00Z'
};

describe('admin catalog management', () => {
  let fixture: ComponentFixture<AdminCatalogManagementComponent>;
  let component: AdminCatalogManagementComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [AdminCatalogManagementComponent] }).compileComponents();
    fixture = TestBed.createComponent(AdminCatalogManagementComponent);
    component = fixture.componentInstance;
  });

  it('keeps protected demo accounts read-only in the editor', () => {
    component.editingUser = demoUser;
    component.userForm = { id: demoUser.id, name: demoUser.name, email: demoUser.email, role: demoUser.role, status: demoUser.status, emailVerified: true };
    fixture.detectChanges();

    const save = fixture.nativeElement.querySelector('#admin-user-editor button[type="submit"]') as HTMLButtonElement;
    expect(save.disabled).toBe(true);
    expect(fixture.nativeElement.textContent).toContain('Demo accounts are protected');
  });

  it('emits a deliberate seller verification value instead of mutating server data locally', () => {
    const changes: Array<{ seller: AdminSeller; verified: boolean }> = [];
    component.sellers = [seller];
    component.verifySeller.subscribe((change) => changes.push(change));
    fixture.detectChanges();

    const button = fixture.nativeElement.querySelector('.admin-row .row-actions button:last-child') as HTMLButtonElement;
    expect(button.textContent).toContain('Approve');
    button.click();
    expect(changes).toEqual([{ seller, verified: true }]);
    expect(seller.verified).toBe(false);
  });

  it('emits moderation as an explicit target status and localizes category labels', () => {
    const changes: Array<{ review: AdminReview; status: 'visible' | 'hidden' }> = [];
    component.reviews = [review];
    component.language = 'fr';
    component.moderateReview.subscribe((change) => changes.push(change));
    fixture.detectChanges();

    const button = fixture.nativeElement.querySelector('.admin-review-state button') as HTMLButtonElement;
    expect(button.textContent).toContain('Masquer');
    button.click();
    expect(changes).toEqual([{ review, status: 'hidden' }]);
    expect(component.categoryLabel('home')).toBe('Maison & parfums');
  });
});
