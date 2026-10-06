import { CommonModule } from '@angular/common';
import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { AdminAuditEntry, Language } from './storefront.models';

@Component({
  selector: 'app-admin-audit-log',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './admin-audit-log.component.html',
  styleUrl: './admin-audit-log.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class AdminAuditLogComponent {
  @Input() entries: AdminAuditEntry[] = [];
  @Input() language: Language = 'en';
  @Input() loading = false;
  @Input() hasMore = false;
  @Input() total = 0;
  @Output() readonly loadMore = new EventEmitter<void>();

  copy(english: string, french: string): string {
    return this.language === 'fr' ? french : english;
  }

  actionLabel(action: string): string {
    return action.replace(/[._-]+/g, ' ');
  }

  snapshot(value: string | null): string {
    if (!value) return this.copy('No snapshot recorded', 'Aucun détail enregistré');
    try {
      return JSON.stringify(JSON.parse(value), null, 2);
    } catch {
      return value;
    }
  }
}
