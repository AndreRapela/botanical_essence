import type { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, takeUntil } from 'rxjs';

import type { AuthService } from './core/auth.service';
import type { CommerceService } from './core/commerce.service';
import type { AdminPaymentNotification, AuthUser } from './storefront.models';

export interface AdminNotificationsActionsContext {
  auth: Pick<AuthService, 'sessionChanged$' | 'sessionVersion'>;
  user: AuthUser | null;
  commerce: Pick<CommerceService, 'adminPaymentNotifications' | 'retryAdminPaymentNotification'>;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  adminLoading: boolean;
  adminError: string;
  adminSuccess: string;
  adminPaymentNotifications: AdminPaymentNotification[];
  adminPaymentNotificationsHasMore: boolean;
  adminPaymentNotificationsPage: number;
  adminPaymentNotificationsTotal: number;
  handleUnauthorized(error: HttpErrorResponse, expectedSessionVersion?: number): void;
  apiError(error: HttpErrorResponse, fallback: string): string;
  copy(english: string, french: string): string;
  loadAdminSellers(): void;
}

export function loadOlderAdminPaymentNotifications(this: AdminNotificationsActionsContext): void {
  if (this.adminLoading || !this.adminPaymentNotificationsHasMore || this.user?.role !== 'admin') return;
  this.adminLoading = true;
  this.adminError = '';
  this.commerce.adminPaymentNotifications(this.adminPaymentNotificationsPage + 1)
    .pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck()))
    .subscribe({
      next: (page) => {
        const knownIds = new Set(this.adminPaymentNotifications.map((item) => item.id));
        this.adminPaymentNotifications = [...this.adminPaymentNotifications, ...page.data.filter((item) => !knownIds.has(item.id))];
        this.adminPaymentNotificationsPage = page.page;
        this.adminPaymentNotificationsTotal = page.total;
        this.adminPaymentNotificationsHasMore = page.hasMore;
        this.adminLoading = false;
      },
      error: (error: HttpErrorResponse) => {
        this.adminLoading = false;
        this.handleUnauthorized(error);
        this.adminError = this.apiError(error, this.copy('We could not load older payment email issues.', 'Impossible de charger les anciens incidents d’e-mail de paiement.'));
      }
    });
}

export function retryAdminPaymentNotification(this: AdminNotificationsActionsContext, notification: AdminPaymentNotification): void {
  if (this.adminLoading || this.user?.role !== 'admin' || !notification.retryAvailable) return;
  const reason = window.prompt(this.copy(
    `Describe what you fixed before retrying email for ${notification.orderNumber} (10–500 characters):`,
    `Décrivez ce que vous avez corrigé avant de réessayer l’e-mail de ${notification.orderNumber} (10 à 500 caractères) :`
  ))?.trim();
  if (!reason || reason.length < 10 || reason.length > 500) return;
  if (!window.confirm(this.copy(
    'Retry this email now? If the mail server accepted the previous message but its acknowledgment was lost, the customer may receive a duplicate. This action is audited.',
    'Réessayer cet e-mail maintenant ? Si le serveur a accepté le message précédent mais que son accusé de réception a été perdu, le client peut recevoir un doublon. Cette action est auditée.'
  ))) return;

  this.adminLoading = true;
  const sessionVersion = this.auth.sessionVersion;
  this.adminError = '';
  this.adminSuccess = '';
  this.commerce.retryAdminPaymentNotification(notification.id, reason)
    .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck()))
    .subscribe({
      next: ({ message }) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminLoading = false;
        this.adminSuccess = message;
        this.loadAdminSellers();
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.adminLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.adminError = this.apiError(error, this.copy('The email could not be queued for retry. Check the notification state before trying again.', 'L’e-mail n’a pas pu être remis dans la file. Vérifiez son état avant de réessayer.'));
      }
    });
}
