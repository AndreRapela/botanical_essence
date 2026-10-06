import { CommonModule } from '@angular/common';
import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { Category, Currency, Language, ManagedProduct, MarketplaceStore, ProductCategory } from './storefront.models';

export interface SellerStoreForm extends Record<string, unknown> {
  displayName: string;
  bio: string;
  city: string;
  region: string;
  country: string;
}

export interface SellerProductForm extends Record<string, unknown> {
  id: number | null;
  name: string;
  description: string;
  origin: string;
  dimensions: string;
  materials: string;
  careInstructions: string;
  category: ProductCategory;
  price: number;
  shippingWeightGrams: number;
  stock: number;
  sprite: number;
  badge: string;
  status: 'draft' | 'active';
}

@Component({
  selector: 'app-seller-studio',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './seller-studio.component.html',
  styleUrl: './seller-studio.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class SellerStudioComponent {
  @Input() store: MarketplaceStore | null = null;
  @Input() products: ManagedProduct[] = [];
  @Input() loading = false;
  @Input() error = '';
  @Input() success = '';
  @Input() language: Language = 'en';
  @Input() storeForm: SellerStoreForm = { displayName: '', bio: '', city: '', region: '', country: 'Belgium' };
  @Input() productForm: SellerProductForm = {
    id: null, name: '', description: '', origin: '', dimensions: '', materials: '', careInstructions: '',
    category: 'plants', price: 1, shippingWeightGrams: 1000, stock: 0, sprite: 0, badge: '', status: 'draft'
  };
  @Input() priceFormatter: (value: number, sourceCurrency?: Currency) => string = (value, currency = 'USD') =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(value);
  @Input() categoryLabel: (category: Category) => string = (category) => category;

  @Output() readonly saveStore = new EventEmitter<void>();
  @Output() readonly saveProduct = new EventEmitter<void>();
  @Output() readonly editProduct = new EventEmitter<ManagedProduct>();
  @Output() readonly archiveProduct = new EventEmitter<ManagedProduct>();
  @Output() readonly resetProductForm = new EventEmitter<void>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }

  isUnapproved(): boolean {
    return !!this.store && !this.store.verified;
  }

  submitProduct(): void {
    if (this.isUnapproved()) this.productForm.status = 'draft';
    this.saveProduct.emit();
  }
}
