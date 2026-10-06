import type { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize } from 'rxjs';

import type { AdminProductForm, AdminStoreForm, AdminUserForm } from './admin-catalog-management.component';
import type { CommerceService } from './core/commerce.service';
import type { AuthService } from './core/auth.service';
import type { AdminProduct, AdminReview, AdminSeller, AdminUser } from './storefront.models';

export interface AdminCatalogActionsContext {
  auth: Pick<AuthService, 'sessionVersion'>;
  commerce: Pick<CommerceService, 'updateAdminUser' | 'updateAdminProduct' | 'moderateReview' | 'updateAdminStore' | 'setSellerVerification'>;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  document: Pick<Document, 'getElementById'>;
  adminLoading: boolean;
  adminError: string;
  adminSuccess: string;
  adminUsers: AdminUser[];
  adminProducts: AdminProduct[];
  adminReviews: AdminReview[];
  adminSellers: AdminSeller[];
  editingAdminUser: AdminUser | null;
  editingAdminProduct: AdminProduct | null;
  editingAdminStore: AdminSeller | null;
  adminUserForm: AdminUserForm;
  adminProductForm: AdminProductForm;
  adminStoreForm: AdminStoreForm;
  copy(english: string, french: string): string;
  apiError(error: HttpErrorResponse, fallback: string): string;
  handleUnauthorized(error: HttpErrorResponse, expectedSessionVersion?: number): void;
  loadAdminSellers(): void;
  refreshCatalog(): void;
}

export function editAdminUser(this: AdminCatalogActionsContext, user: AdminUser): void {
    this.editingAdminUser = user;
    this.adminUserForm = {
      id: user.id,
      name: user.name,
      email: user.email,
      role: user.role,
      status: user.status,
      emailVerified: user.emailVerified
    };
    this.adminError = '';
    this.adminSuccess = '';
    setTimeout(() => this.document.getElementById('admin-user-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
  }

export function saveAdminUser(this: AdminCatalogActionsContext): void {
    if (this.adminLoading || !this.editingAdminUser) return;
    this.adminLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.adminError = '';
    this.commerce.updateAdminUser(this.adminUserForm).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: ({ user }) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        const emailChanged = this.editingAdminUser?.email !== user.email;
        this.adminUsers = this.adminUsers.map((item) => item.id === user.id ? user : item);
        this.adminLoading = false;
        this.editingAdminUser = null;
        this.adminSuccess = emailChanged
          ? this.copy('Email changed. The account is pending until the new address is verified.', 'E-mail modifié. Le compte reste en attente jusqu’à la vérification de la nouvelle adresse.')
          : this.copy('Account updated.', 'Compte mis à jour.');
        this.loadAdminSellers();
        this.refreshCatalog();
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.adminError = this.apiError(error, this.copy('We could not update this account.', 'Impossible de modifier ce compte.'));
      }
    });
  }

export function editAdminProduct(this: AdminCatalogActionsContext, product: AdminProduct): void {
    this.editingAdminProduct = product;
    this.adminProductForm = {
      id: product.id,
      name: product.name,
      description: product.description,
      origin: product.origin ?? '',
      dimensions: product.dimensions ?? '',
      materials: product.materials ?? '',
      careInstructions: product.careInstructions ?? '',
      category: product.category,
      price: product.price,
      shippingWeightGrams: product.shippingWeightGrams,
      stock: product.stock,
      sprite: product.sprite,
      badge: product.badge ?? '',
      status: product.status,
      marketplace: product.marketplace,
      featured: product.featured
    };
    this.adminError = '';
    this.adminSuccess = '';
    setTimeout(() => this.document.getElementById('admin-product-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
  }

export function saveAdminProduct(this: AdminCatalogActionsContext): void {
    if (this.adminLoading || !this.editingAdminProduct) return;
    this.adminLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.adminError = '';
    this.commerce.updateAdminProduct(this.adminProductForm).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: ({ product }) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminProducts = this.adminProducts.map((item) => item.id === product.id ? product : item);
        this.adminLoading = false;
        this.editingAdminProduct = null;
        this.adminSuccess = this.copy('Product updated.', 'Produit mis à jour.');
        this.refreshCatalog();
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.adminError = this.apiError(error, this.copy('We could not update this product.', 'Impossible de modifier ce produit.'));
      }
    });
  }

export function moderateAdminReview(this: AdminCatalogActionsContext, review: AdminReview, status: 'visible' | 'hidden'): void {
    if (this.adminLoading) return;
    this.adminLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.adminError = '';
    this.adminSuccess = '';
    this.commerce.moderateReview(review.id, status).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: () => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminLoading = false;
        this.adminReviews = this.adminReviews.map((item) => item.id === review.id ? { ...item, status } : item);
        this.adminSuccess = status === 'visible'
          ? this.copy('Review restored.', 'Avis restauré.')
          : this.copy('Review hidden.', 'Avis masqué.');
        this.refreshCatalog();
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.adminError = this.apiError(error, this.copy('We could not moderate this review.', 'Impossible de modérer cet avis.'));
      }
    });
  }

export function editAdminStore(this: AdminCatalogActionsContext, seller: AdminSeller): void {
    this.editingAdminStore = seller;
    this.adminStoreForm = {
      id: seller.id,
      displayName: seller.displayName,
      bio: seller.bio ?? '',
      city: seller.city,
      region: seller.region,
      country: seller.country
    };
    setTimeout(() => this.document.getElementById('admin-store-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
  }

export function saveAdminStore(this: AdminCatalogActionsContext): void {
    if (this.adminLoading || !this.editingAdminStore) return;
    this.adminLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.adminError = '';
    this.commerce.updateAdminStore(this.adminStoreForm).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: () => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminLoading = false;
        this.editingAdminStore = null;
        this.adminSuccess = this.copy('Store updated.', 'Boutique mise à jour.');
        this.loadAdminSellers();
        this.refreshCatalog();
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.adminError = this.apiError(error, this.copy('We could not update this store.', 'Impossible de modifier cette boutique.'));
      }
    });
  }

export function setSellerVerification(this: AdminCatalogActionsContext, seller: AdminSeller, verified: boolean): void {
    if (this.adminLoading) return;
    this.adminLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.adminError = '';
    this.adminSuccess = '';
    this.commerce.setSellerVerification(seller.id, verified).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: () => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminLoading = false;
        this.adminSellers = this.adminSellers.map((item) => item.id === seller.id ? { ...item, verified } : item);
        this.adminSuccess = verified
          ? this.copy('Seller approved.', 'Vendeur approuvé.')
          : this.copy('Seller approval removed.', 'Validation du vendeur retirée.');
        this.refreshCatalog();
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.adminError = this.apiError(error, this.copy('We could not update this seller.', 'Impossible de modifier ce vendeur.'));
      }
    });
  }
