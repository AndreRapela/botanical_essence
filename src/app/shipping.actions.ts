import { HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize } from 'rxjs';

import type { ShippingWorkflowContext } from './checkout/checkout-workflow.context';
import { shippingQuoteKey } from './checkout/checkout-policy';
import { ShippingQuote } from './storefront.models';



export function changeQuantity(this: ShippingWorkflowContext, id: number, amount: number): void {
    this.store.changeQuantity(id, amount);
    this.invalidateShippingQuote();
  }

export function invalidateShippingQuote(this: ShippingWorkflowContext): void {
    if (!this.checkoutPending) this.clearCheckoutIdempotencyKey();
    this.shippingQuoteRequestKey = '';
    this.shippingQuoteLoading = false;
    this.shippingQuoteResponse = null;
    this.shippingQuotes = [];
    this.selectedShippingQuote = null;
    this.shippingQuoteError = '';
    this.checkoutError = '';
    this.checkoutForm.delivery = '';
    this.paymentPreviewAttempted = false;
  }

export function checkoutDetailsChanged(this: ShippingWorkflowContext): void {
    if (!this.checkoutPending) this.clearCheckoutIdempotencyKey();
    this.checkoutError = '';
    this.paymentPreviewAttempted = false;
  }

export function calculateShipping(this: ShippingWorkflowContext): void {
    if (this.shippingQuoteLoading || !this.cart.length) return;
    if (!this.checkoutForm.country || !this.checkoutForm.postalCode.trim()) {
      this.shippingQuoteError = this.copy(
        'Choose the country and enter the postal code to calculate delivery.',
        'Choisissez le pays et saisissez le code postal pour calculer la livraison.'
      );
      return;
    }

    const requestKey = this.currentShippingQuoteKey();
    const revision = this.checkoutStateRevision;
    this.shippingQuoteRequestKey = requestKey;
    this.shippingQuoteLoading = true;
    this.shippingQuoteError = '';
    this.commerce.shippingQuotes({
      country: this.checkoutForm.country,
      postalCode: this.checkoutForm.postalCode,
      items: this.cart.map((line) => ({ productId: line.id, quantity: line.quantity }))
    }).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: (response) => {
        if (revision !== this.checkoutStateRevision || this.shippingQuoteRequestKey !== requestKey || this.currentShippingQuoteKey() !== requestKey) return;
        this.shippingQuoteLoading = false;
        this.shippingQuoteResponse = response;
        this.shippingQuotes = response.quotes;
        const previous = response.quotes.find((quote) => quote.id === this.checkoutForm.delivery);
        const preferred = response.quotes.find((quote) => quote.id === 'bpost_home') ?? response.quotes[0] ?? null;
        this.selectShippingQuote(previous ?? preferred);
      },
      error: (error: HttpErrorResponse) => {
        if (revision !== this.checkoutStateRevision || this.shippingQuoteRequestKey !== requestKey) return;
        this.shippingQuoteLoading = false;
        this.shippingQuoteResponse = null;
        this.shippingQuotes = [];
        this.selectedShippingQuote = null;
        this.checkoutForm.delivery = '';
        this.shippingQuoteError = this.apiError(error, this.copy(
          'We could not calculate a shipping estimate for this address.',
          'Impossible de calculer une estimation de livraison pour cette adresse.'
        ));
      }
    });
  }

export function selectShippingQuote(this: ShippingWorkflowContext, quote: ShippingQuote | null): void {
    this.selectedShippingQuote = quote;
    this.checkoutForm.delivery = quote?.id ?? '';
    this.paymentPreviewAttempted = false;
  }

export function currentShippingQuoteKey(this: ShippingWorkflowContext): string {
    return shippingQuoteKey(this.checkoutForm.country, this.checkoutForm.postalCode, this.cart);
  }
