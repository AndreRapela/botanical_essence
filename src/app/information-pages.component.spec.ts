import { TestBed } from '@angular/core/testing';

import { InformationPage, InformationPagesComponent } from './information-pages.component';

describe('InformationPagesComponent', () => {
  function create(page: InformationPage, language: 'en' | 'fr' = 'en', authenticated = false) {
    TestBed.configureTestingModule({ imports: [InformationPagesComponent] });
    const fixture = TestBed.createComponent(InformationPagesComponent);
    fixture.componentRef.setInput('page', page);
    fixture.componentRef.setInput('language', language);
    fixture.componentRef.setInput('authenticated', authenticated);
    fixture.detectChanges();
    return fixture;
  }

  it('validates and emits an isolated translated contact submission and announces its result', () => {
    const fixture = create('contact', 'fr');
    const component = fixture.componentInstance;
    const element: HTMLElement = fixture.nativeElement;
    const send = vi.fn();
    component.send.subscribe(send);
    component.form = { name: 'Ada', email: 'ada@example.be', subject: 'order', message: 'Question sur ma commande' };
    fixture.componentRef.setInput('hours', 'Du lundi au vendredi');
    fixture.componentRef.setInput('contactError', 'Réessayez.');
    fixture.componentRef.setInput('contactLoading', true);
    fixture.detectChanges();

    expect(element.textContent).toContain('Comment pouvons-nous');
    expect(element.textContent).toContain('Du lundi au vendredi');
    expect(element.querySelector('[role="alert"]')?.textContent).toContain('Réessayez.');
    expect(element.querySelector('input[name="contactName"]')?.getAttribute('maxlength')).toBe('120');
    expect(element.querySelector('button[type="submit"]')?.hasAttribute('disabled')).toBe(true);
    component.sendContact();
    expect(send).toHaveBeenCalledWith({ name: 'Ada', email: 'ada@example.be', subject: 'order', message: 'Question sur ma commande' });
    expect(send.mock.calls[0][0]).not.toBe(component.form);

    fixture.componentRef.setInput('contactSent', true);
    fixture.detectChanges();
    expect(element.querySelector('[role="status"]')?.textContent).toContain('Message reçu.');
    expect(element.querySelector('form')).toBeNull();
    fixture.destroy();
  });

  it('shows translated delivery policy and routes its action to contact', () => {
    const fixture = create('deliveries', 'fr');
    const component = fixture.componentInstance;
    const navigate = vi.fn();
    component.navigate.subscribe(navigate);

    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Expédition manuelle');
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Réservez l’envoi directement auprès de bpost');
    component.openPrimaryAction();
    expect(navigate).toHaveBeenCalledWith({ page: 'contact' });
    fixture.destroy();
  });

  it('sends unauthenticated return requests to login and authenticated requests to orders', () => {
    const fixture = create('returns');
    const component = fixture.componentInstance;
    const navigate = vi.fn();
    component.navigate.subscribe(navigate);

    expect((fixture.nativeElement as HTMLElement).textContent).toContain('14 calendar days');
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('there is no 7-day cutoff');
    component.openPrimaryAction();
    expect(navigate).toHaveBeenLastCalledWith({ page: 'account', authMode: 'login' });

    fixture.componentRef.setInput('authenticated', true);
    fixture.detectChanges();
    component.openPrimaryAction();
    expect(navigate).toHaveBeenLastCalledWith({ page: 'account' });
    fixture.destroy();
  });

  it('renders the corresponding legal copy and falls back to the missing translation key', () => {
    const fixture = create('terms');
    const component = fixture.componentInstance;
    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('Clear agreements');
    expect(element.textContent).toContain('Keep your credentials private');
    expect(component.translate('missing.key')).toBe('missing.key');

    fixture.componentRef.setInput('page', 'privacy');
    fixture.componentRef.setInput('language', 'fr');
    fixture.detectChanges();
    expect(element.textContent).toContain('CONFIDENTIALITÉ');
    expect(element.textContent).toContain('Données collectées');
    expect(element.querySelector('a[href="mailto:hello@botanicalessence.be"]')).not.toBeNull();
    fixture.destroy();
  });
});
