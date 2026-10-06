import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import type { HttpErrorResponse } from '@angular/common/http';

import type { CommerceService } from '../core/commerce.service';
import type { StoreService } from '../core/store.service';
import type { CheckoutPreviewData } from './checkout-policy';
import type { CartLine, Currency, ShippingQuote, ShippingQuoteResponse } from '../storefront.models';

export interface ShippingWorkflowContext {
  checkoutForm: CheckoutPreviewData;
  cart: CartLine[];
  store: Pick<StoreService, 'changeQuantity' | 'clearCart'>;
  commerce: Pick<CommerceService, 'shippingQuotes'>;
  destroyRef: DestroyRef;
  cdr: ChangeDetectorRef;
  checkoutPending: boolean;
  checkoutError: string;
  checkoutStateRevision: number;
  paymentPreviewAttempted: boolean;
  selectedShippingQuote: ShippingQuote | null;
  shippingQuoteRequestKey: string;
  shippingQuoteResponse: ShippingQuoteResponse | null;
  shippingQuotes: ShippingQuote[];
  shippingQuoteLoading: boolean;
  shippingQuoteError: string;

  apiError(error: HttpErrorResponse, fallback: string): string;
  copy(english: string, french: string): string;
  clearCheckoutIdempotencyKey(): void;
  currentShippingQuoteKey(): string;
  invalidateShippingQuote(): void;
  selectShippingQuote(quote: ShippingQuote | null): void;
  resetCheckoutForAnonymousUser(): void;
}

export interface CheckoutWorkflowEffects {
  currency: Currency;
  apiError(error: HttpErrorResponse, fallback: string): string;
  copy(english: string, french: string): string;
  calculateShipping(): void;
  handleUnauthorized(error: HttpErrorResponse): void;
  loadOrders(): void;
  showToast(message: string): void;
  markForCheck(): void;
}
