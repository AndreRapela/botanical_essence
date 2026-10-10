import type { HttpErrorResponse } from '@angular/common/http';
import type { ChangeDetectorRef, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize } from 'rxjs';

import type { AuthService } from './core/auth.service';
import type { CommerceService } from './core/commerce.service';
import type { AdminWithdrawalRequest, Language, LocalizedText, OrderSummary } from './storefront.models';
import { withdrawalStatusText } from './order.view';
import { adminRefundResultMessage } from './admin-refund-interaction';

export type AdminWithdrawalAction = 'review' | 'send_instructions' | 'mark_received' | 'not_eligible' | 'refund' | 'check_refund' | 'retry_refund';

/** Customer withdrawal and admin refund actions depend only on this state/service contract. */
export interface AdminWithdrawalsActionsContext {
  auth: Pick<AuthService, 'sessionVersion'>;
  commerce: Pick<CommerceService, 'submitWithdrawal' | 'adminWithdrawalAction'>;
  cdr: Pick<ChangeDetectorRef, 'markForCheck'>;
  destroyRef: DestroyRef;
  currentLanguage: Language;
  withdrawalOrder: OrderSummary | null;
  withdrawalType: 'withdrawal' | 'problem';
  withdrawalMessage: string;
  withdrawalDeliveryDate: string;
  withdrawalLoading: boolean;
  withdrawalError: string;
  withdrawalSuccess: string;
  adminLoading: boolean;
  adminError: string;
  adminSuccess: string;
  copy(english: string, french: string): string;
  apiError(error: HttpErrorResponse, fallback: string): string;
  handleUnauthorized(error: HttpErrorResponse, expectedSessionVersion?: number): void;
  loadOrders(): void;
  loadAdminSellers(): void;
}

export function openWithdrawalRequest(this: AdminWithdrawalsActionsContext, order: OrderSummary, type: 'withdrawal' | 'problem'): void {
    this.withdrawalOrder = order;
    this.withdrawalType = type;
    this.withdrawalMessage = '';
    this.withdrawalDeliveryDate = '';
    this.withdrawalError = '';
    this.withdrawalSuccess = '';
  }

export function closeWithdrawalRequest(this: AdminWithdrawalsActionsContext): void {
    if (this.withdrawalLoading) return;
    this.withdrawalOrder = null;
    this.withdrawalError = '';
  }

export function submitWithdrawalRequest(this: AdminWithdrawalsActionsContext): void {
    const order = this.withdrawalOrder;
    if (!order || this.withdrawalLoading) return;
    if (this.withdrawalType === 'problem' && this.withdrawalMessage.trim().length < 10) {
      this.withdrawalError = this.copy('Describe the issue in at least 10 characters.', 'Décrivez le problème en au moins 10 caractères.');
      return;
    }
    this.withdrawalLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.withdrawalError = '';
    this.commerce.submitWithdrawal({
      orderNumber: order.number,
      type: this.withdrawalType,
      language: this.currentLanguage,
      ...(this.withdrawalMessage.trim() ? { message: this.withdrawalMessage.trim() } : {}),
      ...(this.withdrawalDeliveryDate ? { deliveryReceivedDate: this.withdrawalDeliveryDate } : {})
    }).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck())).subscribe({
      next: ({ request, alreadySubmitted, emailAcknowledged }) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.withdrawalLoading = false;
        this.withdrawalOrder = null;
        this.withdrawalSuccess = alreadySubmitted
          ? this.copy('An active request for this order is already on file. Its reference and status appear below.', 'Une demande est déjà en cours pour cette commande. Sa référence et son statut figurent ci-dessous.')
          : emailAcknowledged
            ? this.copy(`Request ${request?.requestNumber ?? ''} recorded. We emailed a timestamped acknowledgment and will send the return instructions after review.`, `Demande ${request?.requestNumber ?? ''} enregistrée. Un accusé horodaté a été envoyé par e-mail; les instructions suivront après examen.`)
            : this.copy(`Request ${request?.requestNumber ?? ''} recorded on screen. Email delivery could not be confirmed; keep this reference and contact support if needed.`, `Demande ${request?.requestNumber ?? ''} enregistrée à l’écran. L’envoi de l’e-mail n’a pas pu être confirmé; conservez cette référence et contactez le support si nécessaire.`);
        this.loadOrders();
      },
      error: (error: HttpErrorResponse) => {
        if (sessionVersion !== this.auth.sessionVersion) return;
        this.withdrawalLoading = false;
        this.handleUnauthorized(error, sessionVersion);
        this.withdrawalError = this.apiError(error, this.copy('We could not record the request. Your order has not been changed; please try again or contact support.', 'Impossible d’enregistrer la demande. Votre commande n’a pas été modifiée; réessayez ou contactez le support.'));
      }
    });
  }

export function withdrawalStatusLabel(this: AdminWithdrawalsActionsContext, status: string | null | undefined): string {
    return withdrawalStatusText(status, this.currentLanguage);
  }

export function runAdminWithdrawalAction(this: AdminWithdrawalsActionsContext, request: AdminWithdrawalRequest, action: AdminWithdrawalAction): void {
    if (this.adminLoading) return;
    let note: string | undefined;
    const prompts: Partial<Record<typeof action, LocalizedText>> = {
      send_instructions: { en: 'Enter the complete return address, packaging guidance, deadline and who pays return postage. This will be emailed to the customer.', fr: 'Indiquez l’adresse complète, les consignes d’emballage, le délai et qui paie le retour. Ces informations seront envoyées au client.' },
      mark_received: { en: 'Record how you verified receipt of the returned goods or proof of dispatch (at least 10 characters).', fr: 'Indiquez comment vous avez vérifié la réception des biens ou la preuve d’expédition (au moins 10 caractères).' },
      not_eligible: { en: 'Enter the specific legal/contractual reason and product basis for this decision (at least 10 characters).', fr: 'Indiquez le motif juridique/contractuel précis et le produit concerné (au moins 10 caractères).' }
    };
    if (prompts[action]) {
      note = window.prompt(this.copy(prompts[action]!.en, prompts[action]!.fr))?.trim();
      const minimum = action === 'send_instructions' ? 20 : 10;
      if (!note || note.length < minimum) return;
    }
    if (action === 'refund' && !window.confirm(this.copy(
      'Start a full refund of the original Stripe payment? This is only allowed after return receipt or proof is recorded. Returned stock will not be restored automatically.',
      'Démarrer le remboursement intégral du paiement Stripe initial ? Cela n’est autorisé qu’après enregistrement du retour ou de la preuve d’envoi. Le stock ne sera pas réintégré automatiquement.'
    ))) return;
    this.adminLoading = true;
    const sessionVersion = this.auth.sessionVersion;
    this.adminError = '';
    this.adminSuccess = '';
    this.commerce.adminWithdrawalAction({ requestId: request.id, action, ...(note ? { note } : {}) })
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.cdr.markForCheck()))
      .subscribe({
        next: (result) => {
          if (sessionVersion !== this.auth.sessionVersion) return;
          this.adminLoading = false;
          this.adminSuccess = adminRefundResultMessage(result, (english, french) => this.copy(english, french));
          this.loadAdminSellers();
        },
        error: (error: HttpErrorResponse) => {
          if (sessionVersion !== this.auth.sessionVersion) return;
          this.adminLoading = false;
          this.handleUnauthorized(error, sessionVersion);
          this.adminError = this.apiError(error, this.copy('We could not update this return request. Refresh the queue and verify the payment before retrying.', 'Impossible de mettre à jour cette demande. Actualisez la liste et vérifiez le paiement avant de réessayer.'));
        }
      });
  }
