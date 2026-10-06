import { HttpClient } from '@angular/common/http';
import { Injectable, signal } from '@angular/core';
import { Observable, map, of, tap } from 'rxjs';

import { Currency, Product } from '../storefront.models';

interface ProductResponse {
  data: Product[];
  meta: {
    page: number;
    limit: number;
    total: number;
    pages: number;
    baseCurrency: 'USD';
    currencyRates: Record<Currency, number | null>;
    currencyRateDate: string | null;
    currencyRateSource: 'ecb-daily-reference';
    currencyRateType: 'daily_reference';
    currencyRateToken: string | null;
    currencyRateStatus: 'current' | 'recent_cache_provider_unavailable' | 'refresh_in_progress' | 'unavailable';
    currencyRateCheckedAt: string | null;
  };
}

export type CurrencyRateMetadata = Pick<ProductResponse['meta'],
  'currencyRateDate' | 'currencyRateSource' | 'currencyRateType' | 'currencyRateToken' | 'currencyRateStatus' | 'currencyRateCheckedAt'>;

@Injectable({ providedIn: 'root' })
export class CatalogService {
  readonly products = signal<Product[]>([]);
  readonly loading = signal(false);
  readonly loadingMore = signal(false);
  readonly loaded = signal(false);
  readonly error = signal('');
  readonly page = signal(0);
  readonly pages = signal(1);
  readonly total = signal(0);
  readonly currencyRates = signal<Record<Currency, number | null>>({ USD: 1, EUR: null });
  readonly currencyRateMetadata = signal<CurrencyRateMetadata | null>(null);

  constructor(private readonly http: HttpClient) {}

  load(): Observable<ProductResponse | null> {
    if (this.loaded()) return of(null);
    this.loading.set(true);
    this.error.set('');
    return this.http.get<ProductResponse>('/api/products', { params: { page: 1, limit: 100 } }).pipe(
      tap({
        next: (response) => {
          this.products.set(response.data);
          this.applyMetadata(response);
          this.loaded.set(true);
          this.loading.set(false);
        },
        error: () => {
          this.error.set('catalog-unavailable');
          this.loading.set(false);
        }
      })
    );
  }

  loadMore(): Observable<ProductResponse | null> {
    if (!this.loaded() || this.loading() || this.loadingMore() || this.page() >= this.pages()) return of(null);
    this.loadingMore.set(true);
    this.error.set('');
    return this.http.get<ProductResponse>('/api/products', { params: { page: this.page() + 1, limit: 100 } }).pipe(
      tap({
        next: (response) => {
          const existing = new Set(this.products().map((product) => product.id));
          this.products.update((products) => [...products, ...response.data.filter((product) => !existing.has(product.id))]);
          this.applyMetadata(response);
          this.loadingMore.set(false);
        },
        error: () => {
          this.error.set('catalog-more-unavailable');
          this.loadingMore.set(false);
        }
      })
    );
  }

  loadProductBySlug(slug: string): Observable<Product | null> {
    return this.http.get<ProductResponse>('/api/products', { params: { slug, page: 1, limit: 1 } }).pipe(
      map((response) => {
        this.currencyRates.set(response.meta.currencyRates);
        this.currencyRateMetadata.set(this.rateMetadata(response));
        const product = response.data[0] ?? null;
        if (product && !this.products().some((item) => item.id === product.id)) {
          this.products.update((products) => [...products, product]);
        }
        return product;
      })
    );
  }

  reload(): Observable<ProductResponse | null> {
    this.loaded.set(false);
    return this.load();
  }

  private applyMetadata(response: ProductResponse): void {
    this.currencyRates.set(response.meta.currencyRates);
    this.currencyRateMetadata.set(this.rateMetadata(response));
    this.page.set(response.meta.page);
    this.pages.set(response.meta.pages);
    this.total.set(response.meta.total);
  }

  private rateMetadata(response: ProductResponse): CurrencyRateMetadata {
    const { currencyRateDate, currencyRateSource, currencyRateType, currencyRateToken, currencyRateStatus, currencyRateCheckedAt } = response.meta;
    return { currencyRateDate, currencyRateSource, currencyRateType, currencyRateToken, currencyRateStatus, currencyRateCheckedAt };
  }
}
