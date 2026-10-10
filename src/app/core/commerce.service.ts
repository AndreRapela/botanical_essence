import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';

import { AccountProfile, AdminAuditEntry, AdminContactMessage, AdminOrder, AdminOverviewPage, AdminPaymentDispute, AdminPaymentNotification, AdminProduct, AdminReview, AdminSeller, AdminUser, AdminWithdrawalRequest, AuthUser, ContactMessageInput, ManagedProduct, MarketplaceStore, OrderSummary, ProductReviewResponse, ShippingQuoteResponse } from '../storefront.models';
import { AuthService } from './auth.service';
import type { ExternalRefundIssue } from '../storefront.models';

@Injectable({ providedIn: 'root' })
export class CommerceService {
  constructor(private readonly http: HttpClient, private readonly auth: AuthService) {}

  externalRefundIssues(page = 1): Observable<{ data: ExternalRefundIssue[]; page: number; limit: number; total: number; hasMore: boolean }> {
    return this.http.get<{ data: ExternalRefundIssue[]; page: number; limit: number; total: number; hasMore: boolean }>('/api/admin/refund-reconciliation', { params: { page, limit: 50 } });
  }

  resolveExternalRefund(refundId: string, action: 'link' | 'record' | 'dismiss' | 'reverse', reason: string, attempt?: number): Observable<{ message: string }> {
    return this.auth.postWithCsrf<{ message: string }>('/api/admin/refund-reconciliation/resolve', { refundId, action, reason, ...(attempt === undefined ? {} : { attempt }) });
  }

  createOrder(input: Record<string, unknown>): Observable<{ order: OrderSummary; checkoutUrl: string | null; simulated?: boolean; alreadyPaid?: boolean }> {
    return this.auth.postWithCsrf<{ order: OrderSummary; checkoutUrl: string | null; simulated?: boolean; alreadyPaid?: boolean }>('/api/orders', input);
  }

  paymentSessionStatus(sessionId: string): Observable<{ order: OrderSummary; paymentStatus: string }> {
    return this.auth.postWithCsrf<{ order: OrderSummary; paymentStatus: string }>('/api/payments/session-status', { sessionId });
  }

  checkoutOrderStatus(orderNumber: string): Observable<{
    order: OrderSummary;
    paymentStatus: string;
    checkoutUrl: string | null;
    canResume: boolean;
    canRetry: boolean;
    canCancel: boolean;
    providerStatus: string | null;
  }> {
    return this.auth.postWithCsrf<{
      order: OrderSummary;
      paymentStatus: string;
      checkoutUrl: string | null;
      canResume: boolean;
      canRetry: boolean;
      canCancel: boolean;
      providerStatus: string | null;
    }>('/api/payments/order-status', { orderNumber });
  }

  resumeCheckoutOrder(orderNumber: string): Observable<{ order: OrderSummary; checkoutUrl: string | null; alreadyPaid?: boolean }> {
    return this.auth.postWithCsrf<{ order: OrderSummary; checkoutUrl: string | null; alreadyPaid?: boolean }>(
      '/api/payments/resume-checkout',
      { orderNumber }
    );
  }

  cancelCheckoutOrder(orderNumber: string): Observable<{ order: OrderSummary; cancelled: boolean; alreadyPaid: boolean }> {
    return this.auth.postWithCsrf<{ order: OrderSummary; cancelled: boolean; alreadyPaid: boolean }>('/api/payments/cancel-checkout', { orderNumber });
  }

  shippingQuotes(input: { country: string; postalCode: string; items: { productId: number; quantity: number }[] }): Observable<ShippingQuoteResponse> {
    return this.auth.postWithCsrf<ShippingQuoteResponse>('/api/shipping/quotes', input);
  }

  orders(page = 1, limit = 50): Observable<{ data: OrderSummary[]; page: number; limit: number; total: number; hasMore: boolean }> {
    return this.http.get<{ data: OrderSummary[]; page: number; limit: number; total: number; hasMore: boolean }>('/api/orders', { params: { page, limit } });
  }

  submitWithdrawal(input: { orderNumber: string; type: 'withdrawal' | 'problem'; language: 'en' | 'fr'; message?: string; deliveryReceivedDate?: string }): Observable<{
    request: { id: number; requestNumber: string; requestType: 'withdrawal' | 'problem'; status: string; createdAt: string } | null;
    alreadySubmitted: boolean;
    emailAcknowledged?: boolean;
  }> {
    return this.auth.postWithCsrf('/api/orders/withdrawal', input);
  }

  productReviews(productId: number, page = 1, limit = 20): Observable<ProductReviewResponse> {
    return this.http.get<ProductReviewResponse>('/api/product-reviews', { params: { productId, page, limit } });
  }

  saveProductReview(input: { productId: number; rating: number; comment: string }): Observable<{ message: string; reviewId: number; status: 'visible' | 'hidden' }> {
    return this.auth.postWithCsrf<{ message: string; reviewId: number; status: 'visible' | 'hidden' }>('/api/product-reviews', input);
  }

  subscribe(email: string, language: string): Observable<{ message: string }> {
    return this.auth.postWithCsrf<{ message: string }>('/api/newsletter', { email, language });
  }

  contact(input: ContactMessageInput): Observable<{ message: string }> {
    return this.auth.postWithCsrf<{ message: string }>('/api/contact', input);
  }

  profile(): Observable<{ profile: AccountProfile }> {
    return this.http.get<{ profile: AccountProfile }>('/api/account/profile');
  }

  updateProfile(input: Record<string, unknown>): Observable<{ message: string; profile: AccountProfile; user: AuthUser }> {
    return this.auth.postWithCsrf<{ message: string; profile: AccountProfile; user: AuthUser }>('/api/account/profile', input);
  }

  sellerDashboard(): Observable<{ store: MarketplaceStore | null; products: ManagedProduct[] }> {
    return this.http.get<{ store: MarketplaceStore | null; products: ManagedProduct[] }>('/api/seller/dashboard');
  }

  updateSellerStore(input: Record<string, unknown>): Observable<{ message: string; store: MarketplaceStore }> {
    return this.auth.postWithCsrf<{ message: string; store: MarketplaceStore }>('/api/seller/store', input);
  }

  saveSellerProduct(input: Record<string, unknown>): Observable<{ message: string; product: ManagedProduct }> {
    return this.auth.postWithCsrf<{ message: string; product: ManagedProduct }>('/api/seller/products', input);
  }

  archiveSellerProduct(id: number): Observable<{ message: string }> {
    return this.auth.postWithCsrf<{ message: string }>('/api/seller/products/archive', { id });
  }

  adminSellers(page = 1, limit = 50): Observable<{ data: AdminSeller[]; page: number; limit: number; total: number; hasMore: boolean }> {
    return this.http.get<{ data: AdminSeller[]; page: number; limit: number; total: number; hasMore: boolean }>('/api/admin/sellers', { params: { page, limit } });
  }

  setSellerVerification(sellerId: number, verified: boolean): Observable<{ message: string }> {
    return this.auth.postWithCsrf<{ message: string }>('/api/admin/sellers/verification', { sellerId, verified });
  }

  adminOverview(page = 1, limit = 50): Observable<AdminOverviewPage> {
    return this.http.get<AdminOverviewPage>('/api/admin/overview', { params: { page, limit } });
  }

  adminAuditLog(page = 1, limit = 50): Observable<{ data: AdminAuditEntry[]; page: number; limit: number; total: number; hasMore: boolean }> {
    return this.http.get<{ data: AdminAuditEntry[]; page: number; limit: number; total: number; hasMore: boolean }>('/api/admin/audit-log', { params: { page, limit } });
  }

  adminContactMessages(page = 1, limit = 50): Observable<{ data: AdminContactMessage[]; page: number; limit: number; total: number; hasMore: boolean }> {
    return this.http.get<{ data: AdminContactMessage[]; page: number; limit: number; total: number; hasMore: boolean }>('/api/admin/contact-messages', { params: { page, limit } });
  }

  updateAdminContactMessage(messageId: number, status: AdminContactMessage['status']): Observable<{ message: string }> {
    return this.auth.postWithCsrf<{ message: string }>('/api/admin/contact-messages/status', { messageId, status });
  }

  adminOrders(page = 1, limit = 50): Observable<{ data: AdminOrder[]; page: number; limit: number; total: number; hasMore: boolean }> {
    return this.http.get<{ data: AdminOrder[]; page: number; limit: number; total: number; hasMore: boolean }>('/api/admin/orders', { params: { page, limit } });
  }

  adminPaymentDisputes(page = 1, limit = 50): Observable<{ data: AdminPaymentDispute[]; page: number; limit: number; total: number; hasMore: boolean }> {
    return this.http.get<{ data: AdminPaymentDispute[]; page: number; limit: number; total: number; hasMore: boolean }>('/api/admin/payment-disputes', { params: { page, limit } });
  }

  adminPaymentNotifications(page = 1, limit = 50): Observable<{ data: AdminPaymentNotification[]; page: number; limit: number; total: number; hasMore: boolean }> {
    return this.http.get<{ data: AdminPaymentNotification[]; page: number; limit: number; total: number; hasMore: boolean }>('/api/admin/payment-notifications', { params: { page, limit } });
  }

  retryAdminPaymentNotification(id: number, reason: string): Observable<{ message: string }> {
    return this.auth.postWithCsrf<{ message: string }>('/api/admin/payment-notifications/retry', { id, reason });
  }

  partialRefund(orderNumber: string, amountCents: number, reason: string): Observable<{ message: string }> {
    return this.auth.postWithCsrf<{ message: string }>('/api/admin/orders/refund', { orderNumber, amountCents, reason });
  }

  adminWithdrawals(page = 1, limit = 50): Observable<{ data: AdminWithdrawalRequest[]; page: number; limit: number; total: number; hasMore: boolean }> {
    return this.http.get<{ data: AdminWithdrawalRequest[]; page: number; limit: number; total: number; hasMore: boolean }>('/api/admin/withdrawals', { params: { page, limit } });
  }

  adminWithdrawalAction(input: { requestId: number; action: string; note?: string }): Observable<{ message: string; code?: string }> {
    return this.auth.postWithCsrf('/api/admin/withdrawals/action', input);
  }

  cancelAdminOrder(
    orderNumber: string,
    action: 'cancel' | 'check_refund' | 'retry_refund' | 'resolve_refund' | 'resolve_checkout_attempt' = 'cancel',
    resolution?: 'refunded' | 'not_refunded',
    reason?: string
  ): Observable<{ message: string; code?: string }> {
    return this.auth.postWithCsrf<{ message: string; code?: string }>('/api/admin/orders/cancel', {
      orderNumber,
      action,
      ...(resolution ? { resolution } : {}),
      ...(reason ? { reason } : {})
    });
  }

  updateAdminUser(input: Record<string, unknown>): Observable<{ message: string; user: AdminUser }> {
    return this.auth.postWithCsrf<{ message: string; user: AdminUser }>('/api/admin/users', input);
  }

  updateAdminProduct(input: Record<string, unknown>): Observable<{ message: string; product: AdminProduct }> {
    return this.auth.postWithCsrf<{ message: string; product: AdminProduct }>('/api/admin/products', input);
  }

  updateAdminStore(input: Record<string, unknown>): Observable<{ message: string }> {
    return this.auth.postWithCsrf<{ message: string }>('/api/admin/stores', input);
  }

  moderateReview(reviewId: number, status: 'visible' | 'hidden'): Observable<{ message: string }> {
    return this.auth.postWithCsrf<{ message: string }>('/api/admin/reviews/moderate', { reviewId, status });
  }
}
