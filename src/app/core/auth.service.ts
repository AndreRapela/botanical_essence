import { HttpClient, HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { Injectable, OnDestroy, signal } from '@angular/core';
import { EMPTY, Observable, Subject, catchError, filter, finalize, of, shareReplay, switchMap, takeUntil, tap, throwError } from 'rxjs';

import { AuthUser, DemoAccount } from '../storefront.models';

interface SessionResponse {
  user: AuthUser | null;
  csrfToken: string;
}

interface LoginResponse {
  message: string;
  user: AuthUser;
  csrfToken: string;
}

interface ActionResponse {
  message: string;
  developmentVerificationUrl?: string | null;
  developmentResetUrl?: string | null;
}

const AUTH_SESSION_STORAGE_SIGNAL = 'botanical-auth-session-change';
const AUTH_SESSION_CHANNEL_MESSAGE = 'session-changed';

@Injectable({ providedIn: 'root' })
export class AuthService implements OnDestroy {
  readonly user = signal<AuthUser | null>(null);
  readonly ready = signal(false);
  private readonly sessionChangesSubject = new Subject<number>();
  private readonly sessionFetchCancelled = new Subject<void>();
  readonly sessionChanged$ = this.sessionChangesSubject.asObservable();
  private csrfToken = '';
  private sessionRequest: Observable<SessionResponse> | null = null;
  private sessionVersionValue = 0;
  private sessionFetchRevision = 0;
  private sessionMutationRevision = 0;
  private readonly sessionChannel: BroadcastChannel | null;
  private readonly onStorageChange = (event: StorageEvent): void => {
    if (event.key === AUTH_SESSION_STORAGE_SIGNAL && event.newValue) this.refreshAfterExternalSessionChange();
  };
  private readonly onChannelMessage = (event: MessageEvent<unknown>): void => {
    if (event.data === AUTH_SESSION_CHANNEL_MESSAGE) this.refreshAfterExternalSessionChange();
  };

  get sessionVersion(): number { return this.sessionVersionValue; }

  constructor(private readonly http: HttpClient) {
    let sessionChannel: BroadcastChannel | null = null;
    if (typeof window !== 'undefined') {
      window.addEventListener('storage', this.onStorageChange);
      if (typeof BroadcastChannel !== 'undefined') {
        try {
          sessionChannel = new BroadcastChannel(AUTH_SESSION_STORAGE_SIGNAL);
          sessionChannel.addEventListener('message', this.onChannelMessage);
        } catch {
          // The storage event below remains available when channels are restricted.
        }
      }
    }
    this.sessionChannel = sessionChannel;
  }

  initialize(broadcastIdentityChange = this.user() !== null): Observable<SessionResponse> {
    if (this.csrfToken) return of({ user: this.user(), csrfToken: this.csrfToken });
    if (this.sessionRequest) return this.sessionRequest;

    const requestRevision = ++this.sessionFetchRevision;
    const request = this.http.get<SessionResponse>('/api/auth/session').pipe(
      takeUntil(this.sessionFetchCancelled),
      tap({
        next: (session) => {
          if (requestRevision !== this.sessionFetchRevision) return;
          this.publishSessionUser(session.user, broadcastIdentityChange);
          this.csrfToken = session.csrfToken;
          this.ready.set(true);
        },
        error: () => {
          if (requestRevision === this.sessionFetchRevision) this.ready.set(true);
        }
      }),
      finalize(() => {
        if (requestRevision === this.sessionFetchRevision) this.sessionRequest = null;
      }),
      shareReplay({ bufferSize: 1, refCount: false })
    );
    this.sessionRequest = request;
    return request;
  }

  login(input: { email: string; password: string; rememberMe: boolean }): Observable<LoginResponse> {
    return this.mutateSession<LoginResponse>('/api/auth/login', input, (response) => response.user);
  }

  demoAccounts(): Observable<{ enabled: boolean; accounts: DemoAccount[] }> {
    return this.http.get<{ enabled: boolean; accounts: DemoAccount[] }>('/api/auth/demo-accounts');
  }

  demoLogin(role: DemoAccount['role']): Observable<LoginResponse> {
    return this.mutateSession<LoginResponse>('/api/auth/demo-login', { role }, (response) => response.user);
  }

  replaceUser(user: AuthUser): void {
    this.user.set(user);
  }

  logout(): Observable<ActionResponse & { csrfToken: string }> {
    return this.mutateSession<ActionResponse & { csrfToken: string }>('/api/auth/logout', {}, () => null);
  }

  register(input: Record<string, unknown>): Observable<ActionResponse> {
    return this.postWithCsrf<ActionResponse>('/api/auth/register', input);
  }

  forgotPassword(email: string): Observable<ActionResponse> {
    return this.postWithCsrf<ActionResponse>('/api/auth/forgot-password', { email });
  }

  resetPassword(token: string, password: string): Observable<ActionResponse> {
    return this.postWithCsrf<ActionResponse>('/api/auth/reset-password', { token, password });
  }

  verifyEmail(token: string, password: string): Observable<ActionResponse> {
    return this.postWithCsrf<ActionResponse>('/api/auth/verify-email', { token, password });
  }

  postWithCsrf<T>(url: string, body: unknown): Observable<T> {
    let requestSessionVersion = this.sessionVersionValue;
    const send = (): Observable<T> => this.http.post<T>(url, body, { headers: this.headers() });
    const request: Observable<T> = this.csrfToken
      ? send()
      : this.initialize().pipe(
        tap(() => { requestSessionVersion = this.sessionVersionValue; }),
        switchMap(send)
      );

    return request.pipe(
      catchError((error: HttpErrorResponse) => {
        if (error.status !== 403 || error.error?.code !== 'csrf_invalid') {
          return throwError(() => error);
        }
        this.csrfToken = '';
        return this.initialize().pipe(switchMap(() => {
          if (this.sessionVersionValue !== requestSessionVersion) return throwError(() => error);
          return send();
        }));
      })
    );
  }

  ngOnDestroy(): void {
    if (typeof window !== 'undefined') window.removeEventListener('storage', this.onStorageChange);
    this.sessionChannel?.removeEventListener('message', this.onChannelMessage);
    this.sessionChannel?.close();
  }

  markSignedOut(): void {
    this.sessionMutationRevision += 1;
    this.publishSessionUser(null);
    this.csrfToken = '';
  }

  private mutateSession<T extends { csrfToken: string }>(url: string, body: unknown, sessionUser: (response: T) => AuthUser | null): Observable<T> {
    const mutationRevision = ++this.sessionMutationRevision;
    return this.initialize().pipe(
      switchMap(() => {
        if (mutationRevision !== this.sessionMutationRevision) return EMPTY;
        const sessionVersion = this.sessionVersionValue;
        const isCurrent = () => mutationRevision === this.sessionMutationRevision && sessionVersion === this.sessionVersionValue;
        return this.postWithCsrf<T>(url, body).pipe(
          filter(isCurrent),
          tap((response) => {
            this.csrfToken = response.csrfToken;
            this.publishSessionUser(sessionUser(response));
          }),
          catchError((error) => isCurrent() ? throwError(() => error) : EMPTY)
        );
      }),
      catchError((error) => mutationRevision === this.sessionMutationRevision ? throwError(() => error) : EMPTY)
    );
  }

  private publishSessionUser(user: AuthUser | null, broadcast = true): void {
    const previousUser = this.user();
    const identityChanged = previousUser?.id !== user?.id || previousUser?.role !== user?.role;
    this.user.set(user);
    if (!identityChanged) return;
    this.sessionVersionValue += 1;
    this.sessionChangesSubject.next(this.sessionVersionValue);
    if (broadcast) this.broadcastSessionChange();
  }

  private refreshAfterExternalSessionChange(): void {
    this.sessionMutationRevision += 1;
    this.sessionFetchCancelled.next();
    this.sessionFetchRevision += 1;
    this.sessionRequest = null;
    this.csrfToken = '';
    this.user.set(null);
    this.ready.set(true);
    this.sessionVersionValue += 1;
    this.sessionChangesSubject.next(this.sessionVersionValue);
    this.initialize(false).subscribe({ error: () => this.ready.set(true) });
  }

  private broadcastSessionChange(): void {
    if (this.sessionChannel) {
      try {
        this.sessionChannel.postMessage(AUTH_SESSION_CHANNEL_MESSAGE);
        return;
      } catch {
        // Fall back to a storage event if the channel became unavailable.
      }
    }
    if (typeof window === 'undefined') return;
    try {
      window.localStorage.setItem(AUTH_SESSION_STORAGE_SIGNAL, `${Date.now()}-${Math.random().toString(36).slice(2)}`);
    } catch {
      // Cross-tab invalidation is best-effort when browser storage is disabled.
    }
  }

  private headers(): HttpHeaders {
    return new HttpHeaders({ 'X-CSRF-Token': this.csrfToken });
  }
}
