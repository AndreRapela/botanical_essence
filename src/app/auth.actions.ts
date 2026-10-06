import type { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import type { Router } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize } from 'rxjs';

import type { AuthService } from './core/auth.service';
import type { CommerceService } from './core/commerce.service';
import type { StoreService } from './core/store.service';
import type { AccountProfile, AuthMode, DemoAccount, Page } from './storefront.models';

/** The auth/account workflows depend on this explicit shell contract, not on the component class. */
export interface AuthActionsContext {
  auth: Pick<AuthService, 'login' | 'demoLogin' | 'register' | 'forgotPassword' | 'resetPassword' | 'markSignedOut' | 'verifyEmail' | 'logout' | 'replaceUser' | 'sessionVersion' | 'sessionChanged$'>;
  commerce: Pick<CommerceService, 'updateProfile'>;
  store: Pick<StoreService, 'toggleFavorite'>;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  router: Pick<Router, 'navigateByUrl'>;
  document: Pick<Document, 'getElementById'>;
  currentPage: Page;
  authLoading: boolean;
  authError: string;
  authSuccess: string;
  authActionUrl: string;
  verificationPassword: string;
  loginForm: { email: string; password: string };
  rememberMe: boolean;
  checkoutForm: { name: string; email: string };
  registerForm: { name: string; email: string; password: string; confirmPassword: string; role: 'customer' | 'seller'; storeName: string; city: string; region: string; country: string; acceptTerms: boolean };
  forgotEmail: string;
  passwordStrength: number;
  resetToken: string;
  resetForm: { password: string; confirmPassword: string };
  profileLoading: boolean;
  profileError: string;
  profileSuccess: string;
  profileForm: { name: string; bio: string; city: string; region: string; country: string };
  accountProfile: AccountProfile | null;
  copy(english: string, french: string): string;
  tr(key: string): string;
  apiError(error: HttpErrorResponse, fallback: string): string;
  handleUnauthorized(error: HttpErrorResponse, expectedSessionVersion?: number): void;
  clearSensitiveSessionData(): void;
  loadOrders(): void;
  loadAdminSellers(): void;
  loadSellerDashboard(): void;
  loadAccountData(): void;
  closeProductDetails(): void;
  closeLayers(): void;
  navigatePage(page: Page, authMode?: AuthMode): void;
  showToast(message: string): void;
}

export function toggleFavorite(this: AuthActionsContext, id: number): void {
    const added = this.store.toggleFavorite(id);
    this.showToast(this.tr(added ? 'toast.favoriteAdded' : 'toast.favoriteRemoved'));
  }

export function scrollTo(this: AuthActionsContext, id: string): void {
    this.closeLayers();
    if (this.currentPage !== 'home') {
      void this.router.navigateByUrl('/').then(() => {
        setTimeout(() => this.document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
      });
      return;
    }
    this.document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

export function switchAuth(this: AuthActionsContext, mode: AuthMode): void {
    this.verificationPassword = '';
    this.navigatePage('account', mode);
  }

export function submitLogin(this: AuthActionsContext): void {
    this.authError = '';
    this.authSuccess = '';
    if (!this.loginForm.email || !this.loginForm.password) {
      this.authError = this.copy('Enter your email and password.', 'Saisissez votre e-mail et votre mot de passe.');
      return;
    }
    this.authLoading = true;
    this.auth.login({ ...this.loginForm, rememberMe: this.rememberMe }).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: ({ user }) => {
        this.authLoading = false;
        this.loginForm.email = user.email;
        this.loginForm.password = '';
        this.checkoutForm.name = user.name;
        this.checkoutForm.email = user.email;
        this.authSuccess = this.copy(`Welcome back, ${user.name.split(' ')[0]}.`, `Heureux de vous revoir, ${user.name.split(' ')[0]}.`);
        void this.router.navigateByUrl('/account');
        this.loadOrders();
      },
      error: (error: HttpErrorResponse) => {
        this.authLoading = false;
        this.authError = this.apiError(error, this.copy('We could not sign you in. Please try again.', 'Connexion impossible. Veuillez réessayer.'));
      }
    });
  }

export function loginAsDemo(this: AuthActionsContext, role: DemoAccount['role']): void {
    if (this.authLoading) return;
    this.authLoading = true;
    this.authError = '';
    this.authSuccess = '';
    this.auth.demoLogin(role).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: ({ user }) => {
        this.authLoading = false;
        this.loginForm.email = user.email;
        this.checkoutForm.name = user.name;
        this.checkoutForm.email = user.email;
        const destination = user.role === 'admin' ? '/admin' : user.role === 'seller' ? '/seller' : '/account';
        void this.router.navigateByUrl(destination);
        if (user.role === 'admin') this.loadAdminSellers();
        else if (user.role === 'seller') this.loadSellerDashboard();
        else this.loadAccountData();
      },
      error: (error: HttpErrorResponse) => {
        this.authLoading = false;
        this.authError = this.apiError(error, this.copy('Demo access is unavailable.', 'L’accès de démonstration est indisponible.'));
      }
    });
  }

export function saveProfile(this: AuthActionsContext): void {
    if (this.profileLoading) return;
    this.profileLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.profileError = '';
    this.profileSuccess = '';
    this.commerce.updateProfile(this.profileForm).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: ({ profile, user }) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.profileLoading = false;
        this.accountProfile = profile;
        this.auth.replaceUser(user);
        this.checkoutForm.name = user.name;
        this.profileSuccess = this.copy('Profile saved.', 'Profil enregistré.');
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.profileLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.profileError = this.apiError(error, this.copy('We could not save your profile.', 'Impossible d’enregistrer votre profil.'));
      }
    });
  }

export function submitRegister(this: AuthActionsContext): void {
    this.authError = '';
    this.authSuccess = '';
    const form = this.registerForm;
    if (!form.name || !form.email || !form.password || !form.confirmPassword) {
      this.authError = this.copy('Complete all required fields.', 'Complétez tous les champs obligatoires.');
      return;
    }
    if (this.passwordStrength < 4) {
      this.authError = this.copy('Use 12+ characters with uppercase, lowercase, a number and a symbol.', 'Utilisez 12+ caractères avec majuscule, minuscule, chiffre et symbole.');
      return;
    }
    if (form.password !== form.confirmPassword) {
      this.authError = this.copy('The passwords do not match.', 'Les mots de passe ne correspondent pas.');
      return;
    }
    if (!form.acceptTerms) {
      this.authError = this.copy('Accept the terms to create your account.', 'Acceptez les conditions pour créer votre compte.');
      return;
    }
    if (form.role === 'seller' && (!form.storeName || !form.city || !form.region || !form.country)) {
      this.authError = this.copy('Complete the store name, city, region and country.', 'Complétez le nom de la boutique, la ville, la région et le pays.');
      return;
    }
    this.authLoading = true;
    const registration: Record<string, unknown> = {
      name: form.name,
      email: form.email,
      password: form.password,
      role: form.role,
      acceptTerms: form.acceptTerms
    };
    if (form.role === 'seller') {
      Object.assign(registration, {
        storeName: form.storeName,
        city: form.city,
        region: form.region,
        country: form.country
      });
    }
    this.auth.register(registration).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: (response) => {
        this.authLoading = false;
        this.loginForm.email = form.email;
        this.registerForm.password = '';
        this.registerForm.confirmPassword = '';
        this.authActionUrl = response.developmentVerificationUrl ?? '';
        this.authSuccess = this.copy('Check your email to confirm the account before signing in.', 'Consultez votre e-mail pour confirmer le compte avant de vous connecter.');
      },
      error: (error: HttpErrorResponse) => {
        this.authLoading = false;
        this.authError = this.apiError(error, this.copy('We could not create your account. Please try again.', 'Création du compte impossible. Veuillez réessayer.'));
      }
    });
  }

export function submitForgotPassword(this: AuthActionsContext): void {
    this.authError = '';
    this.authSuccess = '';
    if (!this.forgotEmail) {
      this.authError = this.copy('Enter your email address.', 'Saisissez votre adresse e-mail.');
      return;
    }
    this.authLoading = true;
    this.auth.forgotPassword(this.forgotEmail).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: (response) => {
        this.authLoading = false;
        this.authActionUrl = response.developmentResetUrl ?? '';
        this.authSuccess = this.copy('If this email is registered, recovery instructions are on their way.', 'Si cet e-mail est enregistré, les instructions de récupération ont été envoyées.');
      },
      error: (error: HttpErrorResponse) => {
        this.authLoading = false;
        this.authError = this.apiError(error, this.copy('We could not send the recovery email. Try again.', 'Nous n’avons pas pu envoyer l’e-mail. Réessayez.'));
      }
    });
  }

export function submitResetPassword(this: AuthActionsContext): void {
    this.authError = '';
    this.authSuccess = '';
    if (!this.resetToken) {
      this.authError = this.copy('This recovery link is incomplete.', 'Ce lien de récupération est incomplet.');
      return;
    }
    if (this.passwordStrength < 4 || this.resetForm.password !== this.resetForm.confirmPassword) {
      this.authError = this.copy('Use a strong matching password.', 'Utilisez un mot de passe fort et identique.');
      return;
    }
    this.authLoading = true;
    this.auth.resetPassword(this.resetToken, this.resetForm.password).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: () => {
        this.authLoading = false;
        this.auth.markSignedOut();
        this.clearSensitiveSessionData();
        this.resetForm = { password: '', confirmPassword: '' };
        void this.router.navigateByUrl('/login').then(() => {
          this.authSuccess = this.copy('Password changed. You can sign in now.', 'Mot de passe modifié. Vous pouvez vous connecter.');
        });
      },
      error: (error: HttpErrorResponse) => {
        this.authLoading = false;
        this.authError = this.apiError(error, this.copy('We could not reset the password.', 'Impossible de modifier le mot de passe.'));
      }
    });
  }

export function submitVerifyEmail(this: AuthActionsContext): void {
    this.authError = '';
    this.authSuccess = '';
    if (!this.resetToken || !this.verificationPassword) {
      this.authError = this.copy('Enter the password created during registration.', 'Saisissez le mot de passe créé lors de l’inscription.');
      return;
    }
    this.authLoading = true;
    this.auth.verifyEmail(this.resetToken, this.verificationPassword).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: () => {
        this.authLoading = false;
        this.verificationPassword = '';
        this.resetToken = '';
        void this.router.navigateByUrl('/login').then(() => {
          this.authSuccess = this.copy('Email confirmed. You can sign in now.', 'E-mail confirmé. Vous pouvez vous connecter.');
        });
      },
      error: (error: HttpErrorResponse) => {
        this.authLoading = false;
        this.verificationPassword = '';
        this.authError = this.apiError(error, this.copy('This verification link or password is invalid.', 'Ce lien ou mot de passe est invalide.'));
      }
    });
  }

export function openAuthAction(this: AuthActionsContext): void {
    if (!this.authActionUrl) return;
    const target = new URL(this.authActionUrl, window.location.origin);
    if (target.origin === window.location.origin) {
      void this.router.navigateByUrl(`${target.pathname}${target.search}${target.hash}`);
    }
  }

export function logout(this: AuthActionsContext): void {
    this.authLoading = true;
    this.auth.logout().pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: () => {
        this.authLoading = false;
        this.clearSensitiveSessionData();
        this.closeProductDetails();
        void this.router.navigateByUrl('/login').then(() => {
          this.authSuccess = this.copy('You have signed out safely.', 'Vous êtes maintenant déconnecté.');
        });
      },
      error: (error: HttpErrorResponse) => {
        this.authLoading = false;
        this.authError = this.apiError(error, this.copy('Sign out failed.', 'La déconnexion a échoué.'));
      }
    });
  }
