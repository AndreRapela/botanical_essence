import { CommonModule } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, ChangeDetectorRef, Component, DestroyRef, EventEmitter, Input, OnInit, Output, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { finalize, takeUntil } from 'rxjs';

import { AuthService } from './core/auth.service';
import { CommerceService } from './core/commerce.service';
import { ExternalRefundIssue, Language } from './storefront.models';

type ResolutionAction = 'link' | 'record' | 'dismiss' | 'reverse';

@Component({
  selector: 'app-admin-refund-reconciliation',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './admin-refund-reconciliation.component.html',
  styleUrl: './admin-refund-reconciliation.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class AdminRefundReconciliationComponent implements OnInit {
  @Input() language: Language = 'en';
  @Output() readonly changed = new EventEmitter<void>();
  private readonly auth = inject(AuthService);
  private readonly commerce = inject(CommerceService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly cdr = inject(ChangeDetectorRef);
  issues: ExternalRefundIssue[] = [];
  total = 0;
  page = 0;
  hasMore = false;
  busy = false;
  error = '';
  success = '';
  selected: ExternalRefundIssue | null = null;
  action: ResolutionAction = 'link';
  attempt: number | null = null;
  reason = '';
  acknowledged = false;

  ngOnInit(): void {
    this.auth.sessionChanged$.pipe(takeUntilDestroyed(this.destroyRef)).subscribe(() => {
      this.issues = [];
      this.total = 0;
      this.page = 0;
      this.hasMore = false;
      this.selected = null;
      this.action = 'link';
      this.attempt = null;
      this.reason = '';
      this.acknowledged = false;
      this.busy = false;
      this.error = '';
      this.success = '';
      this.cdr.markForCheck();
    });
    this.load();
  }

  copy(english: string, french: string): string { return this.language === 'fr' ? french : english; }

  statusLabel(status: string): string {
    const labels: Record<string, [string, string]> = {
      succeeded: ['Confirmed', 'Confirmé'], pending: ['Pending', 'En attente'], failed: ['Failed', 'Échoué'],
      canceled: ['Cancelled', 'Annulé'], requires_action: ['Action required', 'Action requise'],
      unknown: ['Outcome unknown', 'Résultat inconnu'], manual: ['Manually reviewed', 'Vérifié manuellement']
    };
    const label = labels[status];
    return label ? this.copy(...label) : this.copy('Unrecognized provider status', 'État du prestataire non reconnu');
  }

  actionDetail(): string {
    const labels: Record<ResolutionAction, [string, string]> = {
      link: ['Attach this provider reference to the selected attempt. A previously counted amount will not be added again.', 'Rattachez cette référence à la tentative sélectionnée. Un montant déjà comptabilisé ne sera pas ajouté de nouveau.'],
      record: ['Add this distinct, confirmed refund to the locally recorded total, within the remaining refundable balance.', 'Ajoutez ce remboursement distinct et confirmé au total local, dans la limite du solde remboursable.'],
      dismiss: ['Close this failed or outside-app case only when it has no unresolved effect on local accounting.', 'Clôturez ce cas échoué ou hors application uniquement s’il n’a aucun effet comptable local non résolu.'],
      reverse: ['Subtract this bank-returned amount from the recorded refunded total. This does not charge the customer again or send a replacement refund.', 'Retirez ce montant retourné par la banque du total remboursé enregistré. Cela ne débite pas de nouveau le client et n’envoie pas un autre remboursement.']
    };
    return this.copy(...labels[this.action]);
  }

  money(issue: ExternalRefundIssue): string {
    if (!['EUR', 'USD'].includes(issue.currency)) return `${issue.amountCents} ${this.copy('minor units', 'unités mineures')} (${issue.currency})`;
    return new Intl.NumberFormat(this.language === 'fr' ? 'fr-BE' : 'en-GB', { style: 'currency', currency: issue.currency }).format(issue.amountCents / 100);
  }

  candidates(issue: ExternalRefundIssue): ExternalRefundIssue['attempts'] {
    return issue.attempts.filter((row) => row.amountCents === issue.amountCents && row.currency === issue.currency
      && (row.refundId === null || row.refundId === issue.refundId)
      && !(row.alreadyCounted && ['failed', 'canceled', 'requires_action'].includes(issue.providerStatus)));
  }

  reversalCandidates(issue: ExternalRefundIssue): ExternalRefundIssue['attempts'] {
    return issue.attempts.filter((row) => row.alreadyCounted && row.refundId === issue.refundId
      && row.amountCents === issue.amountCents && row.currency === issue.currency);
  }

  canDismiss(issue: ExternalRefundIssue): boolean {
    return issue.orderId === null || (['failed', 'canceled'].includes(issue.providerStatus) && !this.reversalCandidates(issue).length);
  }

  open(issue: ExternalRefundIssue): void {
    if (this.busy || this.auth.user()?.role !== 'admin') return;
    this.selected = issue;
    this.action = this.reversalCandidates(issue).length && ['failed', 'canceled', 'requires_action'].includes(issue.providerStatus)
      ? 'reverse' : this.candidates(issue).length ? 'link' : issue.orderId !== null && issue.providerStatus === 'succeeded' ? 'record' : 'dismiss';
    this.attempt = (this.action === 'reverse' ? this.reversalCandidates(issue) : this.candidates(issue))[0]?.attempt ?? null;
    this.reason = '';
    this.acknowledged = false;
    this.error = '';
    this.success = '';
  }

  load(older = false): void {
    if (this.busy || this.auth.user()?.role !== 'admin' || (older && !this.hasMore)) return;
    this.busy = true;
    this.error = '';
    this.commerce.externalRefundIssues(older ? this.page + 1 : 1)
      .pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck()))
      .subscribe({
        next: (page) => {
          const ids = new Set(this.issues.map((row) => row.refundId));
          this.issues = older ? [...this.issues, ...page.data.filter((row) => !ids.has(row.refundId))] : page.data;
          this.total = page.total;
          this.page = page.page;
          this.hasMore = page.hasMore;
          this.busy = false;
        },
        error: (error: HttpErrorResponse) => this.fail(error)
      });
  }

  submit(): void {
    if (this.busy || this.auth.user()?.role !== 'admin' || !this.selected) return;
    if (!this.acknowledged || this.reason.trim().length < 10 || this.reason.trim().length > 450
      || (['link', 'reverse'].includes(this.action) && !(this.action === 'reverse' ? this.reversalCandidates(this.selected) : this.candidates(this.selected)).some((row) => row.attempt === this.attempt))) {
      this.error = this.copy('Select a matching attempt when required, enter a reason (10–450 characters) and confirm your review.', 'Sélectionnez une tentative correspondante si nécessaire, indiquez un motif (10–450 caractères) et confirmez votre vérification.');
      return;
    }
    this.busy = true;
    this.error = '';
    this.commerce.resolveExternalRefund(this.selected.refundId, this.action, this.reason.trim(), ['link', 'reverse'].includes(this.action) ? this.attempt ?? undefined : undefined)
      .pipe(takeUntil(this.auth.sessionChanged$), takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck()))
      .subscribe({
        next: () => {
          this.busy = false;
          this.selected = null;
          this.success = this.copy('Reconciliation recorded. No new Stripe refund was sent.', 'Rapprochement enregistré. Aucun nouveau remboursement Stripe n’a été envoyé.');
          this.load();
          this.changed.emit();
        },
        error: (error: HttpErrorResponse) => this.fail(error)
      });
  }

  private fail(error: HttpErrorResponse): void {
    this.busy = false;
    if ([401, 403].includes(error.status)) this.auth.markSignedOut();
    const code: unknown = error.error?.code;
    this.error = code === 'external_refund_ambiguous'
      ? this.copy('Link pending or manually counted attempts first. Recording this as another refund could count the money twice.', 'Rattachez d’abord les tentatives en attente ou comptabilisées manuellement pour éviter un double comptage.')
      : this.copy('Reconciliation could not be confirmed. Refresh the queue and check Stripe; no new refund was sent.', 'Le rapprochement n’a pas pu être confirmé. Actualisez la liste et vérifiez Stripe ; aucun nouveau remboursement n’a été envoyé.');
  }
}
