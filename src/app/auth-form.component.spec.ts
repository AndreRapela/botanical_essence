import { TestBed } from '@angular/core/testing';

import { AuthFormComponent } from './auth-form.component';
import { DemoAccount } from './storefront.models';

describe('AuthFormComponent', () => {
  beforeEach(() => TestBed.configureTestingModule({ imports: [AuthFormComponent] }));

  function create(authMode: 'login' | 'register' | 'forgot' | 'reset' | 'verify' = 'login', language: 'en' | 'fr' = 'en') {
    const fixture = TestBed.createComponent(AuthFormComponent);
    const component = fixture.componentInstance;
    component.language = language;
    component.authMode = authMode;
    component.loginForm = { email: '', password: '' };
    component.registerForm = {
      name: '', email: '', password: '', confirmPassword: '', role: 'customer',
      storeName: '', city: '', region: '', country: 'Belgium', acceptTerms: false
    };
    component.resetForm = { password: '', confirmPassword: '' };
    fixture.detectChanges();
    return { fixture, component };
  }

  it('renders translated login, emits submit and password visibility without taking ownership of auth', () => {
    const { fixture, component } = create('login', 'fr');
    const element: HTMLElement = fixture.nativeElement;
    const submitted = vi.fn();
    const visibility = vi.fn();
    const remember = vi.fn();
    component.submitLogin.subscribe(submitted);
    component.showPasswordChange.subscribe(visibility);
    component.rememberMeChange.subscribe(remember);
    fixture.componentRef.setInput('demoAccounts', [{ role: 'customer', label: 'Demo user' }, { role: 'admin', label: 'Demo admin' }] satisfies DemoAccount[]);
    fixture.componentRef.setInput('authError', 'Connexion refusée.');
    fixture.detectChanges();

    expect(element.textContent).toContain('Connexion');
    expect(element.querySelector('[role="alert"]')?.textContent).toContain('Connexion refusée.');
    expect(element.querySelectorAll('.demo-login button')).toHaveLength(2);
    (element.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    (element.querySelector('.password-field button') as HTMLButtonElement).click();
    const rememberInput = element.querySelector('[name="rememberMe"]') as HTMLInputElement;
    rememberInput.click();

    expect(submitted).toHaveBeenCalledOnce();
    expect(visibility).toHaveBeenCalledWith(true);
    expect(remember).toHaveBeenCalledWith(false);
    fixture.destroy();
  });

  it('keeps seller registration fields conditional and emits submit and auth-mode changes', () => {
    const { fixture, component } = create('register');
    const element: HTMLElement = fixture.nativeElement;
    const submitted = vi.fn();
    const switched = vi.fn();
    component.submitRegister.subscribe(submitted);
    component.switchAuth.subscribe(switched);

    expect(element.querySelector('[name="storeName"]')).toBeNull();
    (element.querySelectorAll('.role-picker button')[1] as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(component.registerForm.role).toBe('seller');
    expect(element.querySelector('[name="storeName"]')).not.toBeNull();
    expect(element.querySelector('[name="sellerRegion"]')?.getAttribute('minlength')).toBe('2');

    (element.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    (element.querySelector('.auth-tabs button:first-child') as HTMLButtonElement).click();
    expect(submitted).toHaveBeenCalledOnce();
    expect(switched).toHaveBeenCalledWith('login');
    fixture.destroy();
  });

  it('renders recovery and reset flows with independent submit events', () => {
    const recovery = create('forgot');
    const recoverySubmit = vi.fn();
    const recoveryMode = vi.fn();
    recovery.component.submitForgotPassword.subscribe(recoverySubmit);
    recovery.component.switchAuth.subscribe(recoveryMode);
    recovery.fixture.componentRef.setInput('authActionUrl', '/local-reset');
    recovery.fixture.detectChanges();
    expect((recovery.fixture.nativeElement as HTMLElement).textContent).toContain('RECOVER YOUR ACCESS');
    (recovery.fixture.nativeElement.querySelector('.dev-action') as HTMLButtonElement).click();
    (recovery.fixture.nativeElement.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    (recovery.fixture.nativeElement.querySelector('.auth-back.inline') as HTMLButtonElement).click();
    expect(recoverySubmit).toHaveBeenCalledOnce();
    expect(recoveryMode).toHaveBeenCalledWith('login');
    recovery.fixture.destroy();

    const reset = create('reset');
    const resetSubmit = vi.fn();
    reset.component.submitResetPassword.subscribe(resetSubmit);
    expect((reset.fixture.nativeElement as HTMLElement).querySelectorAll('input[type="password"]')).toHaveLength(2);
    (reset.fixture.nativeElement.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    expect(resetSubmit).toHaveBeenCalledOnce();
    reset.fixture.destroy();
  });

  it('requires the registration password for email confirmation and offers continuation after success', () => {
    const { fixture, component } = create('verify');
    const element: HTMLElement = fixture.nativeElement;
    const verified = vi.fn();
    const mode = vi.fn();
    component.submitVerifyEmail.subscribe(verified);
    component.switchAuth.subscribe(mode);
    expect(element.querySelector('[name="verificationPassword"]')?.getAttribute('maxlength')).toBe('128');
    (element.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    expect(verified).toHaveBeenCalledOnce();

    fixture.componentRef.setInput('authSuccess', 'Email verified.');
    fixture.detectChanges();
    expect(element.querySelector('[role="status"]')?.textContent).toContain('Email verified.');
    (element.querySelector('.submit-button') as HTMLButtonElement).click();
    expect(mode).toHaveBeenCalledWith('login');
    fixture.destroy();
  });
});
