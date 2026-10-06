import { CommonModule } from '@angular/common';
import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { AdminContactMessage, Language } from './storefront.models';

@Component({
  selector: 'app-admin-contact-inbox',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './admin-contact-inbox.component.html',
  styleUrl: './admin-contact-inbox.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class AdminContactInboxComponent {
  @Input() messages: AdminContactMessage[] = [];
  @Input() language: Language = 'en';
  @Input() loading = false;
  @Input() hasMore = false;
  @Input() total = 0;
  @Output() readonly loadMore = new EventEmitter<void>();
  @Output() readonly changeStatus = new EventEmitter<{ message: AdminContactMessage; status: AdminContactMessage['status'] }>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }

  subjectLabel(subject: AdminContactMessage['subject']): string {
    switch (subject) {
      case 'order': return this.copy('Order', 'Commande');
      case 'care': return this.copy('Plant care', 'Entretien des plantes');
      case 'seller': return this.copy('Seller', 'Vendeur');
      default: return this.copy('Other', 'Autre');
    }
  }

  statusLabel(status: AdminContactMessage['status']): string {
    switch (status) {
      case 'new': return this.copy('New', 'Nouveau');
      case 'in_progress': return this.copy('In progress', 'En cours');
      default: return this.copy('Closed', 'Fermé');
    }
  }

  setStatus(message: AdminContactMessage, status: AdminContactMessage['status']): void {
    this.changeStatus.emit({ message, status });
  }
}
