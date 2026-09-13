import { Injectable, signal, computed, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';
import { LoginResponse, StudentAccount, KursantRole, ParentOtpChild } from '../models/kursant.models';

const TOKEN_KEY   = 'k30_kursant_token';
const STUDENT_KEY = 'k30_kursant_student';
const ROLE_KEY    = 'k30_kursant_role';
const ACTOR_KEY   = 'k30_kursant_actor';
const API         = '/api/v1/kursant_student.php';

interface ParentOtpChoice { success: true; data: { choose_child: ParentOtpChild[] } }

@Injectable({ providedIn: 'root' })
export class AuthService {
  private http   = inject(HttpClient);
  private router = inject(Router);

  private _token     = signal<string | null>(localStorage.getItem(TOKEN_KEY));
  private _student   = signal<StudentAccount | null>(this.#loadStudent());
  private _role      = signal<KursantRole>((localStorage.getItem(ROLE_KEY) as KursantRole) || 'student');
  private _actorName = signal<string>(localStorage.getItem(ACTOR_KEY) || '');

  readonly token          = this._token.asReadonly();
  readonly student        = this._student.asReadonly();
  readonly role           = this._role.asReadonly();
  readonly actorName      = this._actorName.asReadonly();
  readonly isAuthenticated = computed(() => !!this._token());
  readonly isGuardianView  = computed(() => this._role() !== 'student');

  #loadStudent(): StudentAccount | null {
    const raw = localStorage.getItem(STUDENT_KEY);
    if (!raw) return null;
    try { return JSON.parse(raw) as StudentAccount; } catch { return null; }
  }

  #applySession(res: LoginResponse): void {
    this._token.set(res.token);
    this._student.set(res.student);
    this._role.set(res.role);
    this._actorName.set(res.actor_name || '');
    localStorage.setItem(TOKEN_KEY, res.token);
    localStorage.setItem(STUDENT_KEY, JSON.stringify(res.student));
    localStorage.setItem(ROLE_KEY, res.role);
    localStorage.setItem(ACTOR_KEY, res.actor_name || '');
  }

  login(login: string, password: string, remember: boolean): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${API}?action=login`, { login, password, remember })
      .pipe(tap(res => { if (res.success && res.token) this.#applySession(res); }));
  }

  /** Logowanie rodzica/opiekuna hasłem (login = parent_login z konta kursanta). */
  parentLogin(login: string, password: string, remember: boolean): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${API}?action=parent_login`, { login, password, remember })
      .pipe(tap(res => { if (res.success && res.token) this.#applySession(res); }));
  }

  /** Krok 1 logowania SMS: wysyła kod na numer opiekuna, zwraca liczbę dopasowanych kont. */
  parentOtpSend(phone: string): Observable<{ success: boolean; error?: string }> {
    return this.http.post<{ success: boolean; error?: string }>(`${API}?action=parent_otp_send`, { phone });
  }

  /** Krok 2: weryfikuje kod. Jeśli >1 dziecko dopasowane, zwraca listę do wyboru zamiast tokenu. */
  parentOtpVerify(code: string): Observable<LoginResponse | ParentOtpChoice> {
    return this.http.post<LoginResponse | ParentOtpChoice>(`${API}?action=parent_otp_verify`, { code }).pipe(
      tap(res => { if ('token' in res && res.success && res.token) this.#applySession(res); })
    );
  }

  parentSelectChild(studentId: number): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${API}?action=parent_select_child`, { student_id: studentId })
      .pipe(tap(res => { if (res.success && res.token) this.#applySession(res); }));
  }

  authpLogin(login: string, password: string): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${API}?action=authp_login`, { login, password })
      .pipe(tap(res => { if (res.success && res.token) this.#applySession(res); }));
  }

  /** Wymienia jednorazowy token impersonacji (z panelu admina) na token sesji API. */
  impersonate(t: string): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${API}?action=impersonate_exchange`, { t })
      .pipe(tap(res => { if (res.success && res.token) this.#applySession(res); }));
  }

  logout(): void {
    this.http.post(`${API}?action=logout`, {}).subscribe({ error: () => {} });
    this._token.set(null);
    this._student.set(null);
    this._role.set('student');
    this._actorName.set('');
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(STUDENT_KEY);
    localStorage.removeItem(ROLE_KEY);
    localStorage.removeItem(ACTOR_KEY);
    this.router.navigate(['/login']);
  }

  updateStudent(student: StudentAccount): void {
    this._student.set(student);
    localStorage.setItem(STUDENT_KEY, JSON.stringify(student));
  }
}
