import { CommonModule } from '@angular/common';
import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { AdminProduct, AdminReview, AdminSeller, AdminUser, Language, ProductCategory } from './storefront.models';
import { ratingStars } from './product.actions';

export interface AdminUserForm {
  [key: string]: unknown;
  id: number;
  name: string;
  email: string;
  role: AdminUser['role'];
  status: AdminUser['status'];
  emailVerified: boolean;
}

export interface AdminProductForm {
  [key: string]: unknown;
  id: number;
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
  status: 'draft' | 'active' | 'archived';
  marketplace: boolean;
  featured: boolean;
}

export interface AdminStoreForm {
  [key: string]: unknown;
  id: number;
  displayName: string;
  bio: string;
  city: string;
  region: string;
  country: string;
}

export type AdminReviewStatus = AdminReview['status'];

@Component({
  selector: 'app-admin-catalog-management',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './admin-catalog-management.component.html',
  styleUrl: './admin-catalog-management.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class AdminCatalogManagementComponent {
  @Input() users: AdminUser[] = [];
  @Input() usersTotal = 0;
  @Input() products: AdminProduct[] = [];
  @Input() productsTotal = 0;
  @Input() reviews: AdminReview[] = [];
  @Input() reviewsTotal = 0;
  @Input() sellers: AdminSeller[] = [];
  @Input() sellersTotal = 0;
  @Input() sellersHasMore = false;
  @Input() overviewHasMore = false;
  @Input() loading = false;
  @Input() language: Language = 'en';
  @Input() editingUser: AdminUser | null = null;
  @Input() editingProduct: AdminProduct | null = null;
  @Input() editingStore: AdminSeller | null = null;
  @Input() userForm: AdminUserForm = { id: 0, name: '', email: '', role: 'customer', status: 'active', emailVerified: true };
  @Input() productForm: AdminProductForm = {
    id: 0, name: '', description: '', origin: '', dimensions: '', materials: '', careInstructions: '',
    category: 'plants', price: 1, shippingWeightGrams: 1000, stock: 0, sprite: 0, badge: '',
    status: 'draft', marketplace: true, featured: false
  };
  @Input() storeForm: AdminStoreForm = { id: 0, displayName: '', bio: '', city: '', region: '', country: '' };
  @Input() priceFormatter: (value: number, sourceCurrency?: 'USD' | 'EUR') => string = (value, currency = 'USD') =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(value);

  @Output() readonly editUser = new EventEmitter<AdminUser>();
  @Output() readonly saveUser = new EventEmitter<void>();
  @Output() readonly cancelUserEdit = new EventEmitter<void>();
  @Output() readonly editProduct = new EventEmitter<AdminProduct>();
  @Output() readonly saveProduct = new EventEmitter<void>();
  @Output() readonly cancelProductEdit = new EventEmitter<void>();
  @Output() readonly editStore = new EventEmitter<AdminSeller>();
  @Output() readonly saveStore = new EventEmitter<void>();
  @Output() readonly cancelStoreEdit = new EventEmitter<void>();
  @Output() readonly moderateReview = new EventEmitter<{ review: AdminReview; status: AdminReviewStatus }>();
  @Output() readonly verifySeller = new EventEmitter<{ seller: AdminSeller; verified: boolean }>();
  @Output() readonly loadMoreOverview = new EventEmitter<void>();
  @Output() readonly loadMoreSellers = new EventEmitter<void>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }

  categoryLabel(category: ProductCategory): string {
    const labels: Record<ProductCategory, { en: string; fr: string }> = {
      plants: { en: 'Plants', fr: 'Plantes' },
      home: { en: 'Home & scents', fr: 'Maison & parfums' },
      care: { en: 'Plant care', fr: 'Soins' }
    };
    return this.language === 'fr' ? labels[category].fr : labels[category].en;
  }

  ratingStars(rating: number): string {
    return ratingStars(rating);
  }
}
