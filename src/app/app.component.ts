import { CommonModule, DOCUMENT } from '@angular/common';
import type { AdminOrderAction, AdminRefundAction } from './admin-refund-interaction';
import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  DestroyRef,
  HostListener,
  inject
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { combineLatest, finalize } from 'rxjs';

import { AuthService } from './core/auth.service';
import { CatalogService } from './core/catalog.service';
import { CommerceService } from './core/commerce.service';
import { StoreService } from './core/store.service';
import { AdminContactInboxComponent } from './admin-contact-inbox.component';
import { AdminAuditLogComponent } from './admin-audit-log.component';
import { AdminDisputesComponent } from './admin-disputes.component';
import { AdminFinancialOperationsComponent } from './admin-financial-operations.component';
import { AdminCatalogManagementComponent } from './admin-catalog-management.component';
import type { AdminProductForm, AdminStoreForm, AdminUserForm } from './admin-catalog-management.component';
import { AdminPaymentNotificationsComponent } from './admin-payment-notifications.component';
import { AdminRefundReconciliationComponent } from './admin-refund-reconciliation.component';
import { AuthFormComponent } from './auth-form.component';
import { ProductCardComponent } from './product-card.component';
import { InformationPagesComponent } from './information-pages.component';
import { CheckoutDeliveryComponent } from './checkout-delivery.component';
import { CheckoutFinalReviewComponent } from './checkout-final-review.component';
import { CheckoutOrderSummaryComponent } from './checkout-order-summary.component';
import type { CheckoutOrderSummaryItem } from './checkout-order-summary.component';
import { CheckoutDetailsFormComponent } from './checkout-details-form.component';
import type { CheckoutContactChange } from './checkout-details-form.component';
import { CheckoutWorkflowState } from './checkout/checkout-workflow.state';
import { CheckoutWorkflowService } from './checkout/checkout-workflow.service';
import type { CheckoutWorkflowEffects } from './checkout/checkout-workflow.context';
import { shippingLabel as shippingLabelText } from './checkout/shipping-display';
import { CheckoutPendingComponent } from './checkout-pending.component';
import { AccountOrderHistoryComponent } from './account-order-history.component';
import { SellerStudioComponent } from './seller-studio.component';
import type { SellerProductForm, SellerStoreForm } from './seller-studio.component';
import type { ContactFormData } from './information-pages.component';
import {
  checkoutPaymentMethodForCurrency,
  CheckoutPaymentMethod,
  CheckoutPreviewData,
  DELIVERY_COUNTRIES
} from './checkout/checkout-policy';
import { STOREFRONT_TRANSLATIONS } from './i18n/storefront-translations';
import { localizedProductBadge } from './storefront-catalog.view';
import {
  AccountProfile,
  AdminAuditEntry,
  AdminOrder,
  AdminContactMessage,
  AdminPaymentDispute,
  AdminPaymentNotification,
  AdminProduct,
  AdminReview,
  AdminSeller,
  AdminUser,
  AdminWithdrawalRequest,
  AuthMode,
  AuthUser,
  CartLine,
  Category,
  Currency,
  DemoAccount,
  Language,
  ManagedProduct,
  MarketplaceStore,
  OrderSummary,
  Page,
  Product,
  ProductReview,
  ShippingQuote,
  ShippingQuoteResponse
} from './storefront.models';
import {
  cartProductCount as cartProductCountView,
  favoriteStorefrontProducts as favoriteStorefrontProductsView,
  marketplaceSellerCount as marketplaceSellerCountView,
  marketplaceStorefrontProducts as marketplaceStorefrontProductsView,
  pageStorefrontProducts as pageStorefrontProductsView,
  searchStorefrontProducts as searchStorefrontProductsView,
  visibleStorefrontProducts as visibleStorefrontProductsView
} from './storefront-catalog.view';
import {
  checkoutTotal as checkoutTotalAction,
  shippingCost as shippingCostAction,
  paymentMethodLabel as paymentMethodLabelAction
} from "./checkout.actions";
import {
  changeQuantity as changeQuantityAction,
  invalidateShippingQuote as invalidateShippingQuoteAction,
  checkoutDetailsChanged as checkoutDetailsChangedAction,
  calculateShipping as calculateShippingAction,
  selectShippingQuote as selectShippingQuoteAction,
  currentShippingQuoteKey as currentShippingQuoteKeyAction
} from "./shipping.actions";
import {
  toggleFavorite as toggleFavoriteAction,
  scrollTo as scrollToAction,
  switchAuth as switchAuthAction,
  submitLogin as submitLoginAction,
  loginAsDemo as loginAsDemoAction,
  saveProfile as saveProfileAction,
  submitRegister as submitRegisterAction,
  submitForgotPassword as submitForgotPasswordAction,
  submitResetPassword as submitResetPasswordAction,
  submitVerifyEmail as submitVerifyEmailAction,
  openAuthAction as openAuthActionAction,
  logout as logoutAction
} from "./auth.actions";
import {
  loadSellerDashboard as loadSellerDashboardAction,
  saveSellerStore as saveSellerStoreAction,
  editManagedProduct as editManagedProductAction,
  resetProductForm as resetProductFormAction,
  saveSellerProduct as saveSellerProductAction,
  archiveSellerProduct as archiveSellerProductAction
} from "./seller.actions";
import {
  loadAdminSellers as loadAdminSellersAction,
  loadOlderAdminOrders as loadOlderAdminOrdersAction,
  partialRefundAdminOrder as partialRefundAdminOrderAction,
  cancelAdminOrder as cancelAdminOrderAction,
  adminRefundAction as adminRefundActionAction,
  resolveUnattachedCheckoutAttempt as resolveUnattachedCheckoutAttemptAction,
  runAdminOrderAction as runAdminOrderActionAction
} from "./admin-orders.actions";
import { loadOlderAdminManagement as loadOlderAdminManagementAction } from './admin-management.actions';
import { loadOlderAdminSellers as loadOlderAdminSellersAction } from './admin-management.actions';
import {
  loadOlderAdminContactMessages as loadOlderAdminContactMessagesAction,
  updateAdminContactMessage as updateAdminContactMessageAction
} from "./admin-support.actions";
import { loadOlderAdminAuditLog as loadOlderAdminAuditLogAction } from './admin-audit.actions';
import { loadOlderAdminDisputes as loadOlderAdminDisputesAction, loadOlderAdminWithdrawals as loadOlderAdminWithdrawalsAction } from './admin-queues.actions';
import { loadOlderAdminPaymentNotifications as loadOlderAdminPaymentNotificationsAction, retryAdminPaymentNotification as retryAdminPaymentNotificationAction } from './admin-notifications.actions';
import {
  openWithdrawalRequest as openWithdrawalRequestAction,
  closeWithdrawalRequest as closeWithdrawalRequestAction,
  submitWithdrawalRequest as submitWithdrawalRequestAction,
  withdrawalStatusLabel as withdrawalStatusLabelAction,
  runAdminWithdrawalAction as runAdminWithdrawalActionAction
} from "./admin-withdrawals.actions";
import {
  editAdminUser as editAdminUserAction,
  saveAdminUser as saveAdminUserAction,
  editAdminProduct as editAdminProductAction,
  saveAdminProduct as saveAdminProductAction,
  moderateAdminReview as moderateAdminReviewAction,
  editAdminStore as editAdminStoreAction,
  saveAdminStore as saveAdminStoreAction,
  setSellerVerification as setSellerVerificationAction
} from "./admin-catalog.actions";
import {
  syncAuthenticatedUser as syncAuthenticatedUserAction,
  loadAccountData as loadAccountDataAction,
  loadProfile as loadProfileAction,
  prepareLayer as prepareLayerAction,
  loadOrders as loadOrdersAction,
  loadOlderOrders as loadOlderOrdersAction,
  apiError as apiErrorAction,
  handleUnauthorized as handleUnauthorizedAction
} from "./account.actions";
import {
  submitContact as submitContactAction,
  submitNewsletter as submitNewsletterAction,
  formatPrice as formatPriceAction,
  showToast as showToastAction,
  initialLanguage as initialLanguageAction,
  initialCurrency as initialCurrencyAction,
  convertCurrency as convertCurrencyAction,
  refreshCatalog as refreshCatalogAction,
  reconcileCatalogStore as reconcileCatalogStoreAction,
  applyLanguage as applyLanguageAction
} from "./storefront.actions";
import {
  ratingStars as ratingStarsAction,
  openProductDetails as openProductDetailsAction,
  loadProductReviews as loadProductReviewsAction,
  loadOlderProductReviews as loadOlderProductReviewsAction,
  resolveProductPage as resolveProductPageAction,
  loadMoreProducts as loadMoreProductsAction,
  retryCatalog as retryCatalogAction,
  closeProductDetails as closeProductDetailsAction,
  submitProductReview as submitProductReviewAction
} from './product.actions';
import {
  canRequestWithdrawal as canRequestWithdrawalView,
  orderRefundRemaining as orderRefundRemainingView
} from './order.view';



@Component({
  selector: 'app-storefront',
  standalone: true,
  imports: [CommonModule, FormsModule, AdminContactInboxComponent, AdminAuditLogComponent, AdminDisputesComponent, AdminFinancialOperationsComponent, AdminCatalogManagementComponent, AdminPaymentNotificationsComponent, AdminRefundReconciliationComponent, AuthFormComponent, ProductCardComponent, InformationPagesComponent, CheckoutDetailsFormComponent, CheckoutDeliveryComponent, CheckoutFinalReviewComponent, CheckoutOrderSummaryComponent, CheckoutPendingComponent, AccountOrderHistoryComponent, SellerStudioComponent],
  templateUrl: './app.component.html',
  styleUrl: './app.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class StorefrontComponent {
  readonly document = inject(DOCUMENT);
  readonly route = inject(ActivatedRoute);
  readonly router = inject(Router);
  readonly destroyRef = inject(DestroyRef);
  readonly cdr = inject(ChangeDetectorRef);
  readonly auth = inject(AuthService);
  readonly catalog = inject(CatalogService);
  readonly commerce = inject(CommerceService);
  readonly store = inject(StoreService);
  readonly checkoutState = inject(CheckoutWorkflowState);
  readonly checkoutWorkflow = inject(CheckoutWorkflowService);
  readonly adminPriceFormatter = (value: number, sourceCurrency?: Currency): string => this.formatPrice(value, sourceCurrency);
  readonly sellerCategoryLabelFormatter = (category: Category): string => this.categoryLabel(category);
  private checkoutSummaryCacheSource: CartLine[] | null = null;
  private checkoutSummaryCacheCurrency: Currency | null = null;
  private checkoutSummaryCacheRates: Record<Currency, number | null> | null = null;
  private checkoutSummaryCache: CheckoutOrderSummaryItem[] = [];

  readonly languages: Language[] = ['en', 'fr'];
  readonly currencies: Currency[] = ['USD', 'EUR'];
  readonly categoryIds: Category[] = ['all', 'plants', 'home', 'care'];
  readonly deliveryCountries = DELIVERY_COUNTRIES;

  readonly translations = STOREFRONT_TRANSLATIONS;

  currentLanguage: Language = this.initialLanguage();
  currentCurrency: Currency = this.initialCurrency();
  currentPage: Page = 'home';
  authMode: AuthMode = 'login';
  selectedCategory: Category = 'all';
  menuOpen = false;
  searchOpen = false;
  cartOpen = false;
  newsletterSent = false;
  newsletterLoading = false;
  newsletterEmail = '';
  newsletterError = '';
  searchTerm = '';
  toast = '';
  showPassword = false;
  authLoading = false;
  authError = '';
  authSuccess = '';
  authActionUrl = '';
  demoAccounts: DemoAccount[] = [];
  rememberMe = true;
  loginForm = { email: '', password: '' };
  registerForm = { name: '', email: '', password: '', confirmPassword: '', role: 'customer' as 'customer' | 'seller', storeName: '', city: '', region: '', country: 'Belgium', acceptTerms: false };
  forgotEmail = '';
  resetToken = '';
  resetForm = { password: '', confirmPassword: '' };
  verificationPassword = '';
  contactSent = false;
  contactLoading = false;
  contactError = '';
  contactForm: ContactFormData = { name: '', email: '', subject: 'order', message: '' };
  orders: OrderSummary[] = [];
  ordersLoading = false;
  ordersPage = 1;
  ordersTotal = 0;
  ordersHasMore = false;
  ordersError = '';
  accountProfile: AccountProfile | null = null;
  profileLoading = false;
  profileError = '';
  profileSuccess = '';
  profileForm = { name: '', bio: '', city: '', region: '', country: 'Belgium' };
  withdrawalOrder: OrderSummary | null = null;
  withdrawalType: 'withdrawal' | 'problem' = 'withdrawal';
  withdrawalMessage = '';
  withdrawalDeliveryDate = '';
  withdrawalLoading = false;
  withdrawalError = '';
  withdrawalSuccess = '';
  sellerStore: MarketplaceStore | null = null;
  managedProducts: ManagedProduct[] = [];
  sellerLoading = false;
  sellerError = '';
  sellerSuccess = '';
  sellerForm: SellerStoreForm = { displayName: '', bio: '', city: '', region: '', country: 'Belgium' };
  productForm: SellerProductForm = { id: null, name: '', description: '', origin: '', dimensions: '', materials: '', careInstructions: '', category: 'plants', price: 1, shippingWeightGrams: 1000, stock: 0, sprite: 0, badge: '', status: 'draft' };
  adminSellers: AdminSeller[] = [];
  adminSellerPage = 1;
  adminSellersTotal = 0;
  adminSellersHasMore = false;
  adminUsers: AdminUser[] = [];
  adminProducts: AdminProduct[] = [];
  adminReviews: AdminReview[] = [];
  adminOverviewPage = 1;
  adminUsersTotal = 0;
  adminProductsTotal = 0;
  adminReviewsTotal = 0;
  adminOverviewHasMore = false;
  adminOrders: AdminOrder[] = [];
  adminPaymentDisputes: AdminPaymentDispute[] = [];
  adminPaymentDisputesPage = 1;
  adminPaymentDisputesTotal = 0;
  adminPaymentDisputesHasMore = false;
  adminPaymentNotifications: AdminPaymentNotification[] = [];
  adminPaymentNotificationsPage = 1;
  adminPaymentNotificationsTotal = 0;
  adminPaymentNotificationsHasMore = false;
  adminOrderPage = 1;
  adminOrdersTotal = 0;
  adminOrdersHasMore = false;
  adminWithdrawals: AdminWithdrawalRequest[] = [];
  adminWithdrawalsPage = 1;
  adminWithdrawalsTotal = 0;
  adminWithdrawalsHasMore = false;
  adminContactMessages: AdminContactMessage[] = [];
  adminContactMessagesPage = 1;
  adminContactMessagesTotal = 0;
  adminContactMessagesHasMore = false;
  adminAuditEntries: AdminAuditEntry[] = [];
  adminAuditPage = 1;
  adminAuditTotal = 0;
  adminAuditHasMore = false;
  editingAdminUser: AdminUser | null = null;
  editingAdminProduct: AdminProduct | null = null;
  editingAdminStore: AdminSeller | null = null;
  adminUserForm: AdminUserForm = { id: 0, name: '', email: '', role: 'customer', status: 'active', emailVerified: true };
  adminProductForm: AdminProductForm = { id: 0, name: '', description: '', origin: '', dimensions: '', materials: '', careInstructions: '', category: 'plants', price: 1, shippingWeightGrams: 1000, stock: 0, sprite: 0, badge: '', status: 'draft', marketplace: true, featured: false };
  adminStoreForm: AdminStoreForm = { id: 0, displayName: '', bio: '', city: '', region: '', country: '' };
  adminLoading = false;
  adminError = '';
  adminSuccess = '';
  selectedProduct: Product | null = null;
  productSlug = '';
  productPageNotFound = false;
  productPageLoading = false;
  productPageError = '';
  productReviews: ProductReview[] = [];
  productReviewsPage = 1;
  productReviewsHasMore = false;
  productReviewsRequestVersion = 0;
  productReviewSummary = { average: 0, count: 0 };
  canReviewProduct = false;
  reviewForm = { rating: 5, comment: '' };
  reviewLoading = false;
  reviewLoadError = '';
  reviewError = '';
  reviewSuccess = '';
  ownReviewStatus: 'visible' | 'hidden' | null = null;
  lastFocused: HTMLElement | null = null;
  productLookupSlug = '';

  constructor() {
    this.applyLanguage(this.currentLanguage);
    this.auth.sessionChanged$.pipe(takeUntilDestroyed(this.destroyRef)).subscribe(() => {
      this.clearSensitiveSessionData();
      if (!this.user) {
        void this.router.navigateByUrl('/login');
      } else if (this.auth.ready()) {
        this.syncAuthenticatedUser();
        if (this.currentPage === 'product' && this.selectedProduct) this.loadProductReviews(this.selectedProduct);
      }
    });
    this.route.data.pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe((data) => {
      this.currentPage = data['page'] as Page;
      if (this.currentPage !== 'product') {
        this.selectedProduct = null;
        this.productPageNotFound = false;
      }
      this.authMode = (data['authMode'] as AuthMode | undefined) ?? 'login';
      this.authError = '';
      this.authSuccess = '';
      this.applyLanguage(this.currentLanguage);
      if (this.currentPage === 'account' && this.user) this.loadAccountData();
      if (this.currentPage === 'seller' && this.user && this.user.role !== 'admin') this.loadSellerDashboard();
      if (this.currentPage === 'admin' && this.user?.role === 'admin') this.loadAdminSellers();
      this.cdr.markForCheck();
    });
    this.route.paramMap.pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe((params) => {
      const slug = params.get('slug') ?? '';
      if (slug !== this.productSlug) {
        this.productPageNotFound = false;
        this.productPageError = '';
        this.productPageLoading = false;
        this.productLookupSlug = '';
      }
      this.productSlug = slug;
      this.resolveProductPage();
      this.cdr.markForCheck();
    });
    combineLatest([this.route.queryParamMap, this.route.fragment]).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe(([params, fragment]) => {
      const fragmentToken = new URLSearchParams(fragment ?? '').get('token') ?? '';
      const legacyToken = params.get('token') ?? '';
      this.resetToken = fragmentToken || legacyToken;
      if (legacyToken && !fragmentToken) {
        void this.router.navigate([], {
          relativeTo: this.route,
          queryParams: {},
          fragment: `token=${legacyToken}`,
          replaceUrl: true
        });
      }
      const sessionId = params.get('session_id') ?? '';
      const orderNumber = params.get('order_number') ?? '';
      if (sessionId || orderNumber) {
        // Older Stripe sessions may still return with identifiers in their URLs.
        // Retain their in-memory recovery state, but replace the URL immediately.
        const safeQueryParams = Object.fromEntries(params.keys
          .filter((key) => key !== 'session_id' && key !== 'order_number')
          .map((key) => [key, params.get(key)]));
        void this.router.navigate([], {
          relativeTo: this.route,
          queryParams: safeQueryParams,
          fragment: this.route.snapshot?.fragment ?? undefined,
          replaceUrl: true
        });
      }
      if (sessionId) {
        this.paymentReturnSessionId = sessionId;
        this.verifyCheckoutReturn();
      }
      if (orderNumber) this.pendingCheckoutOrderNumber = orderNumber;
      if (params.get('payment') === 'success') {
        this.checkoutError = this.copy('Payment returned successfully. We are checking the order status.', 'Le paiement est revenu avec succès. Nous vérifions le statut de la commande.');
      }
      if (params.get('payment') === 'cancelled') {
        this.checkoutError = this.copy('You left the payment page. We are checking the order so you can safely resume or cancel it.', 'Vous avez quitté la page de paiement. Nous vérifions la commande afin que vous puissiez la reprendre ou l’annuler en toute sécurité.');
        if (orderNumber) this.loadCheckoutOrderStatus(orderNumber);
      }
      this.cdr.markForCheck();
    });
    this.auth.initialize().pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: () => {
        this.syncAuthenticatedUser();
        if (this.currentPage === 'product' && this.selectedProduct) this.loadProductReviews(this.selectedProduct);
      },
      error: () => { this.authError = this.copy('The account service is unavailable.', 'Le service de compte est indisponible.'); }
    });
    this.auth.demoAccounts().pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: ({ enabled, accounts }) => { this.demoAccounts = enabled ? accounts : []; },
      error: () => { this.demoAccounts = []; }
    });
    this.catalog.load().pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: () => {
        this.reconcileCatalogStore();
        this.resolveProductPage();
      },
      error: () => { /* The catalog exposes its own error state. */ }
    });
  }

  get products(): Product[] { return this.catalog.products(); }
  get favorites(): Set<number> { return this.store.favorites(); }
  get cart(): CartLine[] { return this.store.cart(); }
  get user(): AuthUser | null { return this.auth.user(); }

  get checkoutError(): string { return this.checkoutState.checkoutError; }
  set checkoutError(value: string) { this.checkoutState.checkoutError = value; }
  get checkoutForm(): CheckoutPreviewData { return this.checkoutState.checkoutForm; }
  set checkoutForm(value: CheckoutPreviewData) { this.checkoutState.checkoutForm = value; }
  get shippingQuoteLoading(): boolean { return this.checkoutState.shippingQuoteLoading; }
  set shippingQuoteLoading(value: boolean) { this.checkoutState.shippingQuoteLoading = value; }
  get shippingQuoteError(): string { return this.checkoutState.shippingQuoteError; }
  set shippingQuoteError(value: string) { this.checkoutState.shippingQuoteError = value; }
  get shippingQuoteResponse(): ShippingQuoteResponse | null { return this.checkoutState.shippingQuoteResponse; }
  set shippingQuoteResponse(value: ShippingQuoteResponse | null) { this.checkoutState.shippingQuoteResponse = value; }
  get shippingQuotes(): ShippingQuote[] { return this.checkoutState.shippingQuotes; }
  set shippingQuotes(value: ShippingQuote[]) { this.checkoutState.shippingQuotes = value; }
  get selectedShippingQuote(): ShippingQuote | null { return this.checkoutState.selectedShippingQuote; }
  set selectedShippingQuote(value: ShippingQuote | null) { this.checkoutState.selectedShippingQuote = value; }
  get paymentPreviewMethod(): CheckoutPaymentMethod { return this.checkoutState.paymentPreviewMethod; }
  set paymentPreviewMethod(value: CheckoutPaymentMethod) { this.checkoutState.paymentPreviewMethod = value; }
  get paymentPreviewAttempted(): boolean { return this.checkoutState.paymentPreviewAttempted; }
  set paymentPreviewAttempted(value: boolean) { this.checkoutState.paymentPreviewAttempted = value; }
  get paymentStarting(): boolean { return this.checkoutState.paymentStarting; }
  set paymentStarting(value: boolean) { this.checkoutState.paymentStarting = value; }
  get checkoutCompleted(): boolean { return this.checkoutState.checkoutCompleted; }
  set checkoutCompleted(value: boolean) { this.checkoutState.checkoutCompleted = value; }
  get checkoutOrderNumber(): string { return this.checkoutState.checkoutOrderNumber; }
  set checkoutOrderNumber(value: string) { this.checkoutState.checkoutOrderNumber = value; }
  get checkoutIsSimulation(): boolean { return this.checkoutState.checkoutIsSimulation; }
  set checkoutIsSimulation(value: boolean) { this.checkoutState.checkoutIsSimulation = value; }
  get paymentReturnSessionId(): string { return this.checkoutState.paymentReturnSessionId; }
  set paymentReturnSessionId(value: string) { this.checkoutState.paymentReturnSessionId = value; }
  get paymentVerificationLoading(): boolean { return this.checkoutState.paymentVerificationLoading; }
  set paymentVerificationLoading(value: boolean) { this.checkoutState.paymentVerificationLoading = value; }
  get checkoutOrderStatusLoading(): boolean { return this.checkoutState.checkoutOrderStatusLoading; }
  set checkoutOrderStatusLoading(value: boolean) { this.checkoutState.checkoutOrderStatusLoading = value; }
  get checkoutPending(): boolean { return this.checkoutState.checkoutPending; }
  set checkoutPending(value: boolean) { this.checkoutState.checkoutPending = value; }
  get checkoutResumeUrl(): string | null { return this.checkoutState.checkoutResumeUrl; }
  set checkoutResumeUrl(value: string | null) { this.checkoutState.checkoutResumeUrl = value; }
  get checkoutCanRetry(): boolean { return this.checkoutState.checkoutCanRetry; }
  set checkoutCanRetry(value: boolean) { this.checkoutState.checkoutCanRetry = value; }
  get checkoutCanCancel(): boolean { return this.checkoutState.checkoutCanCancel; }
  set checkoutCanCancel(value: boolean) { this.checkoutState.checkoutCanCancel = value; }
  get pendingCheckoutOrderNumber(): string { return this.checkoutState.pendingCheckoutOrderNumber; }
  set pendingCheckoutOrderNumber(value: string) { this.checkoutState.pendingCheckoutOrderNumber = value; }
  get pendingCheckoutOrder(): OrderSummary | null { return this.checkoutState.pendingCheckoutOrder; }
  set pendingCheckoutOrder(value: OrderSummary | null) { this.checkoutState.pendingCheckoutOrder = value; }
  get checkoutIdempotencyKey(): string { return this.checkoutState.checkoutIdempotencyKey; }
  set checkoutIdempotencyKey(value: string) { this.checkoutState.checkoutIdempotencyKey = value; }
  get checkoutStateRevision(): number { return this.checkoutState.checkoutStateRevision; }
  set checkoutStateRevision(value: number) { this.checkoutState.checkoutStateRevision = value; }
  get shippingQuoteRequestKey(): string { return this.checkoutState.shippingQuoteRequestKey; }
  set shippingQuoteRequestKey(value: string) { this.checkoutState.shippingQuoteRequestKey = value; }

  get visibleProducts(): Product[] {
    return visibleStorefrontProductsView(this.products, this.selectedCategory);
  }

  get pageProducts(): Product[] {
    return pageStorefrontProductsView(this.products, this.currentPage, this.selectedCategory);
  }

  get favoriteProducts(): Product[] {
    return favoriteStorefrontProductsView(this.products, this.favorites);
  }

  get marketplaceProducts(): Product[] {
    return marketplaceStorefrontProductsView(this.products);
  }

  get marketplaceSellerCount(): number {
    return marketplaceSellerCountView(this.products);
  }

  get searchResults(): Product[] {
    return searchStorefrontProductsView(this.products, this.searchTerm, this.locale);
  }

  get cartCount(): number {
    return cartProductCountView(this.cart);
  }

  get hasPaymentTestProduct(): boolean {
    return this.cart.some((line) => line.testPrice === true);
  }

  orderRefundRemaining(order: OrderSummary): number {
    return orderRefundRemainingView(order);
  }

  canRequestWithdrawal(order: OrderSummary): boolean {
    return canRequestWithdrawalView(order);
  }

  get cartTotal(): number {
    return this.cart.reduce((total, item) => total + this.productPrice(item) * item.quantity, 0);
  }

  get checkoutSummaryItems(): CheckoutOrderSummaryItem[] {
    const cart = this.cart;
    const currency = this.currentCurrency;
    const rates = this.catalog.currencyRates();
    if (this.checkoutSummaryCacheSource === cart && this.checkoutSummaryCacheCurrency === currency && this.checkoutSummaryCacheRates === rates) {
      return this.checkoutSummaryCache;
    }

    this.checkoutSummaryCacheSource = cart;
    this.checkoutSummaryCacheCurrency = currency;
    this.checkoutSummaryCacheRates = rates;
    this.checkoutSummaryCache = cart.map((line) => ({
      id: line.id,
      sprite: line.sprite,
      quantity: line.quantity,
      seller: line.seller,
      name: this.productName(line),
      lineTotal: this.formatPrice(this.productPrice(line) * line.quantity)
    }));
    return this.checkoutSummaryCache;
  }

  get locale(): string {
    return this.currentLanguage === 'fr' ? 'fr-FR' : 'en-US';
  }

  get passwordStrength(): number {
    const password = this.authMode === 'reset' ? this.resetForm.password : this.registerForm.password;
    return [password.length >= 12, /[a-z]/.test(password) && /[A-Z]/.test(password), /\d/.test(password), /[^A-Za-z0-9]/.test(password)].filter(Boolean).length;
  }

  @HostListener('document:keydown', ['$event'])
  handleLayerKeys(event: KeyboardEvent): void {
    if (!this.menuOpen && !this.searchOpen && !this.cartOpen) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      this.closeLayers();
      return;
    }
    if (event.key !== 'Tab') return;
    const layer = this.document.querySelector<HTMLElement>('.search-layer, .mobile-menu, .cart-drawer');
    if (!layer) return;
    const focusable = Array.from(layer.querySelectorAll<HTMLElement>('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'));
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && this.document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && this.document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  closeLayers(): void {
    const hadOpenLayer = this.menuOpen || this.searchOpen || this.cartOpen;
    this.menuOpen = false;
    this.searchOpen = false;
    this.cartOpen = false;
    this.document.body.classList.remove('layer-open');
    if (hadOpenLayer) {
      this.lastFocused?.focus();
      this.lastFocused = null;
    }
  }

  tr(key: string): string {
    return this.translations[key]?.[this.currentLanguage] ?? key;
  }

  trf(key: string, values: Record<string, string>): string {
    return Object.entries(values).reduce((text, [name, value]) => text.replace(`{${name}}`, value), this.tr(key));
  }

  copy(en: string, fr: string): string {
    return this.currentLanguage === 'fr' ? fr : en;
  }

  productName(product: Product): string {
    return product.name;
  }

  productSubtitle(product: Product): string {
    return product.subtitle;
  }

  productBadge(product: Product): string {
    return localizedProductBadge(product.badge, this.currentLanguage);
  }

  productPrice(product: Product): number {
    return this.convertCurrency(product.price, product.currency, this.currentCurrency);
  }

  ratingStars(rating: number): string {
    return ratingStarsAction(rating);
  }

  openProductDetails(product: Product): void {
    return openProductDetailsAction.call(this, product);
  }

  loadProductReviews(product: Product): void {
    return loadProductReviewsAction.call(this, product);
  }

  loadOlderProductReviews(): void {
    return loadOlderProductReviewsAction.call(this);
  }

  resolveProductPage(): void {
    return resolveProductPageAction.call(this);
  }

  loadMoreProducts(): void {
    return loadMoreProductsAction.call(this);
  }

  retryCatalog(): void {
    return retryCatalogAction.call(this);
  }

  closeProductDetails(): void {
    return closeProductDetailsAction.call(this);
  }

  submitProductReview(): void {
    return submitProductReviewAction.call(this);
  }

  categoryLabel(category: Category): string {
    return this.tr(`category.${category}`);
  }

  setLanguage(language: Language): void {
    this.currentLanguage = language;
    this.searchTerm = '';
    this.toast = '';
    this.applyLanguage(language);
    try {
      window.localStorage.setItem('botanical-language', language);
    } catch {
      // The language still changes when storage is unavailable.
    }
  }

  setCurrency(currency: Currency): void {
    this.currentCurrency = currency;
    this.paymentPreviewMethod = checkoutPaymentMethodForCurrency(currency, this.paymentPreviewMethod);
    if (!this.checkoutPending) this.clearCheckoutIdempotencyKey();
    this.toast = '';
    try {
      window.localStorage.setItem('botanical-currency', currency);
    } catch {
      // The currency still changes when storage is unavailable.
    }
  }

  navigatePage(page: Page, authMode?: AuthMode): void {
    const paths: Record<Page, string> = {
      home: '/', shop: '/shop', marketplace: '/marketplace', product: this.selectedProduct ? `/product/${this.selectedProduct.slug}` : '/shop', essence: '/our-essence', favorites: '/favorites',
      account: '/account', seller: '/seller', admin: '/admin', checkout: '/payment', contact: '/contact', deliveries: '/deliveries', returns: '/returns',
      terms: '/terms', privacy: '/privacy'
    };
    let path = paths[page];
    if (page === 'account' && authMode) {
      path = authMode === 'register' ? '/create-account'
        : authMode === 'forgot' ? '/forgot-password'
        : authMode === 'reset' ? '/reset-password'
        : authMode === 'verify' ? '/verify-email'
        : '/login';
    }
    this.authError = '';
    this.authSuccess = '';
    this.authActionUrl = '';
    this.closeLayers();
    void this.router.navigateByUrl(path);
  }

  toggleMenu(): void {
    if (this.menuOpen) {
      this.closeLayers();
      return;
    }
    this.prepareLayer();
    this.menuOpen = !this.menuOpen;
    this.cartOpen = false;
    this.searchOpen = false;
    setTimeout(() => this.document.querySelector<HTMLElement>('.mobile-menu')?.focus());
  }

  openSearch(): void {
    this.prepareLayer();
    this.searchOpen = true;
    this.menuOpen = false;
    this.cartOpen = false;
    setTimeout(() => this.document.querySelector<HTMLInputElement>('#search-input')?.focus(), 80);
  }

  openCart(): void {
    this.prepareLayer();
    this.cartOpen = true;
    this.menuOpen = false;
    this.searchOpen = false;
    setTimeout(() => this.document.querySelector<HTMLElement>('.cart-drawer')?.focus());
  }

  filterBy(category: Category): void {
    this.selectedCategory = category;
  }

  addToCart(product: Product): void {
    if (product.stock < 1) return;
    this.checkoutCompleted = false;
    this.store.add(product);
    this.invalidateShippingQuote();
    this.showToast(this.trf('toast.added', { name: this.productName(product) }));
  }

  buyNow(product: Product): void {
    if (product.stock < 1) return;
    this.paymentPreviewAttempted = false;
    if (!this.cart.some((line) => line.id === product.id)) {
      this.store.add(product);
      this.invalidateShippingQuote();
    }
    this.closeLayers();
    void this.router.navigateByUrl('/payment').then(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
  }

  private checkoutWorkflowEffects(): CheckoutWorkflowEffects {
    return {
      currency: this.currentCurrency,
      apiError: (error, fallback) => this.apiError(error, fallback),
      copy: (english, french) => this.copy(english, french),
      calculateShipping: () => this.calculateShipping(),
      handleUnauthorized: (error) => this.handleUnauthorized(error),
      loadOrders: () => this.loadOrders(),
      showToast: (message) => this.showToast(message),
      markForCheck: () => this.cdr.markForCheck()
    };
  }

  startPurchase(): void { this.checkoutWorkflow.startPurchase(this.checkoutWorkflowEffects()); }

  verifyCheckoutReturn(): void { this.checkoutWorkflow.verifyCheckoutReturn(this.checkoutWorkflowEffects()); }

  loadCheckoutOrderStatus(orderNumber: string): void { this.checkoutWorkflow.loadCheckoutOrderStatus(orderNumber, this.checkoutWorkflowEffects()); }

  refreshCheckoutOrderStatus(): void { this.checkoutWorkflow.refreshCheckoutOrderStatus(this.checkoutWorkflowEffects()); }

  resumePendingCheckout(): void { this.checkoutWorkflow.resumePendingCheckout(this.checkoutWorkflowEffects()); }

  cancelPendingCheckout(): void { this.checkoutWorkflow.cancelPendingCheckout(this.checkoutWorkflowEffects()); }

  rememberPendingCheckout(orderNumber: string): void { this.checkoutWorkflow.rememberPendingCheckout(orderNumber); }

  clearPendingCheckout(): void { this.checkoutWorkflow.clearPendingCheckout(); }

  clearCheckoutIdempotencyKey(): void { this.checkoutWorkflow.clearCheckoutIdempotencyKey(); }

  resetCheckoutForAnonymousUser(): void { this.checkoutWorkflow.resetCheckoutForAnonymousUser(); }

  clearSensitiveSessionData(): void {
    this.resetCheckoutForAnonymousUser();
    this.orders = [];
    this.ordersLoading = false;
    this.ordersPage = 1;
    this.ordersTotal = 0;
    this.ordersHasMore = false;
    this.ordersError = '';
    this.accountProfile = null;
    this.profileLoading = false;
    this.profileError = '';
    this.profileSuccess = '';
    this.profileForm = { name: '', bio: '', city: '', region: '', country: 'Belgium' };
    this.withdrawalOrder = null;
    this.withdrawalType = 'withdrawal';
    this.withdrawalMessage = '';
    this.withdrawalDeliveryDate = '';
    this.withdrawalLoading = false;
    this.withdrawalError = '';
    this.withdrawalSuccess = '';
    this.sellerStore = null;
    this.managedProducts = [];
    this.sellerLoading = false;
    this.sellerError = '';
    this.sellerSuccess = '';
    this.sellerForm = { displayName: '', bio: '', city: '', region: '', country: 'Belgium' };
    this.productForm = { id: null, name: '', description: '', origin: '', dimensions: '', materials: '', careInstructions: '', category: 'plants', price: 1, shippingWeightGrams: 1000, stock: 0, sprite: 0, badge: '', status: 'draft' };
    this.adminSellers = [];
    this.adminSellerPage = 1;
    this.adminSellersTotal = 0;
    this.adminSellersHasMore = false;
    this.adminUsers = [];
    this.adminProducts = [];
    this.adminReviews = [];
    this.adminOverviewPage = 1;
    this.adminUsersTotal = 0;
    this.adminProductsTotal = 0;
    this.adminReviewsTotal = 0;
    this.adminOverviewHasMore = false;
    this.adminOrders = [];
    this.adminPaymentDisputes = [];
    this.adminPaymentDisputesPage = 1;
    this.adminPaymentDisputesTotal = 0;
    this.adminPaymentDisputesHasMore = false;
    this.adminPaymentNotifications = [];
    this.adminPaymentNotificationsPage = 1;
    this.adminPaymentNotificationsTotal = 0;
    this.adminPaymentNotificationsHasMore = false;
    this.adminOrderPage = 1;
    this.adminOrdersTotal = 0;
    this.adminOrdersHasMore = false;
    this.adminWithdrawals = [];
    this.adminWithdrawalsPage = 1;
    this.adminWithdrawalsTotal = 0;
    this.adminWithdrawalsHasMore = false;
    this.adminContactMessages = [];
    this.adminContactMessagesPage = 1;
    this.adminContactMessagesTotal = 0;
    this.adminContactMessagesHasMore = false;
    this.adminAuditEntries = [];
    this.adminAuditPage = 1;
    this.adminAuditTotal = 0;
    this.adminAuditHasMore = false;
    this.editingAdminUser = null;
    this.editingAdminProduct = null;
    this.editingAdminStore = null;
    this.adminUserForm = { id: 0, name: '', email: '', role: 'customer', status: 'active', emailVerified: true };
    this.adminProductForm = { id: 0, name: '', description: '', origin: '', dimensions: '', materials: '', careInstructions: '', category: 'plants', price: 1, shippingWeightGrams: 1000, stock: 0, sprite: 0, badge: '', status: 'draft', marketplace: true, featured: false };
    this.adminStoreForm = { id: 0, displayName: '', bio: '', city: '', region: '', country: '' };
    this.adminLoading = false;
    this.adminError = '';
    this.adminSuccess = '';
    this.newsletterEmail = '';
    this.newsletterError = '';
    this.toast = '';
    this.contactForm = { name: '', email: '', subject: 'order', message: '' };
    this.contactLoading = false;
    this.contactError = '';
    this.contactSent = false;
    this.reviewForm = { rating: 5, comment: '' };
    this.reviewLoading = false;
    this.reviewLoadError = '';
    this.canReviewProduct = false;
    this.ownReviewStatus = null;
    this.reviewError = '';
    this.reviewSuccess = '';
    this.authLoading = false;
    this.authError = '';
    this.authSuccess = '';
    this.authActionUrl = '';
    this.loginForm = { email: '', password: '' };
    this.registerForm = { name: '', email: '', password: '', confirmPassword: '', role: 'customer', storeName: '', city: '', region: '', country: 'Belgium', acceptTerms: false };
    this.forgotEmail = '';
    this.resetToken = '';
    this.resetForm = { password: '', confirmPassword: '' };
    this.verificationPassword = '';
    this.showPassword = false;
    this.checkoutForm.name = '';
    this.checkoutForm.email = '';
  }

  readCheckoutStorage(kind: 'key' | 'order'): string { return this.checkoutWorkflow.readCheckoutStorage(kind); }

  writeCheckoutStorage(kind: 'key' | 'order', value: string): void { this.checkoutWorkflow.writeCheckoutStorage(kind, value); }

  finishCheckout(order: OrderSummary, simulated: boolean, alreadyPaid: boolean): void {
    this.checkoutWorkflow.finishCheckout(order, simulated, alreadyPaid, this.checkoutWorkflowEffects());
  }

  changeQuantity(id: number, amount: number): void { return changeQuantityAction.call(this, id, amount); }

  invalidateShippingQuote(): void { return invalidateShippingQuoteAction.call(this); }

  checkoutDetailsChanged(): void { return checkoutDetailsChangedAction.call(this); }

  checkoutContactChanged(change: CheckoutContactChange): void {
    this.checkoutForm[change.field] = change.value;
    this.checkoutDetailsChanged();
  }

  calculateShipping(): void { return calculateShippingAction.call(this); }

  selectShippingQuote(quote: ShippingQuote | null): void { return selectShippingQuoteAction.call(this, quote); }

  shippingLabel(quote: ShippingQuote): string { return shippingLabelText(quote, this.currentLanguage); }

  currentShippingQuoteKey(): string { return currentShippingQuoteKeyAction.call(this); }

  toggleFavorite(id: number): void { return toggleFavoriteAction.call(this, id); }

  scrollTo(id: string): void { return scrollToAction.call(this, id); }

  switchAuth(mode: AuthMode): void { return switchAuthAction.call(this, mode); }

  submitLogin(): void { return submitLoginAction.call(this); }

  loginAsDemo(role: DemoAccount['role']): void { return loginAsDemoAction.call(this, role); }

  saveProfile(): void { return saveProfileAction.call(this); }

  submitRegister(): void { return submitRegisterAction.call(this); }

  submitForgotPassword(): void { return submitForgotPasswordAction.call(this); }

  submitResetPassword(): void { return submitResetPasswordAction.call(this); }

  submitVerifyEmail(): void { return submitVerifyEmailAction.call(this); }

  openAuthAction(): void { return openAuthActionAction.call(this); }

  logout(): void { return logoutAction.call(this); }

  loadSellerDashboard(): void { return loadSellerDashboardAction.call(this); }

  saveSellerStore(): void { return saveSellerStoreAction.call(this); }

  editManagedProduct(product: ManagedProduct): void { return editManagedProductAction.call(this, product); }

  resetProductForm(): void { return resetProductFormAction.call(this); }

  saveSellerProduct(): void { return saveSellerProductAction.call(this); }

  archiveSellerProduct(product: ManagedProduct): void { return archiveSellerProductAction.call(this, product); }

  loadAdminSellers(): void { return loadAdminSellersAction.call(this); }

  loadOlderAdminManagement(): void { return loadOlderAdminManagementAction.call(this); }

  loadOlderAdminSellers(): void { return loadOlderAdminSellersAction.call(this); }

  loadOlderAdminContactMessages(): void { return loadOlderAdminContactMessagesAction.call(this); }

  loadOlderAdminAuditLog(): void { return loadOlderAdminAuditLogAction.call(this); }

  loadOlderAdminDisputes(): void { return loadOlderAdminDisputesAction.call(this); }
  loadOlderAdminPaymentNotifications(): void { return loadOlderAdminPaymentNotificationsAction.call(this); }
  retryAdminPaymentNotification(notification: AdminPaymentNotification): void { return retryAdminPaymentNotificationAction.call(this, notification); }

  loadOlderAdminWithdrawals(): void { return loadOlderAdminWithdrawalsAction.call(this); }

  updateAdminContactMessage(message: AdminContactMessage, status: AdminContactMessage['status']): void { return updateAdminContactMessageAction.call(this, message, status); }

  loadOlderAdminOrders(): void { return loadOlderAdminOrdersAction.call(this); }

  partialRefundAdminOrder(order: AdminOrder): void { return partialRefundAdminOrderAction.call(this, order); }

  openWithdrawalRequest(order: OrderSummary, type: 'withdrawal' | 'problem'): void { return openWithdrawalRequestAction.call(this, order, type); }

  closeWithdrawalRequest(): void { return closeWithdrawalRequestAction.call(this); }

  submitWithdrawalRequest(): void { return submitWithdrawalRequestAction.call(this); }

  withdrawalStatusLabel(status: string | null | undefined): string { return withdrawalStatusLabelAction.call(this, status); }

  runAdminWithdrawalAction(request: AdminWithdrawalRequest, action: 'review' | 'send_instructions' | 'mark_received' | 'not_eligible' | 'refund' | 'check_refund' | 'retry_refund'): void { return runAdminWithdrawalActionAction.call(this, request, action); }

  cancelAdminOrder(order: AdminOrder): void { return cancelAdminOrderAction.call(this, order); }

  resolveUnattachedCheckoutAttempt(order: AdminOrder): void { return resolveUnattachedCheckoutAttemptAction.call(this, order); }

  adminRefundAction(order: AdminOrder, action: AdminRefundAction, resolution?: 'refunded' | 'not_refunded'): void { return adminRefundActionAction.call(this, order, action, resolution); }

  runAdminOrderAction(order: AdminOrder, action: AdminOrderAction | 'resolve_checkout_attempt', resolution?: 'refunded' | 'not_refunded', reason?: string): void { return runAdminOrderActionAction.call(this, order, action, resolution, reason); }

  editAdminUser(user: AdminUser): void { return editAdminUserAction.call(this, user); }

  saveAdminUser(): void { return saveAdminUserAction.call(this); }

  editAdminProduct(product: AdminProduct): void { return editAdminProductAction.call(this, product); }

  saveAdminProduct(): void { return saveAdminProductAction.call(this); }

  moderateAdminReview(review: AdminReview, status: 'visible' | 'hidden'): void { return moderateAdminReviewAction.call(this, review, status); }

  editAdminStore(seller: AdminSeller): void { return editAdminStoreAction.call(this, seller); }

  saveAdminStore(): void { return saveAdminStoreAction.call(this); }

  setSellerVerification(seller: AdminSeller, verified: boolean): void { return setSellerVerificationAction.call(this, seller, verified); }

  submitContact(form: ContactFormData): void {
    this.contactForm = { ...form };
    return submitContactAction.call(this);
  }

  submitNewsletter(): void { return submitNewsletterAction.call(this); }

  get checkoutTotal(): number { return checkoutTotalAction.call(this); }

  get shippingCost(): number { return shippingCostAction.call(this); }

  get paymentMethodLabel(): string { return paymentMethodLabelAction.call(this); }

  formatPrice(value: number, sourceCurrency?: Currency): string { return formatPriceAction.call(this, value, sourceCurrency); }

  showToast(message: string): void { return showToastAction.call(this, message); }

  initialLanguage(): Language { return initialLanguageAction(); }

  initialCurrency(): Currency { return initialCurrencyAction(); }

  convertCurrency(value: number, source: Currency, target: Currency): number { return convertCurrencyAction.call(this, value, source, target); }

  syncAuthenticatedUser(): void { return syncAuthenticatedUserAction.call(this); }

  loadAccountData(): void { return loadAccountDataAction.call(this); }

  loadProfile(): void { return loadProfileAction.call(this); }

  prepareLayer(): void { return prepareLayerAction.call(this); }

  loadOrders(): void { return loadOrdersAction.call(this); }

  loadOlderOrders(): void { return loadOlderOrdersAction.call(this); }

  apiError(_error: HttpErrorResponse, fallback: string): string { return apiErrorAction(_error, fallback); }

  handleUnauthorized(error: HttpErrorResponse, expectedSessionVersion?: number): void { return handleUnauthorizedAction.call(this, error, expectedSessionVersion); }

  refreshCatalog(): void { return refreshCatalogAction.call(this); }

  reconcileCatalogStore(): void { return reconcileCatalogStoreAction.call(this); }

  applyLanguage(language: Language): void { return applyLanguageAction.call(this, language); }
}
