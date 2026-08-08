import { Injectable, signal, computed, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';
import { LoginResponse, StudentAccount } from '../models/kursant.models';

const TOKEN_KEY   = 'k30_kursant_token';
const STUDENT_KEY = 'k30_kursant_student';
const API         = '/api/v1/kursant_student.php';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private http   = inject(HttpClient);
  private router = inject(Router);

  private _token   = signal<string | null>(localStorage.getItem(TOKEN_KEY));
  private _student = signal<StudentAccount | null>(this.#loadStudent());

  readonly token          = this._token.asReadonly();
  readonly student        = this._student.asReadonly();
  readonly isAuthenticated = computed(() => !!this._token());

  #loadStudent(): StudentAccount | null {
    const raw = localStorage.getItem(STUDENT_KEY);
    if (!raw) return null;
    try { return JSON.parse(raw) as StudentAccount; } catch { return null; }
  }

  login(login: string, password: string, remember: boolean): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${API}?action=login`, { login, password, remember })
      .pipe(
        tap(res => {
          if (res.success && res.token) {
            this._token.set(res.token);
            this._student.set(res.student);
            localStorage.setItem(TOKEN_KEY, res.token);
            localStorage.setItem(STUDENT_KEY, JSON.stringify(res.student));
          }
        })
      );
  }

  logout(): void {
    this.http.post(`${API}?action=logout`, {}).subscribe({ error: () => {} });
    this._token.set(null);
    this._student.set(null);
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(STUDENT_KEY);
    this.router.navigate(['/login']);
  }

  updateStudent(student: StudentAccount): void {
    this._student.set(student);
    localStorage.setItem(STUDENT_KEY, JSON.stringify(student));
  }
}
