import { Injectable } from '@angular/core';

import type { CheckoutPaymentMethod, CheckoutPreviewData } from './checkout-policy';
import type { OrderSummary, ShippingQuote, ShippingQuoteResponse } from '../storefront.models';

@Injectable({ providedIn: 'root' })
export class CheckoutWorkflowState {
  checkoutError = '';
  checkoutForm: CheckoutPreviewData = {
    name: '', email: '', address: '', city: '', postalCode: '', region: '', country: 'BE', delivery: ''
  };
  shippingQuoteLoading = false;
  shippingQuoteError = '';
  shippingQuoteResponse: ShippingQuoteResponse | null = null;
  shippingQuotes: ShippingQuote[] = [];
  selectedShippingQuote: ShippingQuote | null = null;
  paymentPreviewMethod: CheckoutPaymentMethod = 'bancontact';
  paymentPreviewAttempted = false;
  paymentStarting = false;
  checkoutCompleted = false;
  checkoutOrderNumber = '';
  checkoutIsSimulation = false;
  paymentReturnSessionId = '';
  paymentVerificationLoading = false;
  checkoutOrderStatusLoading = false;
  checkoutPending = false;
  checkoutResumeUrl: string | null = null;
  checkoutCanRetry = false;
  checkoutCanCancel = false;
  pendingCheckoutOrderNumber = '';
  pendingCheckoutOrder: OrderSummary | null = null;
  checkoutIdempotencyKey = '';
  checkoutStateRevision = 0;
  shippingQuoteRequestKey = '';

  resetForAnonymousUser(): void {
    this.checkoutStateRevision += 1;
    this.checkoutError = '';
    this.checkoutForm = { name: '', email: '', address: '', city: '', postalCode: '', region: '', country: 'BE', delivery: '' };
    this.shippingQuoteLoading = false;
    this.shippingQuoteError = '';
    this.shippingQuoteResponse = null;
    this.shippingQuotes = [];
    this.selectedShippingQuote = null;
    this.shippingQuoteRequestKey = '';
    this.paymentPreviewMethod = 'bancontact';
    this.paymentPreviewAttempted = false;
    this.paymentStarting = false;
    this.checkoutCompleted = false;
    this.checkoutOrderNumber = '';
    this.checkoutIsSimulation = false;
    this.paymentReturnSessionId = '';
    this.paymentVerificationLoading = false;
    this.checkoutOrderStatusLoading = false;
    this.checkoutPending = false;
    this.checkoutResumeUrl = null;
    this.checkoutCanRetry = false;
    this.checkoutCanCancel = false;
    this.pendingCheckoutOrderNumber = '';
    this.pendingCheckoutOrder = null;
    // Keep user-scoped recovery keys in session storage for that account's next sign-in.
    this.checkoutIdempotencyKey = '';
  }
}
