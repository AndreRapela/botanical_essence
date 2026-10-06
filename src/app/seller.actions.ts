import type { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, takeUntil } from 'rxjs';

import type { AuthService } from './core/auth.service';
import type { SellerProductForm, SellerStoreForm } from './seller-studio.component';
import type { CommerceService } from './core/commerce.service';
import type { AccountProfile, ManagedProduct, MarketplaceStore, AuthUser } from './storefront.models';

export interface SellerActionsContext {
  auth: Pick<AuthService, 'sessionChanged$' | 'sessionVersion'>;
  user: AuthUser | null;
  commerce: Pick<CommerceService, 'sellerDashboard' | 'updateSellerStore' | 'saveSellerProduct' | 'archiveSellerProduct'>;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  document: Pick<Document, 'getElementById'>;
  accountProfile: AccountProfile | null;
  sellerStore: MarketplaceStore | null;
  managedProducts: ManagedProduct[];
  sellerForm: SellerStoreForm;
  productForm: SellerProductForm;
  sellerLoading: boolean;
  sellerError: string;
  sellerSuccess: string;
  handleUnauthorized(error: HttpErrorResponse, expectedSessionVersion?: number): void;
  apiError(error: HttpErrorResponse, fallback: string): string;
  copy(english: string, french: string): string;
  refreshCatalog(): void;
  resetProductForm(): void;
}

export function loadSellerDashboard(this: SellerActionsContext): void {
    if (this.sellerLoading || !this.user || this.user.role === 'admin') return;
    this.sellerLoading = true;
    this.sellerError = '';
    this.commerce.sellerDashboard().pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: ({ store, products }) => {
        this.sellerLoading = false;
        this.sellerStore = store;
        this.managedProducts = products;
        this.sellerForm = {
          displayName: store?.displayName ?? '',
          bio: store?.bio ?? '',
          city: store?.city ?? this.accountProfile?.city ?? '',
          region: store?.region ?? this.accountProfile?.region ?? '',
          country: store?.country ?? this.accountProfile?.country ?? 'Belgium'
        };
      },
      error: (error: HttpErrorResponse) => {
        this.sellerLoading = false;
        this.handleUnauthorized(error);
        this.sellerError = this.apiError(error, this.copy('We could not load your shop.', 'Impossible de charger votre boutique.'));
      }
    });
  }

export function saveSellerStore(this: SellerActionsContext): void {
    if (this.sellerLoading) return;
    this.sellerLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.sellerError = '';
    this.sellerSuccess = '';
    this.commerce.updateSellerStore(this.sellerForm).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: ({ store }) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.sellerLoading = false;
        this.sellerStore = store;
        this.sellerSuccess = this.copy('Shop details saved.', 'Informations de la boutique enregistrées.');
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.sellerLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.sellerError = this.apiError(error, this.copy('We could not save the shop.', 'Impossible d’enregistrer la boutique.'));
      }
    });
  }

export function editManagedProduct(this: SellerActionsContext, product: ManagedProduct): void {
    this.productForm = {
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
      status: product.status
    };
    this.sellerError = '';
    this.sellerSuccess = '';
    setTimeout(() => this.document.getElementById('seller-product-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
  }

export function resetProductForm(this: SellerActionsContext): void {
    this.productForm = { id: null, name: '', description: '', origin: '', dimensions: '', materials: '', careInstructions: '', category: 'plants', price: 1, shippingWeightGrams: 1000, stock: 0, sprite: 0, badge: '', status: 'draft' };
  }

export function saveSellerProduct(this: SellerActionsContext): void {
    if (this.sellerLoading) return;
    this.sellerLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.sellerError = '';
    this.sellerSuccess = '';
    const wasNew = this.productForm.id === null;
    this.commerce.saveSellerProduct(this.productForm).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: ({ product }) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.sellerLoading = false;
        const index = this.managedProducts.findIndex((item) => item.id === product.id);
        this.managedProducts = index < 0
          ? [product, ...this.managedProducts]
          : this.managedProducts.map((item) => item.id === product.id ? product : item);
        this.sellerSuccess = wasNew
          ? this.copy('Product created.', 'Produit créé.')
          : this.copy('Product updated.', 'Produit mis à jour.');
        this.resetProductForm();
        this.refreshCatalog();
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.sellerLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.sellerError = this.apiError(error, this.copy('We could not save the product.', 'Impossible d’enregistrer le produit.'));
      }
    });
  }

export function archiveSellerProduct(this: SellerActionsContext, product: ManagedProduct): void {
    const confirmed = window.confirm(this.copy(`Archive “${product.name}”?`, `Archiver « ${product.name} » ?`));
    if (!confirmed || this.sellerLoading) return;
    this.sellerLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.sellerError = '';
    this.commerce.archiveSellerProduct(product.id).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: () => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.sellerLoading = false;
        this.managedProducts = this.managedProducts.filter((item) => item.id !== product.id);
        if (this.productForm.id === product.id) this.resetProductForm();
        this.sellerSuccess = this.copy('Product archived.', 'Produit archivé.');
        this.refreshCatalog();
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.sellerLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.sellerError = this.apiError(error, this.copy('We could not archive the product.', 'Impossible d’archiver le produit.'));
      }
    });
  }
