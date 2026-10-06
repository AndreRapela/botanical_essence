import type { CartLine, Category, Language, LocalizedText, Page, Product } from './storefront.models';

const PRODUCT_BADGES: Readonly<Record<string, LocalizedText>> = {
  'most loved': { en: 'Most loved', fr: 'Coup de cœur' },
  new: { en: 'New', fr: 'Nouveau' },
  exclusive: { en: 'Exclusive', fr: 'Exclusif' }
};
const LEGACY_BADGES: Readonly<Record<string, string>> = {
  'mais querida': 'most loved', 'mais amado': 'most loved', 'coup de cœur': 'most loved',
  novidade: 'new', nouveau: 'new', exclusivo: 'exclusive', exclusif: 'exclusive'
};

export function localizedProductBadge(badge: string | null | undefined, language: Language): string {
  if (!badge) return '';
  const normalized = badge.trim().toLowerCase();
  return PRODUCT_BADGES[LEGACY_BADGES[normalized] ?? normalized]?.[language] ?? badge;
}

export function visibleStorefrontProducts(products: Product[], category: Category): Product[] {
  return category === 'all'
    ? products.slice(0, 4)
    : products.filter((product) => product.category === category);
}

export function pageStorefrontProducts(products: Product[], page: Page, category: Category): Product[] {
  const pageProducts = page === 'marketplace'
    ? products.filter((product) => product.marketplace)
    : products;
  return category === 'all'
    ? pageProducts
    : pageProducts.filter((product) => product.category === category);
}

export function favoriteStorefrontProducts(products: Product[], favoriteIds: Set<number>): Product[] {
  return products.filter((product) => favoriteIds.has(product.id));
}

export function marketplaceStorefrontProducts(products: Product[]): Product[] {
  return products.filter((product) => product.marketplace);
}

export function marketplaceSellerCount(products: Product[]): number {
  return new Set(marketplaceStorefrontProducts(products).map((product) => product.seller)).size;
}

export function searchStorefrontProducts(products: Product[], searchTerm: string, locale: string): Product[] {
  const term = searchTerm.trim().toLocaleLowerCase(locale);
  return term
    ? products.filter((product) => `${product.name} ${product.subtitle} ${product.seller}`.toLocaleLowerCase(locale).includes(term))
    : [];
}

export function cartProductCount(cart: CartLine[]): number {
  return cart.reduce((total, item) => total + item.quantity, 0);
}
