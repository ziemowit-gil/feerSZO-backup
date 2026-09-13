import { Injectable, signal, computed, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';
import { Instructor, InstructorLoginResponse, InstructorTotpRequiredResponse } from '../models/kursant.models';

const TOKEN_KEY      = 'k30_dyd_instructor_token';
const INSTRUCTOR_KEY = 'k30_dyd_instructor';
const API            = '/api/v1/dydaktyk_instructor.php';

/**
 * Auth osobna od AuthService (panel kursanta) — inna tożsamość (konto SZO
 * `users`, logowanie hasło + TOTP obowiązkowe), inne API, inne klucze
 * localStorage. Współdzielą tylko wzorzec (token jako sygnał + interceptor).
 */
@Injectable({ providedIn: 'root' })
export class InstructorAuthService {
  private http   = inject(HttpClient);
  private router = inject(Router);

  private _token      = signal<string | null>(localStorage.getItem(TOKEN_KEY));
  private _instructor = signal<Instructor | null>(this.#loadInstructor());

  readonly token           = this._token.asReadonly();
  readonly instructor      = this._instructor.asReadonly();
  readonly isAuthenticated = computed(() => !!this._token());

  #loadInstructor(): Instructor | null {
    const raw = localStorage.getItem(INSTRUCTOR_KEY);
    if (!raw) return null;
    try { return JSON.parse(raw) as Instructor; } catch { return null; }
  }

  /** Krok 1: e-mail + hasło. Sukces zawsze zwraca totp_required — TOTP jest obowiązkowe. */
  login(email: string, password: string): Observable<InstructorTotpRequiredResponse> {
    return this.http.post<InstructorTotpRequiredResponse>(`${API}?action=login`, { email, password });
  }

  /** Krok 2: kod TOTP (albo kod zapasowy) dla pending_token z kroku 1. */
  verifyTotp(pendingToken: string, code: string, remember: boolean): Observable<InstructorLoginResponse> {
    return this.http
      .post<InstructorLoginResponse>(`${API}?action=verify_totp`, { pending_token: pendingToken, code, remember })
      .pipe(tap(res => {
        if (res.success && res.data) {
          this._token.set(res.data.token);
          this._instructor.set(res.data.instructor);
          localStorage.setItem(TOKEN_KEY, res.data.token);
          localStorage.setItem(INSTRUCTOR_KEY, JSON.stringify(res.data.instructor));
        }
      }));
  }

  logout(): void {
    if (!this._token()) return;
    const token = this._token();
    this._token.set(null);
    this._instructor.set(null);
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(INSTRUCTOR_KEY);
    this.http.post(`${API}?action=logout`, {}, { headers: { Authorization: `Bearer ${token}` } })
      .subscribe({ error: () => {} });
    this.router.navigate(['/logowanie-prowadzacy']);
  }
}
