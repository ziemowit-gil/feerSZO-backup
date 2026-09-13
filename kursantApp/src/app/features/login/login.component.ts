import { Component, signal, inject } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { AuthService } from '../../core/auth/auth.service';
import { ParentOtpChild } from '../../core/models/kursant.models';

/** Rola wybrana na ekranie logowania — odpowiednik trzech stron logowania
 *  klasycznego panelu (login.php / parent_login.php / authp_login.php). */
type LoginMode = 'student' | 'parent' | 'authp';
type ParentStage = 'password' | 'sms-phone' | 'sms-code' | 'sms-choose';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, FormsModule, RouterLink,
    MatFormFieldModule, MatInputModule, MatButtonModule,
    MatCheckboxModule, MatProgressSpinnerModule, MatButtonToggleModule,
  ],
  template: `
    <div class="login-layout" role="main">
      <!-- Terminal art panel (decorative, hidden from AT) -->
      <aside class="login-art" aria-hidden="true">
        <div class="terminal-window">
          <div class="terminal-bar">
            <span class="dot red"></span>
            <span class="dot yellow"></span>
            <span class="dot green"></span>
            <span class="terminal-title">kursant&#64;szo-feer ~ $</span>
          </div>
          <div class="terminal-body">
            <p><span class="term-prompt">$ </span><span class="term-cmd">connect --user kursant</span></p>
            <p class="term-ok">✓ Połączono z SZO FEER</p>
            <p><span class="term-prompt">$ </span><span class="term-cmd">ls kursy/</span></p>
            <p class="term-out">kurs_angielski/&nbsp;&nbsp;matematyka/&nbsp;&nbsp;programowanie/</p>
            <p><span class="term-prompt">$ </span><span class="term-cmd">status lekcje --dzisiaj</span></p>
            <p class="term-out">Następna lekcja: <span class="term-hl">18:00 Angielski B2</span></p>
            <p><span class="term-prompt">$ </span><span class="term-cmd blink">_</span></p>
          </div>
        </div>
        <p class="login-tagline">Zarządzaj swoją nauką<br>w jednym miejscu.</p>
      </aside>

      <!-- Login form -->
      <section class="login-form-panel">
        <div class="login-form-wrap">
          <div class="login-logo" aria-hidden="true">
            <span class="material-symbols-outlined">school</span>
          </div>
          <h1 class="login-title">Panel Kursanta</h1>
          <p class="login-subtitle">{{ subtitle() }}</p>

          <!-- Wybór roli: kursant / rodzic / osoba upoważniona -->
          <mat-button-toggle-group class="login-mode" [value]="mode()" (change)="setMode($event.value)" aria-label="Loguję się jako">
            <mat-button-toggle value="student">Kursant</mat-button-toggle>
            <mat-button-toggle value="parent">Rodzic</mat-button-toggle>
            <mat-button-toggle value="authp">Upoważniony</mat-button-toggle>
          </mat-button-toggle-group>

          <!-- Error region -->
          @if (error()) {
            <div class="k-alert danger" role="alert" aria-live="assertive" id="login-error">
              <span class="material-symbols-outlined" aria-hidden="true">error</span>
              <span>{{ error() }}</span>
            </div>
          }
          @if (info()) {
            <div class="k-alert info" role="status" aria-live="polite">
              <span class="material-symbols-outlined" aria-hidden="true">info</span>
              <span>{{ info() }}</span>
            </div>
          }

          <!-- Kursant / Osoba upoważniona: login + hasło -->
          @if (mode() !== 'parent' || parentStage() === 'password') {
            <form [formGroup]="form" (ngSubmit)="onSubmit()" novalidate aria-describedby="login-error">
              <mat-form-field appearance="fill" class="login-field">
                <mat-label>Login lub e-mail</mat-label>
                <input matInput formControlName="login" type="text" id="login-input" autocomplete="username"
                       [attr.aria-describedby]="loginErrors() ? 'login-err' : null"
                       [attr.aria-invalid]="loginErrors() ? 'true' : null">
                @if (loginErrors()) { <mat-error id="login-err">{{ loginErrors() }}</mat-error> }
              </mat-form-field>

              <mat-form-field appearance="fill" class="login-field">
                <mat-label>Hasło</mat-label>
                <input matInput formControlName="password" [type]="showPwd() ? 'text' : 'password'" id="password-input"
                       autocomplete="current-password"
                       [attr.aria-describedby]="pwdErrors() ? 'pwd-err' : null"
                       [attr.aria-invalid]="pwdErrors() ? 'true' : null">
                <button matIconSuffix type="button" mat-icon-button
                        [attr.aria-label]="showPwd() ? 'Ukryj hasło' : 'Pokaż hasło'"
                        [attr.aria-pressed]="showPwd()" (click)="togglePwd()">
                  <span class="material-symbols-outlined" aria-hidden="true">{{ showPwd() ? 'visibility_off' : 'visibility' }}</span>
                </button>
                @if (pwdErrors()) { <mat-error id="pwd-err">{{ pwdErrors() }}</mat-error> }
              </mat-form-field>

              @if (mode() !== 'authp') {
                <div class="login-remember">
                  <mat-checkbox formControlName="remember" id="remember-me">Zapamiętaj mnie</mat-checkbox>
                </div>
              }

              <button mat-flat-button type="submit" class="login-submit" [disabled]="loading()" [attr.aria-busy]="loading()">
                @if (loading()) {
                  <mat-progress-spinner diameter="20" mode="indeterminate" aria-label="Logowanie…"></mat-progress-spinner>
                } @else { Zaloguj się }
              </button>
            </form>

            @if (mode() === 'parent') {
              <button type="button" class="login-switch-link" (click)="parentStage.set('sms-phone'); error.set(null)">
                Nie masz hasła? Zaloguj się kodem SMS
              </button>
            }
          }

          <!-- Rodzic: logowanie kodem SMS (krok 1 — telefon) -->
          @if (mode() === 'parent' && parentStage() === 'sms-phone') {
            <form (ngSubmit)="onOtpSend()" novalidate>
              <mat-form-field appearance="fill" class="login-field">
                <mat-label>Numer telefonu opiekuna</mat-label>
                <input matInput type="tel" [(ngModel)]="otpPhone" name="phone" autocomplete="tel" required>
              </mat-form-field>
              <button mat-flat-button type="submit" class="login-submit" [disabled]="loading()">
                @if (loading()) { <mat-progress-spinner diameter="20" mode="indeterminate"></mat-progress-spinner> } @else { Wyślij kod SMS }
              </button>
            </form>
            <button type="button" class="login-switch-link" (click)="parentStage.set('password'); error.set(null)">Wróć do logowania hasłem</button>
          }

          <!-- Rodzic: logowanie kodem SMS (krok 2 — kod) -->
          @if (mode() === 'parent' && parentStage() === 'sms-code') {
            <form (ngSubmit)="onOtpVerify()" novalidate>
              <mat-form-field appearance="fill" class="login-field">
                <mat-label>Kod z SMS (6 cyfr)</mat-label>
                <input matInput type="text" inputmode="numeric" [(ngModel)]="otpCode" name="code" required maxlength="6">
              </mat-form-field>
              <button mat-flat-button type="submit" class="login-submit" [disabled]="loading()">
                @if (loading()) { <mat-progress-spinner diameter="20" mode="indeterminate"></mat-progress-spinner> } @else { Potwierdź kod }
              </button>
            </form>
          }

          <!-- Rodzic: wybór dziecka, gdy numer pasuje do >1 konta -->
          @if (mode() === 'parent' && parentStage() === 'sms-choose') {
            <ul class="login-choose-child">
              @for (kid of otpChildren(); track kid.id) {
                <li>
                  <button type="button" class="login-link" (click)="onSelectChild(kid.id)">
                    <span class="material-symbols-outlined" aria-hidden="true">person</span>
                    {{ kid.name }}
                  </button>
                </li>
              }
            </ul>
          }

          <!-- Other panels -->
          <nav aria-label="Inne panele" class="login-links">
            <a href="/karty30/ti/kursant/pfron.php" class="login-link">
              <span class="material-symbols-outlined" aria-hidden="true">accessibility</span>
              Portal PFRON
            </a>
            <a href="/karty30/ti/dydaktyk/login.php" class="login-link">
              <span class="material-symbols-outlined" aria-hidden="true">person_book</span>
              Panel prowadzącego
            </a>
            <a routerLink="/logowanie-prowadzacy" class="login-link">
              <span class="material-symbols-outlined" aria-hidden="true">science</span>
              Panel prowadzącego (nowy, beta)
            </a>
          </nav>
        </div>
      </section>
    </div>
  `,
  styles: [`
    .login-layout {
      display: flex;
      min-height: 100vh;
      background: #f1f5f9;
    }

    /* Panel z terminalem — celowo ciemny (terminal = czarne tło) */
    .login-art {
      flex: 1;
      background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 3rem;

      @media (max-width: 768px) { display: none; }
    }

    .terminal-window {
      width: 100%;
      max-width: 420px;
      background: #0a0a14;
      border-radius: .875rem;
      overflow: hidden;
      border: 1px solid rgba(255,255,255,.12);
      font-family: 'Courier New', monospace;
      font-size: .85rem;
    }

    .terminal-bar {
      display: flex;
      align-items: center;
      gap: .4rem;
      padding: .6rem 1rem;
      background: #18182a;
      border-bottom: 1px solid rgba(255,255,255,.08);

      .dot {
        width: 12px; height: 12px;
        border-radius: 50%;
        &.red    { background: #ef4444; }
        &.yellow { background: #eab308; }
        &.green  { background: #22c55e; }
      }

      .terminal-title { margin-left: .5rem; color: rgba(255,255,255,.4); font-size: .8rem; }
    }

    .terminal-body {
      padding: 1.25rem 1.25rem 1.5rem;
      line-height: 1.8;

      p { margin: 0; }
      .term-prompt { color: #fb923c; }
      .term-cmd    { color: #e2e8f0; }
      .term-ok     { color: #4ade80; }
      .term-out    { color: rgba(255,255,255,.5); padding-left: 1rem; }
      .term-hl     { color: #93c5fd; }
      .blink       { animation: blink 1s step-end infinite; }
      @media (prefers-reduced-motion: reduce) { .blink { animation: none; } }
    }

    @keyframes blink { 50% { opacity: 0; } }

    .login-tagline {
      margin-top: 2rem;
      color: rgba(255,255,255,.45);
      font-size: 1.05rem;
      text-align: center;
      line-height: 1.6;
    }

    /* Jasny panel z formularzem */
    .login-form-panel {
      width: 100%;
      max-width: 480px;
      background: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2.5rem 2rem;
      box-shadow: -4px 0 24px rgba(0,0,0,.06);

      @media (max-width: 768px) {
        max-width: none;
        box-shadow: none;
        padding: 2rem 1.25rem;
      }
    }

    .login-form-wrap {
      width: 100%;
      max-width: 380px;
    }

    .login-logo {
      display: flex;
      justify-content: center;
      margin-bottom: 1rem;

      .material-symbols-outlined {
        font-size: 3.5rem;
        color: #2563eb;
      }
    }

    .login-title {
      text-align: center;
      font-size: 1.75rem;
      font-weight: 700;
      margin: 0 0 .35rem;
      color: #111827;
    }

    .login-subtitle {
      text-align: center;
      color: #6b7280;
      margin: 0 0 1.75rem;
      font-size: .9rem;
    }

    .login-field { display: block; margin-bottom: .75rem; }

    .login-remember { margin: .25rem 0 1.25rem; }

    .login-submit {
      width: 100%;
      height: 3rem;
      font-size: 1rem;
      font-weight: 600;
      background: #2563eb !important;
      color: #fff !important;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: .5rem;

      &:hover:not(:disabled) { background: #1d4ed8 !important; }
      &:disabled { opacity: .6; }
    }

    .login-mode {
      display: flex;
      width: 100%;
      margin-bottom: 1.25rem;

      ::ng-deep .mat-button-toggle { flex: 1; }
      ::ng-deep .mat-button-toggle-label-content { padding: 0 .5rem; font-size: .85rem; }
    }

    .login-switch-link {
      display: block;
      width: 100%;
      background: none;
      border: none;
      color: #2563eb;
      font-size: .85rem;
      text-align: center;
      cursor: pointer;
      padding: .5rem;
      margin-bottom: 1rem;

      &:hover, &:focus-visible { text-decoration: underline; }
    }

    .login-choose-child {
      list-style: none;
      margin: 0 0 1rem;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: .4rem;

      .login-link {
        width: 100%;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        cursor: pointer;
        font-size: .9rem;
      }
    }

    .login-links {
      display: flex;
      flex-direction: column;
      gap: .5rem;
      border-top: 1px solid #e5e7eb;
      padding-top: 1.25rem;
    }

    .login-link {
      display: flex;
      align-items: center;
      gap: .5rem;
      color: #6b7280;
      font-size: .875rem;
      text-decoration: none;
      padding: .4rem .5rem;
      border-radius: .4rem;
      transition: color .15s, background .15s;

      .material-symbols-outlined { font-size: 1.1rem; }

      &:hover, &:focus-visible {
        color: #111827;
        background: #f3f4f6;
      }
    }
  `],
})
export class LoginComponent {
  private fb   = inject(FormBuilder);
  private auth = inject(AuthService);
  private router = inject(Router);

  form = this.fb.nonNullable.group({
    login:    ['', [Validators.required, Validators.minLength(2)]],
    password: ['', [Validators.required, Validators.minLength(4)]],
    remember: [false],
  });

  mode        = signal<LoginMode>('student');
  parentStage = signal<ParentStage>('password');
  loading     = signal(false);
  error       = signal<string | null>(null);
  info        = signal<string | null>(null);
  showPwd     = signal(false);

  otpPhone    = '';
  otpCode     = '';
  otpChildren = signal<ParentOtpChild[]>([]);

  subtitle = () => ({
    student: 'Zaloguj się, aby kontynuować naukę',
    parent:  'Wgląd opiekuna w konto dziecka',
    authp:   'Wgląd osoby upoważnionej (tylko odczyt)',
  })[this.mode()];

  setMode(mode: LoginMode): void {
    this.mode.set(mode);
    this.parentStage.set('password');
    this.error.set(null);
    this.info.set(null);
    this.form.reset({ login: '', password: '', remember: false });
  }

  togglePwd(): void { this.showPwd.set(!this.showPwd()); }

  loginErrors = () => {
    const c = this.form.controls.login;
    if (!c.touched || c.valid) return null;
    if (c.hasError('required')) return 'Login jest wymagany';
    if (c.hasError('minlength')) return 'Login jest zbyt krótki';
    return null;
  };

  pwdErrors = () => {
    const c = this.form.controls.password;
    if (!c.touched || c.valid) return null;
    if (c.hasError('required')) return 'Hasło jest wymagane';
    return null;
  };

  private afterLogin(res: { success: boolean; must_change_password?: boolean; error?: string }): void {
    this.loading.set(false);
    if (res.success) {
      this.router.navigate([res.must_change_password ? '/ustawienia' : '/dane']);
    } else {
      this.error.set(res.error ?? 'Błąd logowania.');
    }
  }

  private onError(err: any): void {
    this.loading.set(false);
    this.error.set(err?.error?.error ?? 'Błąd połączenia z serwerem. Spróbuj ponownie.');
  }

  onSubmit(): void {
    this.form.markAllAsTouched();
    if (this.form.invalid || this.loading()) return;

    const { login, password, remember } = this.form.getRawValue();
    this.loading.set(true);
    this.error.set(null);

    const req$ = this.mode() === 'parent'
      ? this.auth.parentLogin(login, password, remember)
      : this.mode() === 'authp'
        ? this.auth.authpLogin(login, password)
        : this.auth.login(login, password, remember);

    req$.subscribe({ next: res => this.afterLogin(res), error: err => this.onError(err) });
  }

  onOtpSend(): void {
    if (!this.otpPhone.trim() || this.loading()) return;
    this.loading.set(true);
    this.error.set(null);
    this.auth.parentOtpSend(this.otpPhone.trim()).subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success) {
          this.info.set('Kod SMS został wysłany — wpisz go poniżej.');
          this.parentStage.set('sms-code');
        } else {
          this.error.set(res.error ?? 'Nie udało się wysłać kodu.');
        }
      },
      error: err => this.onError(err),
    });
  }

  onOtpVerify(): void {
    if (!this.otpCode.trim() || this.loading()) return;
    this.loading.set(true);
    this.error.set(null);
    this.auth.parentOtpVerify(this.otpCode.trim()).subscribe({
      next: res => {
        this.loading.set(false);
        if (!res.success) { this.error.set((res as any).error ?? 'Nieprawidłowy kod.'); return; }
        if ('token' in res) { this.afterLogin(res); return; }
        this.otpChildren.set(res.data.choose_child);
        this.parentStage.set('sms-choose');
      },
      error: err => this.onError(err),
    });
  }

  onSelectChild(studentId: number): void {
    this.loading.set(true);
    this.auth.parentSelectChild(studentId).subscribe({
      next: res => this.afterLogin(res),
      error: err => this.onError(err),
    });
  }
}
