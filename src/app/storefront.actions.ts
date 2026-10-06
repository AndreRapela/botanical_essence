import { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize } from 'rxjs';

import type { CatalogService } from './core/catalog.service';
import type { CommerceService } from './core/commerce.service';
import type { StoreService } from './core/store.service';
import type { ContactFormData } from './information-pages.component';
import type { AuthMode, AuthUser, Currency, Language, LocalizedText, Page, Product } from './storefront.models';

interface ActionContext {
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  apiError(error: HttpErrorResponse, fallback: string): string;
  copy(english: string, french: string): string;
}

interface RequestActionContext extends ActionContext {
  destroyRef: DestroyRef;
}

export interface ContactActionContext extends RequestActionContext {
  commerce: Pick<CommerceService, 'contact'>;
  contactError: string;
  contactLoading: boolean;
  contactSent: boolean;
  contactForm: ContactFormData;
}

export interface NewsletterActionContext extends RequestActionContext {
  commerce: Pick<CommerceService, 'subscribe'>;
  newsletterError: string;
  newsletterLoading: boolean;
  newsletterSent: boolean;
  newsletterEmail: string;
  currentLanguage: Language;
}

export interface CurrencyConversionContext {
  catalog: Pick<CatalogService, 'currencyRates'>;
}

export interface PriceFormattingContext {
  currentCurrency: Currency;
  locale: string;
  convertCurrency(value: number, source: Currency, target: Currency): number;
  copy(english: string, french: string): string;
}

export interface ToastActionContext {
  toast: string;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
}

export interface CatalogRefreshContext extends RequestActionContext {
  catalog: Pick<CatalogService, 'reload'>;
  currentPage: Page;
  selectedProduct: Product | null;
  reconcileCatalogStore(): void;
  resolveProductPage(): void;
}

export interface CatalogReconciliationContext {
  catalog: Pick<CatalogService, 'loaded' | 'page' | 'pages' | 'products'>;
  store: Pick<StoreService, 'reconcile'>;
}

export interface PageTitleContext {
  document: Document;
  currentPage: Page;
  selectedProduct: Product | null;
  authMode: AuthMode;
  user: AuthUser | null;
}



export function submitContact(this: ContactActionContext): void {
    this.contactError = '';
    this.contactLoading = true;
    this.commerce.contact(this.contactForm).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: () => { this.contactLoading = false; this.contactSent = true; },
      error: (error: HttpErrorResponse) => {
        this.contactLoading = false;
        this.contactError = this.apiError(error, this.copy('We could not send your message.', 'Impossible d’envoyer votre message.'));
      }
    });
  }

export function submitNewsletter(this: NewsletterActionContext): void {
    this.newsletterError = '';
    this.newsletterLoading = true;
    this.commerce.subscribe(this.newsletterEmail, this.currentLanguage).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: () => { this.newsletterLoading = false; this.newsletterSent = true; },
      error: (error: HttpErrorResponse) => {
        this.newsletterLoading = false;
        this.newsletterError = this.apiError(error, this.copy('Subscription failed. Try again.', 'L’inscription a échoué. Réessayez.'));
      }
    });
  }

export function formatPrice(this: PriceFormattingContext, value: number, sourceCurrency?: Currency): string {
    const amount = sourceCurrency
      ? this.convertCurrency(value, sourceCurrency, this.currentCurrency)
      : value;
    if (!Number.isFinite(amount)) {
      return this.copy('Price unavailable until the exchange reference is restored.', 'Prix indisponible jusqu’au rétablissement du taux de référence.');
    }
    return amount.toLocaleString(this.locale, { style: 'currency', currency: this.currentCurrency });
  }

export function showToast(this: ToastActionContext, message: string): void {
    this.toast = message;
    window.setTimeout(() => {
      if (this.toast === message) this.toast = '';
      this.cdr.markForCheck();
    }, 2600);
  }

export function initialLanguage(): Language {
    try {
      return window.localStorage.getItem('botanical-language') === 'fr' ? 'fr' : 'en';
    } catch {
      return 'en';
    }
  }

export function initialCurrency(): Currency {
    try {
      return window.localStorage.getItem('botanical-currency') === 'USD' ? 'USD' : 'EUR';
    } catch {
      return 'EUR';
    }
  }

export function convertCurrency(this: CurrencyConversionContext, value: number, source: Currency, target: Currency): number {
    if (source === target) return value;
    const euroPerDollar = this.catalog.currencyRates().EUR;
    if (euroPerDollar === null || euroPerDollar <= 0) return Number.NaN;
    const valueInUsd = source === 'EUR' ? value / euroPerDollar : value;
    const converted = target === 'EUR' ? valueInUsd * euroPerDollar : valueInUsd;
    return Math.round((converted + Number.EPSILON) * 100) / 100;
  }

export function refreshCatalog(this: CatalogRefreshContext): void {
    this.catalog.reload().pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: () => {
        this.reconcileCatalogStore();
        if (this.currentPage === 'product') {
          this.selectedProduct = null;
          this.resolveProductPage();
        }
      },
      error: () => { /* The catalog exposes its own error state. */ }
    });
  }

export function reconcileCatalogStore(this: CatalogReconciliationContext): void {
    const completeCatalog = this.catalog.loaded() && this.catalog.page() >= this.catalog.pages();
    this.store.reconcile(this.catalog.products(), completeCatalog);
  }

export function applyLanguage(this: PageTitleContext, language: Language): void {
    this.document.documentElement.lang = language;
    this.document.querySelector('meta[name="description"]')?.setAttribute('content', language === 'fr'
      ? 'Plantes, essentiels botaniques et un marché de cultivateurs et créateurs indépendants.'
      : 'Plants, botanical essentials and a marketplace of independent growers and makers.');
    const pageTitles: Record<Page, LocalizedText> = {
      home: { en: 'Plants, nature and well-being', fr: 'Plantes, nature et bien-être' },
      shop: { en: 'The botanical shop', fr: 'La boutique botanique' }, marketplace: { en: 'Botanical marketplace', fr: 'Marché botanique' },
      product: { en: this.selectedProduct?.name ?? 'Product details', fr: this.selectedProduct?.name ?? 'Détails du produit' },
      essence: { en: 'Our essence', fr: 'Notre essence' }, favorites: { en: 'Your favorites', fr: 'Vos favoris' },
      account: {
        en: this.user ? 'Your account' : this.authMode === 'register' ? 'Create account' : this.authMode === 'forgot' ? 'Recover password' : 'Sign in',
        fr: this.user ? 'Votre compte' : this.authMode === 'register' ? 'Créer un compte' : this.authMode === 'forgot' ? 'Récupérer le mot de passe' : 'Connexion'
      },
      seller: { en: 'Seller studio', fr: 'Espace vendeur' },
      admin: { en: 'Marketplace moderation', fr: 'Modération du marché' },
      checkout: { en: 'Payment', fr: 'Paiement' }, contact: { en: 'Contact us', fr: 'Nous contacter' },
      deliveries: { en: 'Deliveries', fr: 'Livraisons' }, returns: { en: 'Returns', fr: 'Retours' },
      terms: { en: 'Terms of use', fr: 'Conditions d’utilisation' }, privacy: { en: 'Privacy policy', fr: 'Politique de confidentialité' }
    };
    this.document.title = `Botanical Essence — ${pageTitles[this.currentPage][language]}`;
  }
