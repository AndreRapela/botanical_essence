import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { STOREFRONT_TRANSLATIONS } from './i18n/storefront-translations';
import { Language, LocalizedText, Product } from './storefront.models';
import { localizedProductBadge } from './storefront-catalog.view';

@Component({
  selector: 'app-product-card',
  standalone: true,
  templateUrl: './product-card.component.html',
  styleUrl: './product-card.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class ProductCardComponent {
  @Input({ required: true }) product!: Product;
  @Input({ required: true }) displayPrice = '';
  @Input() language: Language = 'en';
  @Input() favorite = false;
  @Input() showBadges = true;

  @Output() readonly open = new EventEmitter<Product>();
  @Output() readonly favoriteToggle = new EventEmitter<number>();
  @Output() readonly add = new EventEmitter<Product>();

  private readonly translations: Readonly<Record<string, LocalizedText>> = STOREFRONT_TRANSLATIONS;

  translate(key: string): string {
    return this.translations[key]?.[this.language] ?? key;
  }

  addLabel(): string {
    return this.translate('product.add').replace('{name}', this.product.name);
  }

  badgeLabel(): string {
    return localizedProductBadge(this.product.badge, this.language);
  }

  openLabel(): string {
    const prefix = this.language === 'fr' ? 'Ouvrir la fiche de ' : 'Open product page for ';
    return `${prefix}${this.product.name}`;
  }

  ratingLabel(): string {
    return this.language === 'fr' ? 'Voir les avis et les détails' : 'View ratings and product details';
  }

  ratingSummary(): string {
    return this.product.reviewCount
      ? `${this.product.averageRating} · ${this.product.reviewCount}`
      : this.language === 'fr' ? 'Nouveau · aucun avis' : 'New · no reviews';
  }

  ratingStars(): string {
    const filled = Math.max(0, Math.min(5, Math.round(this.product.averageRating)));
    return '★'.repeat(filled) + '☆'.repeat(5 - filled);
  }

  openProduct(): void {
    this.open.emit(this.product);
  }

  toggleFavorite(): void {
    this.favoriteToggle.emit(this.product.id);
  }

  addToCart(): void {
    this.add.emit(this.product);
  }
}
