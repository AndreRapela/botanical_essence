import type { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, takeUntil } from 'rxjs';

import type { AuthService } from './core/auth.service';
import type { CommerceService } from './core/commerce.service';
import type { AdminPaymentDispute, AdminWithdrawalRequest, AuthUser } from './storefront.models';

export interface AdminQueuesActionsContext {
  auth: Pick<AuthService, 'sessionChanged$'>;
  user: AuthUser | null;
  commerce: Pick<CommerceService, 'adminWithdrawals' | 'adminPaymentDisputes'>;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  adminLoading: boolean;
  adminError: string;
  adminWithdrawals: AdminWithdrawalRequest[];
  adminWithdrawalsHasMore: boolean;
  adminWithdrawalsPage: number;
  adminWithdrawalsTotal: number;
  adminPaymentDisputes: AdminPaymentDispute[];
  adminPaymentDisputesHasMore: boolean;
  adminPaymentDisputesPage: number;
  adminPaymentDisputesTotal: number;
  handleUnauthorized(error: HttpErrorResponse): void;
  apiError(error: HttpErrorResponse, fallback: string): string;
  copy(english: string, french: string): string;
}

export function loadOlderAdminWithdrawals(this: AdminQueuesActionsContext): void {
  if (this.adminLoading || !this.adminWithdrawalsHasMore || this.user?.role !== 'admin') return;
  this.adminLoading = true;
  this.adminError = '';
  this.commerce.adminWithdrawals(this.adminWithdrawalsPage + 1)
    .pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck()))
    .subscribe({
      next: (page) => {
        const knownIds = new Set(this.adminWithdrawals.map((request) => request.id));
        this.adminWithdrawals = [...this.adminWithdrawals, ...page.data.filter((request) => !knownIds.has(request.id))];
        this.adminWithdrawalsPage = page.page;
        this.adminWithdrawalsTotal = page.total;
        this.adminWithdrawalsHasMore = page.hasMore;
        this.adminLoading = false;
      },
      error: (error: HttpErrorResponse) => {
        this.adminLoading = false;
        this.handleUnauthorized(error);
        this.adminError = this.apiError(error, this.copy('We could not load older consumer requests.', 'Impossible de charger les anciennes demandes des consommateurs.'));
      }
    });
}

export function loadOlderAdminDisputes(this: AdminQueuesActionsContext): void {
  if (this.adminLoading || !this.adminPaymentDisputesHasMore || this.user?.role !== 'admin') return;
  this.adminLoading = true;
  this.adminError = '';
  this.commerce.adminPaymentDisputes(this.adminPaymentDisputesPage + 1)
    .pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck()))
    .subscribe({
      next: (page) => {
        const knownIds = new Set(this.adminPaymentDisputes.map((dispute) => dispute.id));
        this.adminPaymentDisputes = [...this.adminPaymentDisputes, ...page.data.filter((dispute) => !knownIds.has(dispute.id))];
        this.adminPaymentDisputesPage = page.page;
        this.adminPaymentDisputesTotal = page.total;
        this.adminPaymentDisputesHasMore = page.hasMore;
        this.adminLoading = false;
      },
      error: (error: HttpErrorResponse) => {
        this.adminLoading = false;
        this.handleUnauthorized(error);
        this.adminError = this.apiError(error, this.copy('We could not load older Stripe disputes.', 'Impossible de charger les anciens litiges Stripe.'));
      }
    });
}
