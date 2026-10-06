import type { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, takeUntil } from 'rxjs';

import type { AuthService } from './core/auth.service';
import type { CommerceService } from './core/commerce.service';
import type { AdminContactMessage, AuthUser } from './storefront.models';

export function withAdminContactMessageStatus(
  messages: AdminContactMessage[],
  messageId: number,
  status: AdminContactMessage['status']
): AdminContactMessage[] {
  let changed = false;
  const updated = messages.map((message) => {
    if (message.id !== messageId) return message;
    changed = true;
    return { ...message, status };
  });
  return changed ? updated : messages;
}

export interface AdminSupportActionsContext {
  auth: Pick<AuthService, 'sessionChanged$' | 'sessionVersion'>;
  user: AuthUser | null;
  commerce: Pick<CommerceService, 'adminContactMessages' | 'updateAdminContactMessage'>;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  adminLoading: boolean;
  adminError: string;
  adminSuccess: string;
  adminContactMessages: AdminContactMessage[];
  adminContactMessagesHasMore: boolean;
  adminContactMessagesPage: number;
  adminContactMessagesTotal: number;
  handleUnauthorized(error: HttpErrorResponse, expectedSessionVersion?: number): void;
  apiError(error: HttpErrorResponse, fallback: string): string;
  copy(english: string, french: string): string;
}

export function loadOlderAdminContactMessages(this: AdminSupportActionsContext): void {
  if (this.adminLoading || !this.adminContactMessagesHasMore || this.user?.role !== 'admin') return;
  this.adminLoading = true;
  this.adminError = '';
  const nextPage = this.adminContactMessagesPage + 1;
  this.commerce.adminContactMessages(nextPage)
    .pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck()))
    .subscribe({
      next: (response) => {
        this.adminContactMessages = [...this.adminContactMessages, ...response.data];
        this.adminContactMessagesPage = response.page;
        this.adminContactMessagesTotal = response.total;
        this.adminContactMessagesHasMore = response.hasMore;
        this.adminLoading = false;
      },
      error: (error: HttpErrorResponse) => {
        this.adminLoading = false;
        this.handleUnauthorized(error);
        this.adminError = this.apiError(error, this.copy('We could not load older contact messages.', 'Impossible de charger les anciens messages.'));
      }
    });
}

export function updateAdminContactMessage(
  this: AdminSupportActionsContext,
  message: AdminContactMessage,
  status: AdminContactMessage['status']
): void {
  if (this.adminLoading || this.user?.role !== 'admin' || message.status === status) return;
  this.adminLoading = true;
  const sessionVersion = this.auth.sessionVersion;
  this.adminError = '';
  this.commerce.updateAdminContactMessage(message.id, status)
    .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck()))
    .subscribe({
      next: () => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminContactMessages = withAdminContactMessageStatus(this.adminContactMessages, message.id, status);
        this.adminSuccess = this.copy('Contact message status updated.', 'Statut du message mis à jour.');
        this.adminLoading = false;
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.adminError = this.apiError(error, this.copy('We could not update this contact message.', 'Impossible de mettre à jour ce message.'));
      }
    });
}
