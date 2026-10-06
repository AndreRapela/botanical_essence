import type { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, forkJoin, takeUntil } from 'rxjs';

import { confirmAdminOrderCancellation, handleAdminRefundInteraction } from './admin-refund-interaction';
import type { AdminOrderAction, AdminRefundAction, RefundResolution } from './admin-refund-interaction';
import type { CommerceService } from './core/commerce.service';
import type { AuthService } from './core/auth.service';
import type { AdminAuditEntry, AdminContactMessage, AdminOrder, AdminPaymentDispute, AdminPaymentNotification, AdminProduct, AdminReview, AdminSeller, AdminUser, AdminWithdrawalRequest, AuthUser, Currency } from './storefront.models';

type AdminOrderActionName = AdminOrderAction | 'resolve_checkout_attempt';

/** Finance/admin actions depend on this explicit backend-facing shell contract. */
export interface AdminOrdersActionsContext {
  auth: Pick<AuthService, 'sessionChanged$' | 'sessionVersion'>;
  commerce: Pick<CommerceService,
    | 'adminSellers'
    | 'adminOverview'
    | 'adminOrders'
    | 'adminWithdrawals'
    | 'adminPaymentDisputes'
    | 'adminPaymentNotifications'
    | 'adminContactMessages'
    | 'adminAuditLog'
    | 'partialRefund'
    | 'cancelAdminOrder'>;
  user: AuthUser | null;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  adminLoading: boolean;
  adminError: string;
  adminSuccess: string;
  adminSellers: AdminSeller[];
  adminSellerPage: number;
  adminSellersTotal: number;
  adminSellersHasMore: boolean;
  adminUsers: AdminUser[];
  adminProducts: AdminProduct[];
  adminReviews: AdminReview[];
  adminOverviewPage: number;
  adminUsersTotal: number;
  adminProductsTotal: number;
  adminReviewsTotal: number;
  adminOverviewHasMore: boolean;
  adminOrders: AdminOrder[];
  adminOrderPage: number;
  adminOrdersTotal: number;
  adminOrdersHasMore: boolean;
  adminWithdrawals: AdminWithdrawalRequest[];
  adminWithdrawalsPage: number;
  adminWithdrawalsTotal: number;
  adminWithdrawalsHasMore: boolean;
  adminPaymentDisputes: AdminPaymentDispute[];
  adminPaymentDisputesPage: number;
  adminPaymentDisputesTotal: number;
  adminPaymentDisputesHasMore: boolean;
  adminPaymentNotifications: AdminPaymentNotification[];
  adminPaymentNotificationsPage: number;
  adminPaymentNotificationsTotal: number;
  adminPaymentNotificationsHasMore: boolean;
  adminContactMessages: AdminContactMessage[];
  adminContactMessagesPage: number;
  adminContactMessagesTotal: number;
  adminContactMessagesHasMore: boolean;
  adminAuditEntries: AdminAuditEntry[];
  adminAuditPage: number;
  adminAuditTotal: number;
  adminAuditHasMore: boolean;
  apiError(error: HttpErrorResponse, fallback: string): string;
  handleUnauthorized(error: HttpErrorResponse, expectedSessionVersion?: number): void;
  copy(english: string, french: string): string;
  formatPrice(value: number, currency: Currency): string;
  loadAdminSellers(): void;
  runAdminOrderAction(order: AdminOrder, action: AdminOrderActionName, resolution?: RefundResolution, reason?: string): void;
}

export function loadAdminSellers(this: AdminOrdersActionsContext): void {
    if (this.adminLoading || this.user?.role !== 'admin') return;
    this.adminLoading = true;
    this.adminError = '';
    forkJoin({
      sellers: this.commerce.adminSellers(1),
      overview: this.commerce.adminOverview(),
      orders: this.commerce.adminOrders(1),
      withdrawals: this.commerce.adminWithdrawals(1),
      disputes: this.commerce.adminPaymentDisputes(1),
      notifications: this.commerce.adminPaymentNotifications(1),
      contactMessages: this.commerce.adminContactMessages(1),
      auditLog: this.commerce.adminAuditLog(1)
    }).pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: ({ sellers, overview, orders, withdrawals, disputes, notifications, contactMessages, auditLog }) => {
        this.adminSellers = sellers.data;
        this.adminSellerPage = sellers.page;
        this.adminSellersTotal = sellers.total;
        this.adminSellersHasMore = sellers.hasMore;
        this.adminUsers = overview.users;
        this.adminProducts = overview.products;
        this.adminReviews = overview.reviews;
        this.adminOverviewPage = overview.page;
        this.adminUsersTotal = overview.usersTotal;
        this.adminProductsTotal = overview.productsTotal;
        this.adminReviewsTotal = overview.reviewsTotal;
        this.adminOverviewHasMore = overview.usersHasMore || overview.productsHasMore || overview.reviewsHasMore;
        this.adminOrders = orders.data;
        this.adminOrderPage = orders.page;
        this.adminOrdersTotal = orders.total;
        this.adminOrdersHasMore = orders.hasMore;
        this.adminWithdrawals = withdrawals.data;
        this.adminWithdrawalsPage = withdrawals.page;
        this.adminWithdrawalsTotal = withdrawals.total;
        this.adminWithdrawalsHasMore = withdrawals.hasMore;
        this.adminPaymentDisputes = disputes.data;
        this.adminPaymentDisputesPage = disputes.page;
        this.adminPaymentDisputesTotal = disputes.total;
        this.adminPaymentDisputesHasMore = disputes.hasMore;
        this.adminPaymentNotifications = notifications.data;
        this.adminPaymentNotificationsPage = notifications.page;
        this.adminPaymentNotificationsTotal = notifications.total;
        this.adminPaymentNotificationsHasMore = notifications.hasMore;
        this.adminContactMessages = contactMessages.data;
        this.adminContactMessagesPage = contactMessages.page;
        this.adminContactMessagesTotal = contactMessages.total;
        this.adminContactMessagesHasMore = contactMessages.hasMore;
        this.adminAuditEntries = auditLog.data;
        this.adminAuditPage = auditLog.page;
        this.adminAuditTotal = auditLog.total;
        this.adminAuditHasMore = auditLog.hasMore;
        this.adminLoading = false;
      },
      error: (error: HttpErrorResponse) => {
        this.adminLoading = false;
        this.handleUnauthorized(error);
        this.adminError = this.apiError(error, this.copy('We could not load the administration and recent orders.', 'Impossible de charger l’administration et les commandes récentes.'));
      }
    });
  }

export function loadOlderAdminOrders(this: AdminOrdersActionsContext): void {
    if (this.adminLoading || !this.adminOrdersHasMore || this.user?.role !== 'admin') return;
    this.adminLoading = true;
    this.adminError = '';
    this.commerce.adminOrders(this.adminOrderPage + 1).pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: (page) => {
        this.adminOrders = [...this.adminOrders, ...page.data.filter((order) => !this.adminOrders.some((loaded) => loaded.id === order.id))];
        this.adminOrderPage = page.page;
        this.adminOrdersTotal = page.total;
        this.adminOrdersHasMore = page.hasMore;
        this.adminLoading = false;
      },
      error: (error: HttpErrorResponse) => {
        this.adminLoading = false;
        this.handleUnauthorized(error);
        this.adminError = this.apiError(error, this.copy('We could not load older orders.', 'Impossible de charger les anciennes commandes.'));
      }
    });
  }

export function partialRefundAdminOrder(this: AdminOrdersActionsContext, order: AdminOrder): void {
    if (this.adminLoading || order.paymentProvider !== 'stripe' || !['paid', 'partially_refunded'].includes(order.paymentStatus)) return;
    const remaining = Math.max(0, Math.round(order.total * 100) - order.refundedTotalCents);
    if (remaining < 1) return;
    const amountInput = window.prompt(this.copy(
      `Enter a partial refund amount in ${order.currency} (maximum ${(remaining / 100).toFixed(2)}).`,
      `Saisissez le montant du remboursement partiel en ${order.currency} (maximum ${(remaining / 100).toFixed(2)}).`
    ));
    if (amountInput === null) return;
    const normalized = amountInput.trim().replace(',', '.');
    if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) {
      this.adminError = this.copy('Enter a valid amount with up to two decimal places.', 'Saisissez un montant valide avec au plus deux décimales.');
      return;
    }
    const [whole, fraction = ''] = normalized.split('.');
    const amountCents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    if (!Number.isSafeInteger(amountCents) || amountCents < 1 || amountCents > remaining) {
      this.adminError = this.copy('The refund must be greater than zero and no more than the amount still available.', 'Le remboursement doit être supérieur à zéro et ne pas dépasser le solde disponible.');
      return;
    }
    const reason = window.prompt(this.copy('Enter an audit reason (at least 10 characters).', 'Saisissez un motif d’audit (10 caractères minimum).'))?.trim();
    if (!reason || reason.length < 10) return;
    if (!window.confirm(this.copy(`Confirm refund of ${this.formatPrice(amountCents / 100, order.currency)} for order ${order.number}?`, `Confirmer le remboursement de ${this.formatPrice(amountCents / 100, order.currency)} pour la commande ${order.number} ?`))) return;
    this.adminLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.adminError = '';
    this.commerce.partialRefund(order.number, amountCents, reason).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
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
        this.adminError = this.apiError(error, this.copy('The partial refund could not be completed. Check its Stripe state before retrying.', 'Le remboursement partiel n’a pas abouti. Vérifiez son état dans Stripe avant de réessayer.'));
      }
    });
  }

export function cancelAdminOrder(this: AdminOrdersActionsContext, order: AdminOrder): void {
    const copy = (english: string, french: string) => this.copy(english, french);
    if (order.paymentReconciliationRequired) {
      resolveUnattachedCheckoutAttempt.call(this, order);
      return;
    }
    if (this.adminLoading || !confirmAdminOrderCancellation(order, copy)) return;
    this.runAdminOrderAction(order, 'cancel');
}

export function resolveUnattachedCheckoutAttempt(this: AdminOrdersActionsContext, order: AdminOrder): void {
    if (this.adminLoading || !order.paymentReconciliationRequired
        || !['awaiting_payment', 'cancelled'].includes(order.status) || order.paymentStatus !== 'pending') return;
    const reason = window.prompt(this.copy(
      `After checking Stripe for order ${order.number}, record how you verified that no Checkout Session and no PaymentIntent (including one still processing) exists (10–500 characters):`,
      `Après avoir vérifié Stripe pour la commande ${order.number}, indiquez comment vous avez confirmé qu’il n’existe ni session Checkout ni PaymentIntent, y compris en cours de traitement (10 à 500 caractères) :`
    ))?.trim();
    if (!reason || reason.length < 10 || reason.length > 500) return;
    if (!window.confirm(this.copy(
      'Confirm that you independently verified this exact order in Stripe and found no Checkout Session and no PaymentIntent (processing or settled). The reservation will be released.',
      'Confirmez avoir vérifié cette commande précise dans Stripe et n’avoir trouvé ni session Checkout ni PaymentIntent (en cours ou réglé). La réservation sera libérée.'
    ))) return;
    this.runAdminOrderAction(order, 'resolve_checkout_attempt', undefined, reason);
}

export function adminRefundAction(this: AdminOrdersActionsContext, order: AdminOrder, action: AdminRefundAction, resolution?: RefundResolution): void {
    if (order.refundAttemptIsPartial && action === 'resolve_refund' && resolution) {
      if (this.adminLoading) return;
      const reason = window.prompt(this.copy(
        `Only resolve after independently checking the Stripe refund. Explain why attempt #${order.refundAttempt} is being recorded as ${resolution} (10–500 characters):`,
        `Ne résolvez qu’après vérification indépendante du remboursement Stripe. Expliquez pourquoi la tentative #${order.refundAttempt} est marquée ${resolution === 'refunded' ? 'remboursée' : 'non remboursée'} (10 à 500 caractères) :`
      ))?.trim();
      if (!reason || reason.length < 10 || reason.length > 500) return;
      if (!window.confirm(this.copy(
        resolution === 'refunded'
          ? 'Confirm that the exact partial-refund amount was successfully issued. The refunded balance will be increased.'
          : 'Confirm that Stripe did not issue this refund. Any later retry must be safe from duplication.',
        resolution === 'refunded'
          ? 'Confirmez que le montant exact du remboursement partiel a bien été émis. Le solde remboursé sera augmenté.'
          : 'Confirmez que Stripe n’a pas émis ce remboursement. Toute nouvelle tentative doit éviter un doublon.'
      ))) return;
      this.runAdminOrderAction(order, action, resolution, reason);
      return;
    }
    handleAdminRefundInteraction(
      order,
      action,
      resolution,
      (english, french) => this.copy(english, french),
      (selectedAction, selectedResolution, reason) => this.runAdminOrderAction(order, selectedAction, selectedResolution, reason),
      (english, french) => { this.adminError = this.copy(english, french); },
      this.adminLoading
    );
  }

export function runAdminOrderAction(this: AdminOrdersActionsContext, order: AdminOrder, action: AdminOrderActionName, resolution?: RefundResolution, reason?: string): void {
    this.adminLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.adminError = '';
    this.adminSuccess = '';
    this.commerce.cancelAdminOrder(order.number, action, resolution, reason).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
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
        this.adminError = this.apiError(error, this.copy('We could not update this refund. Review the order and Stripe status before retrying.', 'Impossible de mettre à jour ce remboursement. Vérifiez la commande et son état Stripe avant de réessayer.'));
      }
    });
  }
