import type { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, takeUntil } from 'rxjs';

import type { AuthService } from './core/auth.service';
import type { CommerceService } from './core/commerce.service';
import type { AdminProduct, AdminReview, AdminSeller, AdminUser, AuthUser } from './storefront.models';

export interface AdminManagementActionsContext {
  auth: Pick<AuthService, 'sessionChanged$'>;
  user: AuthUser | null;
  commerce: Pick<CommerceService, 'adminOverview' | 'adminSellers'>;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  adminLoading: boolean;
  adminError: string;
  adminOverviewHasMore: boolean;
  adminOverviewPage: number;
  adminUsers: AdminUser[];
  adminProducts: AdminProduct[];
  adminReviews: AdminReview[];
  adminUsersTotal: number;
  adminProductsTotal: number;
  adminReviewsTotal: number;
  adminSellers: AdminSeller[];
  adminSellerPage: number;
  adminSellersTotal: number;
  adminSellersHasMore: boolean;
  handleUnauthorized(error: HttpErrorResponse): void;
  apiError(error: HttpErrorResponse, fallback: string): string;
  copy(english: string, french: string): string;
}

function appendUnique<T extends { id: number }>(current: T[], incoming: T[]): T[] {
  const known = new Set(current.map((item) => item.id));
  return [...current, ...incoming.filter((item) => !known.has(item.id))];
}

export function loadOlderAdminManagement(this: AdminManagementActionsContext): void {
  if (this.adminLoading || !this.adminOverviewHasMore || this.user?.role !== 'admin') return;
  this.adminLoading = true;
  this.adminError = '';
  this.commerce.adminOverview(this.adminOverviewPage + 1)
    .pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck()))
    .subscribe({
      next: (page) => {
        this.adminUsers = appendUnique(this.adminUsers, page.users);
        this.adminProducts = appendUnique(this.adminProducts, page.products);
        this.adminReviews = appendUnique(this.adminReviews, page.reviews);
        this.adminOverviewPage = page.page;
        this.adminUsersTotal = page.usersTotal;
        this.adminProductsTotal = page.productsTotal;
        this.adminReviewsTotal = page.reviewsTotal;
        this.adminOverviewHasMore = page.usersHasMore || page.productsHasMore || page.reviewsHasMore;
        this.adminLoading = false;
      },
      error: (error: HttpErrorResponse) => {
        this.adminLoading = false;
        this.handleUnauthorized(error);
        this.adminError = this.apiError(error, this.copy('We could not load more accounts, products and reviews.', 'Impossible de charger davantage de comptes, produits et avis.'));
      }
    });
}

export function loadOlderAdminSellers(this: AdminManagementActionsContext): void {
  if (this.adminLoading || !this.adminSellersHasMore || this.user?.role !== 'admin') return;
  this.adminLoading = true;
  this.adminError = '';
  this.commerce.adminSellers(this.adminSellerPage + 1)
    .pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck()))
    .subscribe({
      next: (page) => {
        this.adminSellers = appendUnique(this.adminSellers, page.data);
        this.adminSellerPage = page.page;
        this.adminSellersTotal = page.total;
        this.adminSellersHasMore = page.hasMore;
        this.adminLoading = false;
      },
      error: (error: HttpErrorResponse) => {
        this.adminLoading = false;
        this.handleUnauthorized(error);
        this.adminError = this.apiError(error, this.copy('We could not load more seller accounts.', 'Impossible de charger davantage de comptes vendeurs.'));
      }
    });
}
