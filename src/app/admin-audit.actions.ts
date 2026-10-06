import type { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, takeUntil } from 'rxjs';

import type { AuthService } from './core/auth.service';
import type { CommerceService } from './core/commerce.service';
import type { AdminAuditEntry, AuthUser } from './storefront.models';

export interface AdminAuditActionsContext {
  auth: Pick<AuthService, 'sessionChanged$'>;
  user: AuthUser | null;
  commerce: Pick<CommerceService, 'adminAuditLog'>;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  adminLoading: boolean;
  adminError: string;
  adminAuditEntries: AdminAuditEntry[];
  adminAuditHasMore: boolean;
  adminAuditPage: number;
  adminAuditTotal: number;
  handleUnauthorized(error: HttpErrorResponse): void;
  apiError(error: HttpErrorResponse, fallback: string): string;
  copy(english: string, french: string): string;
}

export function loadOlderAdminAuditLog(this: AdminAuditActionsContext): void {
  if (this.adminLoading || !this.adminAuditHasMore || this.user?.role !== 'admin') return;
  this.adminLoading = true;
  this.adminError = '';
  this.commerce.adminAuditLog(this.adminAuditPage + 1)
    .pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck()))
    .subscribe({
      next: (page) => {
        this.adminAuditEntries = [...this.adminAuditEntries, ...page.data.filter((entry) => !this.adminAuditEntries.some((loaded) => loaded.id === entry.id))];
        this.adminAuditPage = page.page;
        this.adminAuditTotal = page.total;
        this.adminAuditHasMore = page.hasMore;
        this.adminLoading = false;
      },
      error: (error: HttpErrorResponse) => {
        this.adminLoading = false;
        this.handleUnauthorized(error);
        this.adminError = this.apiError(error, this.copy('We could not load older administrative activity.', 'Impossible de charger les anciennes activités administratives.'));
      }
    });
}
