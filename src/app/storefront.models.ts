export type Language = 'en' | 'fr';
export type Currency = 'USD' | 'EUR';
export type Category = 'all' | 'plants' | 'home' | 'care';
export type ProductCategory = Exclude<Category, 'all'>;
export type Page = 'home' | 'shop' | 'marketplace' | 'product' | 'essence' | 'favorites' | 'account' | 'seller' | 'admin' | 'checkout' | 'contact' | 'deliveries' | 'returns' | 'terms' | 'privacy';
export type AuthMode = 'login' | 'register' | 'forgot' | 'reset' | 'verify';

export interface LocalizedText {
  en: string;
  fr: string;
}

export interface ContactMessageInput {
  name: string;
  email: string;
  subject: 'order' | 'care' | 'seller' | 'other';
  message: string;
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: 'customer' | 'seller' | 'admin';
  emailVerifiedAt: string | null;
}

export interface DemoAccount {
  role: 'admin' | 'customer' | 'seller';
  label: string;
}

export interface AccountProfile {
  name: string;
  email: string;
  bio: string | null;
  city: string | null;
  region: string | null;
  country: string | null;
}

export interface Product {
  id: number;
  slug: string;
  name: string;
  subtitle: string;
  origin: string | null;
  dimensions: string | null;
  materials: string | null;
  careInstructions: string | null;
  category: ProductCategory;
  price: number;
  shippingWeightGrams: number;
  stock: number;
  sprite: number;
  badge?: string | null;
  seller: string;
  marketplace: boolean;
  averageRating: number;
  reviewCount: number;
  currency: 'USD';
  testPrice?: boolean;
}

export interface CartLine extends Product {
  quantity: number;
}

export interface OrderSummary {
  number: string;
  status: string;
  paymentStatus: string;
  subtotal: number;
  shipping: number;
  total: number;
  currency: Currency;
  usdPerEur?: number | null;
  fxRateDate?: string | null;
  fxRateSource?: string | null;
  shippingTariffReviewedAt?: string | null;
  shippingTariffValidThrough?: string | null;
  refundedTotalCents?: number;
  testPayment?: boolean;
  paymentInstructions?: string;
  reservationExpiresAt?: string | null;
  createdAt?: string;
  withdrawalRequestId?: number | null;
  withdrawalRequestNumber?: string | null;
  withdrawalStatus?: string | null;
  withdrawalType?: 'withdrawal' | 'problem' | null;
}

export interface AdminWithdrawalRequest {
  id: number;
  requestNumber: string;
  requestType: 'withdrawal' | 'problem';
  status: 'submitted' | 'reviewing' | 'instructions_sent' | 'return_received' | 'refund_pending' | 'refund_failed' | 'refunded' | 'not_eligible';
  customerMessage: string | null;
  deliveryReceivedDate: string | null;
  adminNote: string | null;
  createdAt: string;
  orderNumber: string;
  orderStatus: string;
  paymentStatus: string;
  paymentProvider: string | null;
  refundAttempt: number;
  refundOutcome: AdminOrder['refundOutcome'];
  refundErrorCode: string | null;
  refundErrorMessage: string | null;
  orderItems: string;
  total: number;
  currency: Currency;
  customerName: string;
  email: string;
}

export interface AdminOrder {
  id: number;
  number: string;
  customerName: string;
  email: string;
  status: string;
  paymentStatus: string;
  paymentMethod: string;
  paymentProvider: string | null;
  testPayment: boolean;
  paymentSessionAttemptedAt: string | null;
  paymentSessionUnconfirmed: boolean;
  paymentReconciliationRequired: boolean;
  refundId: string | null;
  refundAttempt: number;
  refundedTotalCents: number;
  refundAttemptIsPartial: boolean;
  refundOutcome: 'none' | 'pending' | 'unknown' | 'definitive_failure' | 'succeeded' | 'manual_refunded' | 'manual_not_refunded';
  refundErrorCode: string | null;
  refundErrorMessage: string | null;
  refundErrorHttpStatus: number | null;
  subtotal: number;
  shipping: number;
  total: number;
  currency: Currency;
  createdAt: string;
  items: { name: string; quantity: number }[];
}

export interface AdminPaymentDispute {
  id: string;
  orderId: number | null;
  orderNumber: string | null;
  customerName: string | null;
  email: string | null;
  paymentIntentId: string | null;
  chargeId: string | null;
  status: string;
  reason: string | null;
  amountCents: number;
  currency: Currency;
  evidenceDueAt: string | null;
  livemode: boolean;
  createdAt: string | null;
  syncError: string | null;
}

export interface AdminPaymentNotification {
  id: number;
  orderId: number;
  orderNumber: string;
  customerName: string;
  email: string;
  notificationType: string;
  referenceId: string;
  status: 'pending' | 'processing' | 'failed';
  attempts: number;
  consecutiveFailures: number;
  createdAt: string;
  availableAt: string;
  lockedUntil: string | null;
  lastError: string | null;
  retryAvailable: boolean;
}

export interface ShippingQuote {
  id: string;
  label: string;
  amount: number;
  amountCents: number;
  currency: 'EUR';
  estimatedDays: string;
  description: string;
  kind: 'pickup' | 'home' | 'home-guaranteed' | 'international';
}

export interface ShippingQuoteResponse {
  carrier: 'bpost';
  quoteType: 'estimate';
  referenceYear: number;
  source: string;
  tariffVersion: string;
  tariffReviewedAt: string;
  tariffValidThrough: string;
  tariffBasis: string;
  tariffReference: string;
  liveCarrierQuote: false;
  contractualRate: false;
  labelCreationAvailable: false;
  trackingAvailable: false;
  currency: 'EUR';
  destination: { countryCode: string; postalCode: string; zone: 'belgium' | 'neighbour' | 'eu' };
  parcel: { weightGrams: number; weightKilograms: number; packagingWeightGrams: number };
  quotes: ShippingQuote[];
}

export interface MarketplaceStore {
  id: number;
  displayName: string;
  slug: string;
  bio: string | null;
  city: string;
  region: string;
  country: string;
  rating: number;
  verified: boolean;
}

export interface ManagedProduct {
  id: number;
  name: string;
  description: string;
  origin: string | null;
  dimensions: string | null;
  materials: string | null;
  careInstructions: string | null;
  category: ProductCategory;
  price: number;
  shippingWeightGrams: number;
  stock: number;
  sprite: number;
  badge: string | null;
  status: 'draft' | 'active';
  updatedAt: string;
}

export interface AdminSeller {
  id: number;
  displayName: string;
  bio: string | null;
  city: string;
  region: string;
  country: string;
  verified: boolean;
  ownerName: string;
  email: string;
  accountStatus: 'pending' | 'active' | 'disabled';
  productCount: number;
}

export interface AdminUser {
  id: number;
  name: string;
  email: string;
  role: 'customer' | 'seller' | 'admin';
  status: 'pending' | 'active' | 'disabled';
  isDemo: boolean;
  emailVerified: boolean;
  emailVerifiedAt: string | null;
  createdAt: string;
}

export interface AdminContactMessage {
  id: number;
  name: string;
  email: string;
  subject: 'order' | 'care' | 'seller' | 'other';
  message: string;
  status: 'new' | 'in_progress' | 'closed';
  createdAt: string;
}

export interface AdminProduct {
  id: number;
  name: string;
  description: string;
  origin: string | null;
  dimensions: string | null;
  materials: string | null;
  careInstructions: string | null;
  category: ProductCategory;
  price: number;
  shippingWeightGrams: number;
  stock: number;
  sprite: number;
  badge: string | null;
  status: 'draft' | 'active' | 'archived';
  marketplace: boolean;
  featured: boolean;
  sellerId: number;
  sellerName: string;
  updatedAt: string;
}

export interface ProductReview {
  id: number;
  rating: number;
  comment: string;
  authorName: string;
  verifiedPurchase: boolean;
  createdAt: string;
  updatedAt: string;
}

export interface OwnProductReview {
  id: number;
  rating: number;
  comment: string;
  status: 'visible' | 'hidden';
  createdAt: string;
  updatedAt: string;
}

export interface ProductReviewResponse {
  data: ProductReview[];
  summary: { average: number; count: number };
  page: number;
  limit: number;
  total: number;
  hasMore: boolean;
  canReview: boolean;
  myReview: OwnProductReview | null;
}

export interface AdminReview {
  id: number;
  productId: number;
  productName: string;
  authorName: string;
  authorEmail: string;
  rating: number;
  comment: string;
  status: 'visible' | 'hidden';
  createdAt: string;
  updatedAt: string;
}

export interface AdminOverviewPage {
  users: AdminUser[];
  products: AdminProduct[];
  reviews: AdminReview[];
  page: number;
  limit: number;
  usersTotal: number;
  usersHasMore: boolean;
  productsTotal: number;
  productsHasMore: boolean;
  reviewsTotal: number;
  reviewsHasMore: boolean;
}

export interface AdminAuditEntry {
  id: number;
  adminUserId: number | null;
  adminName: string | null;
  adminEmail: string | null;
  action: string;
  targetType: string;
  targetId: number;
  previousValue: string | null;
  newValue: string | null;
  createdAt: string;
}
