import { HttpErrorResponse } from '@angular/common/http';
import { DestroyRef, Injectable, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize } from 'rxjs';

import { AuthService } from '../core/auth.service';
import { CatalogService } from '../core/catalog.service';
import { CommerceService } from '../core/commerce.service';
import { StoreService } from '../core/store.service';
import type { Currency, OrderSummary } from '../storefront.models';
import { checkoutPaymentMethodForCurrency, checkoutPreviewIssue, shippingQuoteKey } from './checkout-policy';
import type { CheckoutWorkflowEffects } from './checkout-workflow.context';
import { CheckoutWorkflowState } from './checkout-workflow.state';

@Injectable({ providedIn: 'root' })
export class CheckoutWorkflowService {
  private readonly auth = inject(AuthService);
  private readonly catalog = inject(CatalogService);
  private readonly commerce = inject(CommerceService);
  private readonly store = inject(StoreService);
  private readonly state = inject(CheckoutWorkflowState);
  private readonly destroyRef = inject(DestroyRef);

  startPurchase(effects: CheckoutWorkflowEffects): void {
    const state = this.state;
    if (state.paymentStarting || state.paymentVerificationLoading) return;
    if (state.checkoutPending) {
      this.refreshCheckoutOrderStatus(effects);
      return;
    }
    const user = this.auth.user();
    if (!user) {
      state.checkoutError = effects.copy('Sign in and confirm your email before placing an order.', 'Connectez-vous et confirmez votre e-mail avant de passer commande.');
      return;
    }
    if (!user.emailVerifiedAt) {
      state.checkoutError = effects.copy('Confirm your account email before placing an order.', 'Confirmez l’e-mail de votre compte avant de passer commande.');
      return;
    }
    const issue = checkoutPreviewIssue(state.checkoutForm);
    if (issue === 'contact') {
      state.checkoutError = effects.copy('Enter a valid full name and email address before continuing.', 'Saisissez un nom complet et une adresse e-mail valides avant de continuer.');
      return;
    }
    if (issue === 'address') {
      state.checkoutError = effects.copy('Complete the street, city, postal code and country before continuing.', 'Complétez la rue, la ville, le code postal et le pays avant de continuer.');
      return;
    }
    if (issue === 'delivery' || !state.selectedShippingQuote) {
      effects.calculateShipping();
      state.checkoutError = effects.copy('Calculate delivery before continuing to payment.', 'Calculez la livraison avant de continuer vers le paiement.');
      return;
    }
    if (state.shippingQuoteRequestKey !== this.currentShippingQuoteKey()) {
      effects.calculateShipping();
      state.checkoutError = effects.copy('Recalculate delivery for the current address and cart before continuing.', 'Recalculez la livraison pour l’adresse et le panier actuels avant de continuer.');
      return;
    }
    const exchangeRate = this.catalog.currencyRateMetadata();
    if (!exchangeRate?.currencyRateToken || exchangeRate.currencyRateStatus === 'unavailable') {
      state.checkoutError = effects.copy('Checkout is paused because the recent ECB exchange reference is unavailable. Please try again later.', 'Le paiement est suspendu car le taux de référence récent de la BCE est indisponible. Veuillez réessayer plus tard.');
      return;
    }
    const tariffVersion = state.shippingQuoteResponse?.tariffVersion;
    if (!tariffVersion) {
      effects.calculateShipping();
      state.checkoutError = effects.copy('Recalculate the shipping estimate before continuing.', 'Recalculez l’estimation de livraison avant de continuer.');
      return;
    }
    if (checkoutPaymentMethodForCurrency(effects.currency, state.paymentPreviewMethod) !== state.paymentPreviewMethod) {
      state.checkoutError = effects.copy('Bancontact and SEPA Direct Debit are charged in euros. Switch the currency to EUR or choose card.', 'Bancontact et le débit direct SEPA sont débités en euros. Passez en EUR ou choisissez la carte.');
      return;
    }

    state.checkoutError = '';
    state.paymentStarting = true;
    const revision = state.checkoutStateRevision;
    state.checkoutIdempotencyKey ||= this.readCheckoutStorage('key') || crypto.randomUUID();
    this.writeCheckoutStorage('key', state.checkoutIdempotencyKey);
    this.commerce.createOrder({
      idempotencyKey: state.checkoutIdempotencyKey,
      name: state.checkoutForm.name,
      email: state.checkoutForm.email,
      address: state.checkoutForm.address,
      city: state.checkoutForm.city,
      postalCode: state.checkoutForm.postalCode,
      region: state.checkoutForm.region,
      country: state.checkoutForm.country,
      delivery: state.selectedShippingQuote.id,
      payment: state.paymentPreviewMethod,
      currency: effects.currency,
      exchangeRateToken: exchangeRate.currencyRateToken,
      shippingTariffVersion: tariffVersion,
      items: this.store.cart().map((line) => ({ productId: line.id, quantity: line.quantity }))
    }).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => {
      if (this.isCurrent(revision)) state.paymentStarting = false;
      effects.markForCheck();
    })).subscribe({
      next: ({ order, checkoutUrl, simulated, alreadyPaid }) => {
        if (!this.isCurrent(revision)) return;
        state.paymentStarting = false;
        if (checkoutUrl) {
          state.pendingCheckoutOrder = order;
          this.rememberPendingCheckout(order.number);
          state.checkoutResumeUrl = checkoutUrl;
          state.checkoutCanRetry = false;
          state.checkoutCanCancel = true;
          window.location.assign(checkoutUrl);
          return;
        }
        this.finishCheckout(order, Boolean(simulated || order.testPayment), Boolean(alreadyPaid), effects);
      },
      error: (error: HttpErrorResponse) => {
        if (!this.isCurrent(revision)) return;
        state.paymentStarting = false;
        const apiCode = typeof error.error?.code === 'string' ? error.error.code : '';
        if (['checkout_in_progress', 'payment_operation_in_progress', 'idempotency_conflict', 'order_cancelled', 'payment_session_unverified', 'stripe_checkout_rejected', 'checkout_preparation_failed'].includes(apiCode)) {
          const orderNumber = error.error?.details?.orderNumber;
          if (typeof orderNumber === 'string') {
            this.rememberPendingCheckout(orderNumber);
            this.loadCheckoutOrderStatus(orderNumber, effects);
          }
        } else if (error.status >= 400 && error.status < 500 && error.status !== 401 && error.status !== 429
          && !['payment_confirmation_pending', 'payment_operation_in_progress', 'payment_session_unverified', 'idempotency_conflict', 'order_cancelled'].includes(apiCode)) {
          this.clearCheckoutIdempotencyKey();
        }
        effects.handleUnauthorized(error);
        if (error.status === 401) return;
        state.checkoutError = effects.apiError(error, effects.copy('We could not start your secure payment. Please try again.', 'Impossible de démarrer le paiement sécurisé. Veuillez réessayer.'));
      }
    });
  }

  verifyCheckoutReturn(effects: CheckoutWorkflowEffects): void {
    const state = this.state;
    const sessionId = state.paymentReturnSessionId;
    if (!this.auth.user() || !sessionId || state.paymentVerificationLoading) return;
    state.paymentVerificationLoading = true;
    const revision = state.checkoutStateRevision;
    this.commerce.paymentSessionStatus(sessionId).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => {
      if (this.isCurrent(revision)) state.paymentVerificationLoading = false;
      effects.markForCheck();
    })).subscribe({
      next: ({ order, paymentStatus }) => {
        if (!this.isCurrent(revision)) return;
        if (paymentStatus === 'paid' && order.status !== 'cancelled') {
          this.finishCheckout(order, Boolean(order.testPayment), false, effects);
          return;
        }
        state.pendingCheckoutOrder = order;
        this.rememberPendingCheckout(order.number);
        state.checkoutError = effects.copy('The provider is still confirming this payment. This order is locked against duplicate checkout attempts.', 'Le prestataire confirme encore ce paiement. Cette commande est protégée contre les tentatives de paiement en double.');
        this.loadCheckoutOrderStatus(order.number, effects);
      },
      error: (error: HttpErrorResponse) => {
        if (!this.isCurrent(revision)) return;
        effects.handleUnauthorized(error);
        if (error.status === 401) return;
        state.checkoutPending = true;
        state.checkoutCanCancel = false;
        state.checkoutResumeUrl = null;
        state.checkoutError = effects.apiError(error, effects.copy('We could not verify the payment yet. Check your order history before trying again.', 'Impossible de vérifier le paiement pour le moment. Consultez votre historique avant de réessayer.'));
      }
    });
  }

  loadCheckoutOrderStatus(orderNumber: string, effects: CheckoutWorkflowEffects): void {
    const state = this.state;
    if (!this.auth.user() || !orderNumber || state.checkoutOrderStatusLoading) return;
    this.rememberPendingCheckout(orderNumber);
    state.checkoutOrderStatusLoading = true;
    const revision = state.checkoutStateRevision;
    this.commerce.checkoutOrderStatus(orderNumber).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => {
      if (this.isCurrent(revision)) state.checkoutOrderStatusLoading = false;
      effects.markForCheck();
    })).subscribe({
      next: ({ order, paymentStatus, checkoutUrl, canResume, canRetry, canCancel, providerStatus }) => {
        if (!this.isCurrent(revision)) return;
        if (paymentStatus === 'paid' && order.status !== 'cancelled') {
          this.finishCheckout(order, Boolean(order.testPayment), false, effects);
          return;
        }
        if (paymentStatus === 'failed' || paymentStatus === 'refunded') {
          this.clearCheckoutIdempotencyKey();
          this.clearPendingCheckout();
          state.checkoutError = effects.copy('This payment attempt is closed. Your cart is still available if you want to try again.', 'Cette tentative de paiement est terminée. Votre panier reste disponible si vous souhaitez réessayer.');
          return;
        }
        state.pendingCheckoutOrder = order;
        this.rememberPendingCheckout(order.number);
        if (order.status === 'cancelled') {
          state.checkoutResumeUrl = null;
          state.checkoutError = effects.copy('This order is cancelled, but its payment or refund still needs to be reconciled. A new purchase stays blocked to avoid a second charge.', 'Cette commande est annulée, mais le paiement ou le remboursement doit encore être rapproché. Un nouvel achat reste bloqué pour éviter un second débit.');
          return;
        }
        state.checkoutResumeUrl = canResume ? checkoutUrl : null;
        state.checkoutCanRetry = canRetry;
        state.checkoutCanCancel = canCancel;
        state.checkoutError = canResume
          ? effects.copy('Your secure payment is still open. Resume it or cancel this order before starting a different purchase.', 'Votre paiement sécurisé est toujours ouvert. Reprenez-le ou annulez cette commande avant d’en commencer une autre.')
          : canRetry
            ? effects.copy('We could not confirm whether Stripe opened checkout. Retry this same order to recover the idempotent session; no duplicate order will be created.', 'Impossible de confirmer si Stripe a ouvert le paiement. Reprenez cette même commande pour récupérer la session idempotente ; aucun doublon ne sera créé.')
            : providerStatus === 'manual_reconciliation_required'
              ? effects.copy('This payment attempt needs staff reconciliation with Stripe. Your stock remains reserved; do not create another order or try to cancel it.', 'Cette tentative doit être vérifiée par le service client auprès de Stripe. Votre stock reste réservé ; ne créez pas une autre commande et ne tentez pas de l’annuler.')
              : effects.copy('Payment confirmation is still pending. We will not create another order while this one is unresolved.', 'La confirmation du paiement est en attente. Nous ne créerons pas une autre commande tant que celle-ci ne sera pas résolue.');
      },
      error: (error: HttpErrorResponse) => {
        if (!this.isCurrent(revision)) return;
        effects.handleUnauthorized(error);
        if (error.status === 401) return;
        state.checkoutResumeUrl = null;
        state.checkoutCanCancel = false;
        state.checkoutPending = true;
        state.checkoutError = effects.apiError(error, effects.copy('We could not verify this order. A new checkout is blocked until its payment status can be confirmed.', 'Impossible de vérifier cette commande. Un nouveau paiement est bloqué jusqu’à la confirmation de son état.'));
      }
    });
  }

  refreshCheckoutOrderStatus(effects: CheckoutWorkflowEffects): void {
    if (this.state.pendingCheckoutOrderNumber) this.loadCheckoutOrderStatus(this.state.pendingCheckoutOrderNumber, effects);
    else this.verifyCheckoutReturn(effects);
  }

  resumePendingCheckout(effects: CheckoutWorkflowEffects): void {
    const state = this.state;
    if (state.checkoutResumeUrl) {
      window.location.assign(state.checkoutResumeUrl);
      return;
    }
    if (state.checkoutCanRetry && this.auth.user() && state.pendingCheckoutOrderNumber) {
      const orderNumber = state.pendingCheckoutOrderNumber;
      const revision = state.checkoutStateRevision;
      state.checkoutOrderStatusLoading = true;
      this.commerce.resumeCheckoutOrder(orderNumber).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => {
        if (this.isCurrent(revision)) state.checkoutOrderStatusLoading = false;
        effects.markForCheck();
      })).subscribe({
        next: ({ order, checkoutUrl, alreadyPaid }) => {
          if (!this.isCurrent(revision)) return;
          if (checkoutUrl) {
            state.pendingCheckoutOrder = order;
            state.checkoutResumeUrl = checkoutUrl;
            state.checkoutCanRetry = false;
            state.checkoutCanCancel = true;
            window.location.assign(checkoutUrl);
          } else if (alreadyPaid || order.paymentStatus === 'paid') {
            this.finishCheckout(order, Boolean(order.testPayment), Boolean(alreadyPaid), effects);
          } else {
            state.checkoutOrderStatusLoading = false;
            this.loadCheckoutOrderStatus(order.number, effects);
          }
        },
        error: (error: HttpErrorResponse) => {
          if (!this.isCurrent(revision)) return;
          effects.handleUnauthorized(error);
          if (error.status === 401) return;
          state.checkoutError = effects.apiError(error, effects.copy('We could not safely resume this payment. The order remains protected; retry the same order or check its status.', 'Impossible de reprendre ce paiement en toute sécurité. La commande reste protégée ; réessayez la même commande ou vérifiez son état.'));
          const apiCode = typeof error.error?.code === 'string' ? error.error.code : '';
          if (['stripe_checkout_rejected', 'checkout_preparation_failed', 'payment_session_expired'].includes(apiCode)) {
            state.checkoutOrderStatusLoading = false;
            this.loadCheckoutOrderStatus(orderNumber, effects);
          }
        }
      });
      return;
    }
    this.refreshCheckoutOrderStatus(effects);
  }

  cancelPendingCheckout(effects: CheckoutWorkflowEffects): void {
    const state = this.state;
    if (!this.auth.user() || !state.checkoutCanCancel || !state.pendingCheckoutOrderNumber || state.checkoutOrderStatusLoading) return;
    const orderNumber = state.pendingCheckoutOrderNumber;
    const revision = state.checkoutStateRevision;
    let reconcileStatusAfterCancellation = false;
    state.checkoutOrderStatusLoading = true;
    this.commerce.cancelCheckoutOrder(orderNumber).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => {
      if (this.isCurrent(revision)) state.checkoutOrderStatusLoading = false;
      effects.markForCheck();
      if (this.isCurrent(revision) && reconcileStatusAfterCancellation && this.auth.user()) this.loadCheckoutOrderStatus(orderNumber, effects);
    })).subscribe({
      next: ({ order, cancelled, alreadyPaid }) => {
        if (!this.isCurrent(revision)) return;
        if ((alreadyPaid || order.paymentStatus === 'paid') && order.status !== 'cancelled') {
          this.finishCheckout(order, Boolean(order.testPayment), Boolean(alreadyPaid), effects);
          return;
        }
        if (cancelled && ['failed', 'refunded'].includes(order.paymentStatus)) {
          this.clearCheckoutIdempotencyKey();
          this.clearPendingCheckout();
          state.paymentReturnSessionId = '';
          state.checkoutError = effects.copy('The unpaid order was safely cancelled. Your cart is ready for another attempt.', 'La commande non payée a été annulée en toute sécurité. Votre panier est prêt pour une nouvelle tentative.');
          effects.loadOrders();
          return;
        }
        state.pendingCheckoutOrder = order;
        this.rememberPendingCheckout(order.number);
        state.checkoutResumeUrl = null;
        state.checkoutCanRetry = false;
        state.checkoutCanCancel = false;
        state.checkoutError = effects.copy('We could not confirm that this order was cancelled. Its payment state is being checked again; a new order stays blocked.', 'Nous n’avons pas pu confirmer l’annulation de cette commande. Son paiement est revérifié ; une nouvelle commande reste bloquée.');
        reconcileStatusAfterCancellation = true;
      },
      error: (error: HttpErrorResponse) => {
        if (!this.isCurrent(revision)) return;
        effects.handleUnauthorized(error);
        if (error.status === 401) return;
        state.checkoutResumeUrl = null;
        state.checkoutCanRetry = false;
        state.checkoutCanCancel = false;
        state.checkoutPending = true;
        state.checkoutError = effects.apiError(error, effects.copy('We could not confirm the cancellation. Payment status is being checked again; a new order stays blocked.', 'Nous n’avons pas pu confirmer l’annulation. Le paiement est revérifié ; une nouvelle commande reste bloquée.'));
        reconcileStatusAfterCancellation = true;
      }
    });
  }

  finishCheckout(order: OrderSummary, simulated: boolean, alreadyPaid: boolean, effects: CheckoutWorkflowEffects): void {
    const state = this.state;
    this.clearCheckoutIdempotencyKey();
    this.clearPendingCheckout();
    state.checkoutCompleted = true;
    state.checkoutOrderNumber = order.number;
    state.checkoutIsSimulation = simulated;
    this.store.clearCart();
    state.paymentReturnSessionId = '';
    this.invalidateShippingQuote();
    effects.loadOrders();
    effects.showToast(simulated
      ? effects.copy('Local test purchase completed — no money was moved.', 'Achat de test local terminé — aucun argent n’a été transféré.')
      : alreadyPaid
        ? effects.copy('This order was already paid.', 'Cette commande était déjà payée.')
        : effects.copy('Payment confirmed. Thank you for your order.', 'Paiement confirmé. Merci pour votre commande.'));
  }

  rememberPendingCheckout(orderNumber: string): void {
    this.state.pendingCheckoutOrderNumber = orderNumber;
    this.state.checkoutPending = true;
    this.writeCheckoutStorage('order', orderNumber);
  }

  clearPendingCheckout(): void {
    const state = this.state;
    state.pendingCheckoutOrderNumber = '';
    state.checkoutPending = false;
    state.checkoutResumeUrl = null;
    state.checkoutCanRetry = false;
    state.checkoutCanCancel = false;
    state.pendingCheckoutOrder = null;
    this.writeCheckoutStorage('order', '');
  }

  clearCheckoutIdempotencyKey(): void {
    this.state.checkoutIdempotencyKey = '';
    this.writeCheckoutStorage('key', '');
  }

  readCheckoutStorage(kind: 'key' | 'order'): string {
    const user = this.auth.user();
    if (!user) return '';
    try {
      const value = window.sessionStorage.getItem(`botanical-checkout-${kind}-${user.id}`) ?? '';
      return value.length <= 64 ? value : '';
    } catch { return ''; }
  }

  writeCheckoutStorage(kind: 'key' | 'order', value: string): void {
    const user = this.auth.user();
    if (!user) return;
    const key = `botanical-checkout-${kind}-${user.id}`;
    try {
      if (value) window.sessionStorage.setItem(key, value);
      else window.sessionStorage.removeItem(key);
    } catch { /* Recovery storage is optional; the backend enforces checkout idempotency. */ }
  }

  resetCheckoutForAnonymousUser(): void { this.state.resetForAnonymousUser(); }

  private invalidateShippingQuote(): void {
    const state = this.state;
    if (!state.checkoutPending) this.clearCheckoutIdempotencyKey();
    state.shippingQuoteRequestKey = '';
    state.shippingQuoteLoading = false;
    state.shippingQuoteResponse = null;
    state.shippingQuotes = [];
    state.selectedShippingQuote = null;
    state.shippingQuoteError = '';
    state.checkoutError = '';
    state.checkoutForm.delivery = '';
    state.paymentPreviewAttempted = false;
  }

  private currentShippingQuoteKey(): string {
    const state = this.state;
    return shippingQuoteKey(state.checkoutForm.country, state.checkoutForm.postalCode, this.store.cart());
  }

  private isCurrent(revision: number): boolean { return revision === this.state.checkoutStateRevision; }
}
