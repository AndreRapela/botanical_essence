import type { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, takeUntil } from 'rxjs';

import type { AuthService } from './core/auth.service';
import type { CommerceService } from './core/commerce.service';
import type { Router } from '@angular/router';
import type { AccountProfile, AdminOrder, AdminProduct, AdminReview, AdminSeller, AdminUser, AuthUser, AuthMode, ManagedProduct, MarketplaceStore, OrderSummary, Page } from './storefront.models';

export interface AuthenticatedSessionContext {
  user: AuthUser | null;
  currentPage: Page;
  checkoutForm: { name: string; email: string };
  checkoutIdempotencyKey: string;
  paymentReturnSessionId: string;
  pendingCheckoutOrderNumber: string;
  readCheckoutStorage(kind: 'key' | 'order'): string;
  verifyCheckoutReturn(): void;
  loadCheckoutOrderStatus(orderNumber: string): void;
  loadAccountData(): void;
  loadSellerDashboard(): void;
  loadAdminSellers(): void;
}

export interface LoadAccountDataContext {
  user: AuthUser | null;
  loadOrders(): void;
  loadProfile(): void;
  loadSellerDashboard(): void;
}

export interface AccountProfileContext {
  user: AuthUser | null;
  auth: Pick<AuthService, 'sessionChanged$' | 'sessionVersion'>;
  commerce: Pick<CommerceService, 'profile'>;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  profileLoading: boolean;
  profileError: string;
  profileForm: { name: string; bio: string; city: string; region: string; country: string };
  accountProfile: AccountProfile | null;
  sellerStore: MarketplaceStore | null;
  sellerForm: { city: string; region: string; country: string };
  handleUnauthorized(error: HttpErrorResponse, expectedSessionVersion?: number): void;
  apiError(error: HttpErrorResponse, fallback: string): string;
  copy(english: string, french: string): string;
}

export interface AccountOrdersContext {
  user: AuthUser | null;
  auth: Pick<AuthService, 'sessionChanged$' | 'sessionVersion'>;
  commerce: Pick<CommerceService, 'orders'>;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  orders: OrderSummary[];
  ordersLoading: boolean;
  ordersPage: number;
  ordersTotal: number;
  ordersHasMore: boolean;
  ordersError: string;
  handleUnauthorized(error: HttpErrorResponse, expectedSessionVersion?: number): void;
  apiError(error: HttpErrorResponse, fallback: string): string;
  copy(english: string, french: string): string;
}

export interface OverlayLayerContext {
  menuOpen: boolean;
  searchOpen: boolean;
  cartOpen: boolean;
  lastFocused: HTMLElement | null;
  document: Document;
}

export interface UnauthorizedSessionContext {
  auth: Pick<AuthService, 'markSignedOut' | 'sessionVersion'>;
  router: Pick<Router, 'navigateByUrl'>;
  clearSensitiveSessionData(): void;
}

export function syncAuthenticatedUser(this: AuthenticatedSessionContext): void {
    if (!this.user) return;
    this.checkoutForm.name = this.user.name;
    this.checkoutForm.email = this.user.email;
    this.checkoutIdempotencyKey ||= this.readCheckoutStorage('key');
    this.verifyCheckoutReturn();
    if (!this.paymentReturnSessionId) {
      this.pendingCheckoutOrderNumber ||= this.readCheckoutStorage('order');
      if (this.pendingCheckoutOrderNumber) this.loadCheckoutOrderStatus(this.pendingCheckoutOrderNumber);
    }
    if (this.currentPage === 'account') this.loadAccountData();
    if (this.currentPage === 'seller' && this.user.role !== 'admin') this.loadSellerDashboard();
    if (this.currentPage === 'admin' && this.user.role === 'admin') this.loadAdminSellers();
  }

export function loadAccountData(this: LoadAccountDataContext): void {
    this.loadOrders();
    this.loadProfile();
    if (this.user && this.user.role !== 'admin') this.loadSellerDashboard();
  }

export function loadProfile(this: AccountProfileContext): void {
    if (!this.user || this.profileLoading) return;
    const sessionVersion = this.auth.sessionVersion;
    this.profileLoading = true;
    this.profileError = '';
    this.commerce.profile().pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: ({ profile }) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.profileLoading = false;
        this.accountProfile = profile;
        this.profileForm = {
          name: profile.name,
          bio: profile.bio ?? '',
          city: profile.city ?? '',
          region: profile.region ?? '',
          country: profile.country ?? 'Belgium'
        };
        if (!this.sellerStore) {
          this.sellerForm.city ||= profile.city ?? '';
          this.sellerForm.region ||= profile.region ?? '';
          this.sellerForm.country ||= profile.country ?? 'Belgium';
        }
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.profileLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.profileError = this.apiError(error, this.copy('We could not load your profile.', 'Impossible de charger votre profil.'));
      }
    });
  }

export function prepareLayer(this: OverlayLayerContext): void {
    if (!this.menuOpen && !this.searchOpen && !this.cartOpen) {
      this.lastFocused = this.document.activeElement instanceof HTMLElement ? this.document.activeElement : null;
    }
    this.document.body.classList.add('layer-open');
  }

export function loadOrders(this: AccountOrdersContext): void {
    if (!this.user || this.ordersLoading) return;
    const sessionVersion = this.auth.sessionVersion;
    this.ordersLoading = true;
    this.ordersError = '';
    this.commerce.orders(1).pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: (result) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.orders = result.data;
        this.ordersPage = result.page;
        this.ordersTotal = result.total;
        this.ordersHasMore = result.hasMore;
        this.ordersLoading = false;
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.ordersLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.ordersError = this.apiError(error, this.copy('We could not load your order history.', 'Impossible de charger votre historique de commandes.'));
      }
    });
  }

export function loadOlderOrders(this: AccountOrdersContext): void {
    if (!this.user || this.ordersLoading || !this.ordersHasMore) return;
    const sessionVersion = this.auth.sessionVersion;
    this.ordersLoading = true;
    this.ordersError = '';
    this.commerce.orders(this.ordersPage + 1).pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: (result) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        const knownOrders = new Set(this.orders.map((order) => order.number));
        this.orders = [...this.orders, ...result.data.filter((order) => !knownOrders.has(order.number))];
        this.ordersPage = result.page;
        this.ordersTotal = result.total;
        this.ordersHasMore = result.hasMore;
        this.ordersLoading = false;
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.ordersLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.ordersError = this.apiError(error, this.copy('We could not load older orders. Please try again.', 'Impossible de charger les anciennes commandes. Réessayez.'));
      }
    });
  }

export function apiError(_error: HttpErrorResponse, fallback: string): string {
    // The API remains locale-neutral from the UI's perspective. Raw backend
    // messages may contain operational detail or use a different language.
    return fallback;
  }

export function handleUnauthorized(this: UnauthorizedSessionContext, error: HttpErrorResponse, expectedSessionVersion = this.auth.sessionVersion): void {
    if (error.status !== 401 || expectedSessionVersion !== this.auth.sessionVersion) return;
    const previousSessionVersion = this.auth.sessionVersion;
    this.auth.markSignedOut();
    if (this.auth.sessionVersion === previousSessionVersion) {
      this.clearSensitiveSessionData();
      void this.router.navigateByUrl('/login');
    }
  }
