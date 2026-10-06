import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { STOREFRONT_TRANSLATIONS } from './i18n/storefront-translations';
import { AuthMode, ContactMessageInput, Language, LocalizedText, Page } from './storefront.models';

export type InformationPage = Extract<Page, 'contact' | 'deliveries' | 'returns' | 'terms' | 'privacy'>;

export type ContactFormData = ContactMessageInput;

export interface InformationPageNavigation {
  page: Page;
  authMode?: AuthMode;
}

@Component({
  selector: 'app-information-pages',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './information-pages.component.html',
  styleUrl: './information-pages.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class InformationPagesComponent {
  @Input({ required: true }) page!: InformationPage;
  @Input() language: Language = 'en';
  @Input() authenticated = false;
  @Input() hours = '';
  @Input() contactSent = false;
  @Input() contactLoading = false;
  @Input() contactError = '';
  @Input() set initialContactForm(value: ContactFormData) { this.form = { ...value }; }

  @Output() readonly navigate = new EventEmitter<InformationPageNavigation>();
  @Output() readonly send = new EventEmitter<ContactFormData>();

  form: ContactFormData = { name: '', email: '', subject: 'order', message: '' };

  private readonly translations: Readonly<Record<string, LocalizedText>> = STOREFRONT_TRANSLATIONS;

  copy(en: string, fr: string): string {
    return this.language === 'fr' ? fr : en;
  }

  translate(key: string): string {
    return this.translations[key]?.[this.language] ?? key;
  }

  openPrimaryAction(): void {
    const returns = this.page === 'returns';
    this.navigate.emit({
      page: returns ? 'account' : 'contact',
      ...(returns && !this.authenticated ? { authMode: 'login' as const } : {})
    });
  }

  sendContact(): void {
    this.send.emit({ ...this.form });
  }
}
