import { Injectable, computed, signal } from '@angular/core';

import { CartLine, Product } from '../storefront.models';

@Injectable({ providedIn: 'root' })
export class StoreService {
  private restoredCart = this.readStoredCart();
  private readonly cartState = signal<CartLine[]>([]);
  private readonly favoriteIds = signal<number[]>(this.readNumberArray('botanical-favorites'));

  readonly cart = computed(() => this.cartState());
  readonly favorites = computed(() => new Set(this.favoriteIds()));

  add(product: Product): void {
    if (product.stock < 1) return;
    const existing = this.cartState().find((line) => line.id === product.id);
    const next = existing
      ? this.cartState().map((line) => line.id === product.id ? { ...line, quantity: Math.min(line.quantity + 1, product.stock, 20) } : line)
      : [...this.cartState(), { ...product, quantity: 1 }];
    this.setCart(next);
  }

  changeQuantity(id: number, amount: number): void {
    if (!Number.isInteger(id) || id < 1 || !Number.isInteger(amount)) return;
    const next = this.cartState()
      .map((line) => line.id === id ? { ...line, quantity: Math.min(line.stock, line.quantity + amount, 20) } : line)
      .filter((line) => line.quantity > 0);
    this.setCart(next);
  }

  clearCart(): void {
    this.restoredCart = [];
    this.setCart([]);
  }

  toggleFavorite(id: number): boolean {
    if (!Number.isInteger(id) || id < 1) return false;
    const next = new Set(this.favoriteIds());
    next.has(id) ? next.delete(id) : next.add(id);
    const values = [...next];
    this.favoriteIds.set(values);
    this.write('botanical-favorites', values);
    return next.has(id);
  }

  reconcile(products: Product[], completeCatalog = true): void {
    const byId = new Map(products.map((product) => [product.id, product]));
    const quantities = new Map(this.restoredCart.map((line) => [line.id, line.quantity]));
    for (const line of this.cartState()) quantities.set(line.id, line.quantity);
    const next = [...quantities].flatMap(([id, quantity]) => {
      const current = byId.get(id);
      return current && current.stock > 0 ? [{ ...current, quantity: Math.min(quantity, current.stock, 20) }] : [];
    });
    this.restoredCart = completeCatalog
      ? []
      : [...quantities].filter(([id]) => !byId.has(id)).map(([id, quantity]) => ({ id, quantity }));
    this.setCart(next);
    if (completeCatalog) {
      const validIds = new Set(products.map((product) => product.id));
      const favorites = this.favoriteIds().filter((id) => validIds.has(id));
      this.favoriteIds.set(favorites);
      this.write('botanical-favorites', favorites);
    }
  }

  private setCart(cart: CartLine[]): void {
    this.cartState.set(cart);
    const persisted = new Map(this.restoredCart.map((line) => [line.id, line.quantity]));
    for (const { id, quantity } of cart) persisted.set(id, quantity);
    this.write('botanical-cart', [...persisted].slice(0, 50).map(([id, quantity]) => ({ id, quantity })));
  }

  private readStoredCart(): Array<{ id: number; quantity: number }> {
    try {
      const parsed: unknown = JSON.parse(window.localStorage.getItem('botanical-cart') ?? '[]');
      if (!Array.isArray(parsed)) return [];
      return parsed.slice(0, 50).flatMap((line): Array<{ id: number; quantity: number }> => {
        if (!line || typeof line !== 'object') return [];
        const value = line as Partial<CartLine>;
        return Number.isInteger(value.id) && Number(value.id) > 0
          && Number.isInteger(value.quantity) && Number(value.quantity) > 0 && Number(value.quantity) <= 20
          ? [{ id: Number(value.id), quantity: Number(value.quantity) }]
          : [];
      });
    } catch {
      return [];
    }
  }

  private readNumberArray(key: string): number[] {
    try {
      const parsed: unknown = JSON.parse(window.localStorage.getItem(key) ?? '[]');
      return Array.isArray(parsed)
        ? [...new Set(parsed.filter((value): value is number => Number.isInteger(value) && value > 0))].slice(0, 200)
        : [];
    } catch {
      return [];
    }
  }

  private write(key: string, value: unknown): void {
    try { window.localStorage.setItem(key, JSON.stringify(value)); } catch { /* Persistence is optional. */ }
  }
}
