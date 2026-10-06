import type { CartLine, Product } from './storefront.models';
import {
  cartProductCount,
  favoriteStorefrontProducts,
  marketplaceSellerCount,
  marketplaceStorefrontProducts,
  localizedProductBadge,
  pageStorefrontProducts,
  searchStorefrontProducts,
  visibleStorefrontProducts
} from './storefront-catalog.view';

function product(
  id: number,
  category: Product['category'],
  marketplace: boolean,
  seller: string,
  name = `Plant ${id}`,
  subtitle = 'Indoor plant'
): Product {
  return {
    id,
    slug: `plant-${id}`,
    name,
    subtitle,
    origin: null,
    dimensions: null,
    materials: null,
    careInstructions: null,
    category,
    price: 12,
    shippingWeightGrams: 500,
    stock: 5,
    sprite: 1,
    seller,
    marketplace,
    averageRating: 0,
    reviewCount: 0,
    currency: 'USD'
  };
}

describe('storefront catalog view rules', () => {
  const catalog = [
    product(1, 'plants', true, 'Green House', 'Monstera', 'Tropical plant'),
    product(2, 'home', false, 'Botanical Essence', 'Candle', 'Citrus scent'),
    product(3, 'care', true, 'Green House', 'Plant food', 'Care essentials'),
    product(4, 'plants', true, 'Fern Corner', 'Fern', 'Shade-loving plant'),
    product(5, 'home', true, 'Fern Corner', 'Pot', 'Ceramic home decor')
  ];

  it('limits the all-category home selection to four while preserving category results', () => {
    expect(visibleStorefrontProducts(catalog, 'all').map(({ id }) => id)).toEqual([1, 2, 3, 4]);
    expect(visibleStorefrontProducts(catalog, 'plants').map(({ id }) => id)).toEqual([1, 4]);
  });

  it('applies the marketplace page boundary before the selected category', () => {
    expect(pageStorefrontProducts(catalog, 'shop', 'all').map(({ id }) => id)).toEqual([1, 2, 3, 4, 5]);
    expect(pageStorefrontProducts(catalog, 'marketplace', 'all').map(({ id }) => id)).toEqual([1, 3, 4, 5]);
    expect(pageStorefrontProducts(catalog, 'marketplace', 'plants').map(({ id }) => id)).toEqual([1, 4]);
  });

  it('derives favorites and unique marketplace sellers from the public catalog', () => {
    expect(favoriteStorefrontProducts(catalog, new Set([2, 5])).map(({ id }) => id)).toEqual([2, 5]);
    expect(marketplaceStorefrontProducts(catalog).map(({ id }) => id)).toEqual([1, 3, 4, 5]);
    expect(marketplaceSellerCount(catalog)).toBe(2);
  });

  it('trims and case-folds search across names, subtitles and seller names', () => {
    expect(searchStorefrontProducts(catalog, '  MONSTERA ', 'en-US').map(({ id }) => id)).toEqual([1]);
    expect(searchStorefrontProducts(catalog, 'CITRUS', 'en-US').map(({ id }) => id)).toEqual([2]);
    expect(searchStorefrontProducts(catalog, 'fern corner', 'en-US').map(({ id }) => id)).toEqual([4, 5]);
    expect(searchStorefrontProducts(catalog, '   ', 'en-US')).toEqual([]);
  });

  it('counts all cart quantities rather than only distinct products', () => {
    const cart: CartLine[] = [
      { ...catalog[0], quantity: 2 },
      { ...catalog[1], quantity: 3 }
    ];
    expect(cartProductCount(cart)).toBe(5);
    expect(cartProductCount([])).toBe(0);
  });

  it('localizes legacy catalog badges while preserving custom seller content', () => {
    expect(localizedProductBadge('Mais querida', 'en')).toBe('Most loved');
    expect(localizedProductBadge('Novidade', 'fr')).toBe('Nouveau');
    expect(localizedProductBadge('Exclusivo', 'en')).toBe('Exclusive');
    expect(localizedProductBadge('Exclusive', 'fr')).toBe('Exclusif');
    expect(localizedProductBadge('Handmade in Ghent', 'fr')).toBe('Handmade in Ghent');
    expect(localizedProductBadge(null, 'en')).toBe('');
  });
});
