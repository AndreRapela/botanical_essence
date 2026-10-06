import type { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import type { Router } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, takeUntil } from 'rxjs';

import type { AuthService } from './core/auth.service';
import type { CatalogService } from './core/catalog.service';
import type { CommerceService } from './core/commerce.service';
import type { Language, OwnProductReview, Page, Product, ProductReview, ProductReviewResponse } from './storefront.models';

export interface ProductActionsContext {
  auth: Pick<AuthService, 'sessionChanged$' | 'sessionVersion'>;
  catalog: Pick<CatalogService, 'loadProductBySlug' | 'loadMore' | 'reload' | 'products' | 'loading' | 'loaded' | 'error'>;
  commerce: Pick<CommerceService, 'productReviews' | 'saveProductReview'>;
  router: Pick<Router, 'navigate'>;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  document: Pick<Document, 'body'>;
  products: Product[];
  selectedProduct: Product | null;
  productReviews: ProductReview[];
  productReviewsPage: number;
  productReviewsHasMore: boolean;
  productReviewsRequestVersion: number;
  productReviewSummary: ProductReviewResponse['summary'];
  canReviewProduct: boolean;
  reviewForm: { rating: number; comment: string };
  reviewLoading: boolean;
  reviewLoadError: string;
  reviewError: string;
  reviewSuccess: string;
  ownReviewStatus: OwnProductReview['status'] | null;
  productSlug: string;
  productLookupSlug: string;
  productPageNotFound: boolean;
  productPageLoading: boolean;
  productPageError: string;
  currentPage: Page;
  currentLanguage: Language;
  lastFocused: HTMLElement | null;
  closeLayers(): void;
  apiError(error: HttpErrorResponse, fallback: string): string;
  copy(english: string, french: string): string;
  handleUnauthorized(error: HttpErrorResponse, expectedSessionVersion?: number): void;
  applyLanguage(language: Language): void;
  loadProductReviews(product: Product): void;
  reconcileCatalogStore(): void;
  resolveProductPage(): void;
  refreshCatalog(): void;
}

export function ratingStars(rating: number): string {
  const filled = Math.max(0, Math.min(5, Math.round(rating)));
  return '★'.repeat(filled) + '☆'.repeat(5 - filled);
}

export function openProductDetails(this: ProductActionsContext, product: Product): void {
  this.closeLayers();
  void this.router.navigate(['/product', product.slug]).then(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
}

export function loadProductReviews(this: ProductActionsContext, product: Product): void {
  const requestVersion = ++this.productReviewsRequestVersion;
  this.productReviews = [];
  this.productReviewsPage = 1;
  this.productReviewsHasMore = false;
  this.productReviewSummary = { average: product.averageRating, count: product.reviewCount };
  this.canReviewProduct = false;
  this.reviewForm = { rating: 5, comment: '' };
  this.ownReviewStatus = null;
  this.reviewError = '';
  this.reviewLoadError = '';
  this.reviewSuccess = '';
  this.reviewLoading = true;
  this.commerce.productReviews(product.id, 1).pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
    next: (response) => {
      if (this.selectedProduct?.id !== product.id || requestVersion !== this.productReviewsRequestVersion) return;
      this.reviewLoading = false;
      this.productReviews = response.data;
      this.productReviewSummary = response.summary;
      this.productReviewsPage = response.page;
      this.productReviewsHasMore = response.hasMore;
      this.canReviewProduct = response.canReview;
      if (response.myReview) {
        this.reviewForm = { rating: response.myReview.rating, comment: response.myReview.comment };
        this.ownReviewStatus = response.myReview.status;
      }
    },
    error: (error: HttpErrorResponse) => {
      if (this.selectedProduct?.id !== product.id || requestVersion !== this.productReviewsRequestVersion) return;
      this.reviewLoading = false;
      this.reviewLoadError = this.apiError(error, this.copy('We could not load the reviews.', 'Impossible de charger les avis.'));
    }
  });
}

export function loadOlderProductReviews(this: ProductActionsContext): void {
  if (!this.selectedProduct || this.reviewLoading || !this.productReviewsHasMore) return;
  const productId = this.selectedProduct.id;
  const page = this.productReviewsPage + 1;
  const requestVersion = ++this.productReviewsRequestVersion;
  this.reviewLoading = true;
  this.reviewLoadError = '';
  this.commerce.productReviews(productId, page).pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
    next: (response) => {
      if (this.selectedProduct?.id !== productId || requestVersion !== this.productReviewsRequestVersion) return;
      const knownReviewIds = new Set(this.productReviews.map((review) => review.id));
      this.productReviews = [...this.productReviews, ...response.data.filter((review) => !knownReviewIds.has(review.id))];
      this.productReviewSummary = response.summary;
      this.productReviewsPage = response.page;
      this.productReviewsHasMore = response.hasMore;
      this.reviewLoading = false;
    },
    error: (error: HttpErrorResponse) => {
      if (this.selectedProduct?.id !== productId || requestVersion !== this.productReviewsRequestVersion) return;
      this.reviewLoading = false;
      this.handleUnauthorized(error);
      this.reviewLoadError = this.apiError(error, this.copy('We could not load older reviews. Please try again.', 'Impossible de charger les avis précédents. Réessayez.'));
    }
  });
}

export function resolveProductPage(this: ProductActionsContext): void {
  if (this.currentPage !== 'product' || !this.productSlug) return;
  const product = this.products.find((item) => item.slug === this.productSlug) ?? null;
  if (product) {
    this.productPageLoading = false;
    this.productPageError = '';
    this.productPageNotFound = false;
    this.productLookupSlug = this.productSlug;
    if (this.selectedProduct?.id === product.id && (this.reviewLoading || this.productReviews.length || this.productReviewSummary.count === 0)) {
      this.selectedProduct = product;
      return;
    }
    this.selectedProduct = product;
    this.applyLanguage(this.currentLanguage);
    this.loadProductReviews(product);
    return;
  }

  this.selectedProduct = null;
  if (this.catalog.loading() || !this.catalog.loaded() || this.catalog.error()) return;
  if (this.productLookupSlug === this.productSlug && (this.productPageLoading || this.productPageNotFound)) return;

  const requestedSlug = this.productSlug;
  this.productLookupSlug = requestedSlug;
  this.productPageLoading = true;
  this.productPageError = '';
  this.productPageNotFound = false;
  this.catalog.loadProductBySlug(requestedSlug).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
    next: (loadedProduct) => {
      if (this.currentPage !== 'product' || this.productSlug !== requestedSlug) return;
      this.productPageLoading = false;
      this.productPageNotFound = loadedProduct === null;
      if (loadedProduct) {
        this.reconcileCatalogStore();
        this.resolveProductPage();
      }
    },
    error: (error: HttpErrorResponse) => {
      if (this.currentPage !== 'product' || this.productSlug !== requestedSlug) return;
      this.productPageLoading = false;
      this.productPageError = this.apiError(error, this.copy('We could not load this product.', 'Impossible de charger ce produit.'));
    }
  });
}

export function loadMoreProducts(this: ProductActionsContext): void {
  this.catalog.loadMore().pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
    next: () => this.reconcileCatalogStore(),
    error: () => { /* The catalog exposes its own error state. */ }
  });
}

export function retryCatalog(this: ProductActionsContext): void {
  this.productPageError = '';
  this.productPageNotFound = false;
  this.productLookupSlug = '';
  this.catalog.reload().pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
    next: () => {
      this.reconcileCatalogStore();
      this.resolveProductPage();
    },
    error: () => { /* The catalog exposes its own error state. */ }
  });
}

export function closeProductDetails(this: ProductActionsContext): void {
  if (!this.selectedProduct) return;
  this.productReviewsRequestVersion++;
  this.selectedProduct = null;
  this.productReviews = [];
  this.productReviewsPage = 1;
  this.productReviewsHasMore = false;
  this.reviewLoading = false;
  this.canReviewProduct = false;
  this.ownReviewStatus = null;
  this.productReviewSummary = { average: 0, count: 0 };
  this.reviewError = '';
  this.reviewLoadError = '';
  this.reviewSuccess = '';
  this.document.body.classList.remove('layer-open');
  this.lastFocused?.focus();
  this.lastFocused = null;
}

export function submitProductReview(this: ProductActionsContext): void {
  if (!this.selectedProduct || this.reviewLoading || !this.canReviewProduct) return;
  const submittedProductId = this.selectedProduct.id;
  const sessionVersion = this.auth.sessionVersion;
  const wasUpdate = this.ownReviewStatus !== null;
  this.reviewError = '';
  this.reviewSuccess = '';
  this.reviewLoading = true;
  this.commerce.saveProductReview({ productId: this.selectedProduct.id, ...this.reviewForm }).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
    next: ({ status }) => {
      if (sessionVersion !== this.auth.sessionVersion) return;
      const product = this.selectedProduct;
      if (product?.id !== submittedProductId) {
        this.refreshCatalog();
        return;
      }
      this.reviewSuccess = wasUpdate
        ? this.copy('Review updated.', 'Avis mis à jour.')
        : this.copy('Review published.', 'Avis publié.');
      this.ownReviewStatus = status;
      if (product) {
        const requestVersion = ++this.productReviewsRequestVersion;
        this.commerce.productReviews(product.id, 1).pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
          next: (response) => {
            if (this.selectedProduct?.id !== product.id || requestVersion !== this.productReviewsRequestVersion) return;
            this.reviewLoading = false;
            this.productReviews = response.data;
            this.productReviewSummary = response.summary;
            this.productReviewsPage = response.page;
            this.productReviewsHasMore = response.hasMore;
            this.canReviewProduct = response.canReview;
            this.refreshCatalog();
          },
          error: () => {
            if (this.selectedProduct?.id === product.id && requestVersion === this.productReviewsRequestVersion) this.reviewLoading = false;
          }
        });
      } else {
        this.reviewLoading = false;
      }
    },
    error: (error: HttpErrorResponse) => {
      if (sessionVersion !== this.auth.sessionVersion) return;
      this.reviewLoading = false;
      this.handleUnauthorized(error, sessionVersion);
      this.reviewError = this.apiError(error, this.copy('We could not publish your review.', 'Impossible de publier votre avis.'));
    }
  });
}
