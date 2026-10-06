import { Routes } from '@angular/router';

const loadStorefront = () => import('./app.component').then((module) => module.StorefrontComponent);

export const routes: Routes = [
  { path: '', loadComponent: loadStorefront, data: { page: 'home' } },
  { path: 'shop', loadComponent: loadStorefront, data: { page: 'shop' } },
  { path: 'marketplace', loadComponent: loadStorefront, data: { page: 'marketplace' } },
  { path: 'product/:slug', loadComponent: loadStorefront, data: { page: 'product' } },
  { path: 'our-essence', loadComponent: loadStorefront, data: { page: 'essence' } },
  { path: 'favorites', loadComponent: loadStorefront, data: { page: 'favorites' } },
  { path: 'account', loadComponent: loadStorefront, data: { page: 'account', authMode: 'login' } },
  { path: 'seller', loadComponent: loadStorefront, data: { page: 'seller' } },
  { path: 'admin', loadComponent: loadStorefront, data: { page: 'admin' } },
  { path: 'login', loadComponent: loadStorefront, data: { page: 'account', authMode: 'login' } },
  { path: 'create-account', loadComponent: loadStorefront, data: { page: 'account', authMode: 'register' } },
  { path: 'forgot-password', loadComponent: loadStorefront, data: { page: 'account', authMode: 'forgot' } },
  { path: 'reset-password', loadComponent: loadStorefront, data: { page: 'account', authMode: 'reset' } },
  { path: 'verify-email', loadComponent: loadStorefront, data: { page: 'account', authMode: 'verify' } },
  { path: 'payment', loadComponent: loadStorefront, data: { page: 'checkout' } },
  { path: 'checkout', redirectTo: 'payment', pathMatch: 'full' },
  { path: 'contact', loadComponent: loadStorefront, data: { page: 'contact' } },
  { path: 'deliveries', loadComponent: loadStorefront, data: { page: 'deliveries' } },
  { path: 'returns', loadComponent: loadStorefront, data: { page: 'returns' } },
  { path: 'terms', loadComponent: loadStorefront, data: { page: 'terms' } },
  { path: 'privacy', loadComponent: loadStorefront, data: { page: 'privacy' } },
  { path: '**', redirectTo: '' }
];
