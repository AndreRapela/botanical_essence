import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Subject, of, throwError } from 'rxjs';

import { AdminRefundReconciliationComponent } from './admin-refund-reconciliation.component';
import { AuthService } from './core/auth.service';
import { CommerceService } from './core/commerce.service';
import { AuthUser, ExternalRefundIssue } from './storefront.models';

const issue: ExternalRefundIssue = {
  refundId: 're_external1', orderId: 1, orderNumber: 'EXTERNAL-1', paymentIntentId: 'pi_external1',
  amountCents: 2500, currency: 'EUR', providerStatus: 'succeeded', resolution: 'unmatched',
  createdAt: '2026-10-07T10:00:00Z', lastSyncedAt: '2026-10-07T10:05:00Z',
  attempts: [{ attempt: 1, kind: 'partial', amountCents: 2500, currency: 'EUR', status: 'manual', refundId: null, alreadyCounted: true }]
};

describe('AdminRefundReconciliationComponent', () => {
  let fixture: ComponentFixture<AdminRefundReconciliationComponent>;
  let component: AdminRefundReconciliationComponent;
  let session: Subject<number>;
  const user = signal<AuthUser | null>(null);
  const list = vi.fn();
  const resolve = vi.fn();
  const signOut = vi.fn();

  beforeEach(async () => {
    session = new Subject<number>();
    user.set({ id: 42, name: 'Admin', email: 'admin@example.test', role: 'admin', emailVerifiedAt: '2026-10-07' });
    list.mockReset().mockReturnValue(of({ data: [issue], page: 1, limit: 50, total: 1, hasMore: false }));
    resolve.mockReset().mockReturnValue(of({ message: 'A provider message must not override localized feedback.' }));
    signOut.mockReset().mockImplementation(() => { user.set(null); session.next(1); });
    await TestBed.configureTestingModule({ imports: [AdminRefundReconciliationComponent], providers: [
      { provide: AuthService, useValue: { user, sessionChanged$: session.asObservable(), markSignedOut: signOut } },
      { provide: CommerceService, useValue: { externalRefundIssues: list, resolveExternalRefund: resolve } }
    ] }).compileComponents();
    fixture = TestBed.createComponent(AdminRefundReconciliationComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('loads a paginated queue and explains blocking and double-count prevention', () => {
    expect(list).toHaveBeenCalledWith(1);
    const text = (fixture.nativeElement as HTMLElement).textContent;
    expect(text).toContain('Refunds needing review');
    expect(text).toContain('blocked until reviewed');
    expect(text).toContain('will not add the amount twice');
    expect(text).toContain('€25.00');
  });

  it('offers only matching unbound identities and excludes failed manually counted attempts', () => {
    expect(component.candidates({ ...issue, attempts: [...issue.attempts,
      { ...issue.attempts[0], attempt: 2, amountCents: 1000 },
      { ...issue.attempts[0], attempt: 3, refundId: 're_other' }] })).toHaveLength(1);
    expect(component.candidates({ ...issue, providerStatus: 'failed' })).toHaveLength(0);
  });

  it('requires acknowledgement, a reason and a matching attempt before submission', () => {
    component.open(issue);
    component.submit();
    expect(resolve).not.toHaveBeenCalled();
    component.acknowledged = true;
    component.reason = 'Verified original provider refund';
    component.attempt = 999;
    component.submit();
    expect(resolve).not.toHaveBeenCalled();
    expect(component.error).toContain('matching attempt');
  });

  it('links the selected manual attempt and refreshes orders without asking Stripe to send money', () => {
    const changed = vi.fn();
    component.changed.subscribe(changed);
    component.open(issue);
    component.acknowledged = true;
    component.reason = '  Verified this manually counted refund  ';
    component.submit();
    expect(resolve).toHaveBeenCalledWith('re_external1', 'link', 'Verified this manually counted refund', 1);
    expect(component.success).toContain('No new Stripe refund was sent');
    expect(changed).toHaveBeenCalledOnce();
    expect(component.selected).toBeNull();
    expect(list).toHaveBeenCalledTimes(2);
  });

  it('explains ambiguity using localized text rather than leaking a Portuguese provider error', () => {
    resolve.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 409, error: { code: 'external_refund_ambiguous', message: 'Mensagem interna em português' } })));
    component.open(issue);
    component.action = 'record';
    component.acknowledged = true;
    component.reason = 'Reviewed but this may duplicate the amount';
    component.submit();
    expect(component.error).toContain('count the money twice');
    expect(component.error).not.toContain('Mensagem');
    expect(component.selected).toEqual(issue);
  });

  it('renders an accessible inline audit form in French with a local-accounting safety notice', async () => {
    fixture.componentRef.setInput('language', 'fr');
    component.open(issue);
    fixture.detectChanges();
    await fixture.whenStable();
    const root = fixture.nativeElement as HTMLElement;
    expect(root.textContent).toContain('Remboursements à vérifier');
    expect(root.textContent).toContain('déjà comptabilisé');
    expect(root.textContent).toContain('n’envoie pas d’argent');
    for (const element of root.querySelectorAll('select, textarea')) expect(root.querySelector(`label[for="${element.id}"]`)).not.toBeNull();
  });

  it('cannot dismiss an active refund or imply unsupported currencies use two decimals', () => {
    expect(component.canDismiss(issue)).toBe(false);
    expect(component.canDismiss({ ...issue, providerStatus: 'failed' })).toBe(true);
    expect(component.canDismiss({ ...issue, orderId: null })).toBe(true);
    expect(component.money({ ...issue, currency: 'JPY', amountCents: 25 })).toBe('25 minor units (JPY)');
  });

  it('deduplicates paginated rows and does not paginate after exhaustion', () => {
    component.hasMore = true;
    list.mockReturnValue(of({ data: [issue, { ...issue, refundId: 're_external2' }], page: 2, total: 2, hasMore: false }));
    component.load(true);
    expect(component.issues.map((row) => row.refundId)).toEqual(['re_external1', 're_external2']);
    component.load(true);
    expect(list).toHaveBeenCalledTimes(2);
  });

  it('cancels stale responses and clears financial data when the session changes', () => {
    const deferred = new Subject<{ data: ExternalRefundIssue[]; page: number; limit: number; total: number; hasMore: boolean }>();
    list.mockReturnValue(deferred);
    component.load();
    user.set(null);
    session.next(2);
    deferred.next({ data: [issue], page: 1, limit: 50, total: 1, hasMore: false });
    expect(component.issues).toEqual([]);
    expect(component.total).toBe(0);
    expect(component.page).toBe(0);
    expect(component.hasMore).toBe(false);
    component.open(issue);
    component.load();
    expect(component.selected).toBeNull();
    expect(list).toHaveBeenCalledTimes(2);
  });

  it('revokes the local admin session after authorization failure', () => {
    list.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 403 })));
    component.load();
    expect(signOut).toHaveBeenCalledOnce();
    expect(user()).toBeNull();
    expect(component.issues).toEqual([]);
  });

  it('offers an audited correction, not dismissal, for a counted refund returned by Stripe', () => {
    const returned: ExternalRefundIssue = { ...issue, providerStatus: 'failed', attempts: [{ ...issue.attempts[0], status: 'succeeded', refundId: issue.refundId }] };
    component.open(returned);
    expect(component.action).toBe('reverse');
    expect(component.canDismiss(returned)).toBe(false);
    expect(component.candidates(returned)).toEqual([]);
    component.acknowledged = true;
    component.reason = 'Verified returned funds with the payment provider';
    component.submit();
    expect(resolve).toHaveBeenCalledWith(issue.refundId, 'reverse', component.reason, 1);
  });

  it('does not treat an unbound manual amount as proof for reversing an arbitrary external refund', () => {
    expect(component.reversalCandidates({ ...issue, providerStatus: 'failed' })).toEqual([]);
    component.open({ ...issue, providerStatus: 'failed' });
    expect(component.action).toBe('dismiss');
  });

  it('localizes known provider states and safely labels an unrecognized status', () => {
    expect(component.statusLabel('succeeded')).toBe('Confirmed');
    fixture.componentRef.setInput('language', 'fr');
    expect(component.statusLabel('requires_action')).toBe('Action requise');
    expect(component.statusLabel('manual')).toBe('Vérifié manuellement');
    expect(component.statusLabel('unexpected')).toBe('État du prestataire non reconnu');
  });

  it('explains the accounting effect of every resolution before asking for confirmation', () => {
    expect(component.actionDetail()).toContain('will not be added again');
    component.action = 'record';
    expect(component.actionDetail()).toContain('remaining refundable balance');
    component.action = 'dismiss';
    expect(component.actionDetail()).toContain('no unresolved effect');
    component.action = 'reverse';
    expect(component.actionDetail()).toContain('Subtract this bank-returned amount');
    fixture.componentRef.setInput('language', 'fr');
    expect(component.actionDetail()).toContain('ne débite pas de nouveau');
  });
});
