import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { AuthMode, DemoAccount, Language } from './storefront.models';

export interface LoginFormState {
  email: string;
  password: string;
}

export interface RegisterFormState {
  name: string;
  email: string;
  password: string;
  confirmPassword: string;
  role: 'customer' | 'seller';
  storeName: string;
  city: string;
  region: string;
  country: string;
  acceptTerms: boolean;
}

export interface ResetPasswordFormState {
  password: string;
  confirmPassword: string;
}

@Component({
  selector: 'app-auth-form',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './auth-form.component.html',
  styleUrl: './auth-form.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class AuthFormComponent {
  @Input({ required: true }) language!: Language;
  @Input({ required: true }) authMode!: AuthMode;
  @Input({ required: true }) authLoading = false;
  @Input() authError = '';
  @Input() authSuccess = '';
  @Input() authActionUrl = '';
  @Input() demoAccounts: DemoAccount[] = [];
  @Input() passwordStrength = 0;
  @Input({ required: true }) showPassword = false;
  @Input({ required: true }) rememberMe = true;
  @Input({ required: true }) loginForm!: LoginFormState;
  @Input({ required: true }) registerForm!: RegisterFormState;
  @Input({ required: true }) forgotEmail = '';
  @Input({ required: true }) resetForm!: ResetPasswordFormState;
  @Input({ required: true }) verificationPassword = '';

  @Output() readonly navigateHome = new EventEmitter<void>();
  @Output() readonly switchAuth = new EventEmitter<AuthMode>();
  @Output() readonly submitLogin = new EventEmitter<void>();
  @Output() readonly loginAsDemo = new EventEmitter<DemoAccount['role']>();
  @Output() readonly submitRegister = new EventEmitter<void>();
  @Output() readonly submitForgotPassword = new EventEmitter<void>();
  @Output() readonly submitResetPassword = new EventEmitter<void>();
  @Output() readonly submitVerifyEmail = new EventEmitter<void>();
  @Output() readonly openAuthAction = new EventEmitter<void>();
  @Output() readonly showPasswordChange = new EventEmitter<boolean>();
  @Output() readonly rememberMeChange = new EventEmitter<boolean>();
  @Output() readonly forgotEmailChange = new EventEmitter<string>();
  @Output() readonly verificationPasswordChange = new EventEmitter<string>();

  copy(en: string, fr: string): string {
    return this.language === 'fr' ? fr : en;
  }
}
