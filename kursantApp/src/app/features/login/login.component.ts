import { Component, signal, inject } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { AuthService } from '../../core/auth/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, RouterLink,
    MatFormFieldModule, MatInputModule, MatButtonModule,
    MatCheckboxModule, MatProgressSpinnerModule,
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
          <p class="login-subtitle">Zaloguj się, aby kontynuować naukę</p>

          <!-- Error region -->
          @if (error()) {
            <div class="k-alert danger"
                 role="alert"
                 aria-live="assertive"
                 id="login-error">
              <span class="material-symbols-outlined" aria-hidden="true">error</span>
              <span>{{ error() }}</span>
            </div>
          }

          <form [formGroup]="form"
                (ngSubmit)="onSubmit()"
                novalidate
                aria-describedby="login-error">
            <!-- Login -->
            <mat-form-field appearance="fill" class="login-field">
              <mat-label>Login lub e-mail</mat-label>
              <input matInput
                     formControlName="login"
                     type="text"
                     id="login-input"
                     autocomplete="username"
                     [attr.aria-describedby]="loginErrors() ? 'login-err' : null"
                     [attr.aria-invalid]="loginErrors() ? 'true' : null">
              @if (loginErrors()) {
                <mat-error id="login-err">{{ loginErrors() }}</mat-error>
              }
            </mat-form-field>

            <!-- Password -->
            <mat-form-field appearance="fill" class="login-field">
              <mat-label>Hasło</mat-label>
              <input matInput
                     formControlName="password"
                     [type]="showPwd() ? 'text' : 'password'"
                     id="password-input"
                     autocomplete="current-password"
                     [attr.aria-describedby]="pwdErrors() ? 'pwd-err' : null"
                     [attr.aria-invalid]="pwdErrors() ? 'true' : null">
              <button matIconSuffix
                      type="button"
                      mat-icon-button
                      [attr.aria-label]="showPwd() ? 'Ukryj hasło' : 'Pokaż hasło'"
                      [attr.aria-pressed]="showPwd()"
                      (click)="togglePwd()">
                <span class="material-symbols-outlined" aria-hidden="true">
                  {{ showPwd() ? 'visibility_off' : 'visibility' }}
                </span>
              </button>
              @if (pwdErrors()) {
                <mat-error id="pwd-err">{{ pwdErrors() }}</mat-error>
              }
            </mat-form-field>

            <!-- Remember me -->
            <div class="login-remember">
              <mat-checkbox formControlName="remember" id="remember-me">
                Zapamiętaj mnie
              </mat-checkbox>
            </div>

            <!-- Submit -->
            <button mat-flat-button
                    type="submit"
                    class="login-submit"
                    [disabled]="loading()"
                    [attr.aria-busy]="loading()">
              @if (loading()) {
                <mat-progress-spinner diameter="20" mode="indeterminate" aria-label="Logowanie…"></mat-progress-spinner>
              } @else {
                Zaloguj się
              }
            </button>
          </form>

          <!-- Other panels -->
          <nav aria-label="Inne panele" class="login-links">
            <a href="/karty30/ti/kursant/parent.php" class="login-link">
              <span class="material-symbols-outlined" aria-hidden="true">family_restroom</span>
              Panel rodzica / opiekuna
            </a>
            <a href="/karty30/ti/kursant/pfron.php" class="login-link">
              <span class="material-symbols-outlined" aria-hidden="true">accessibility</span>
              Portal PFRON
            </a>
            <a href="/karty30/ti/dydaktyk/login.php" class="login-link">
              <span class="material-symbols-outlined" aria-hidden="true">person_book</span>
              Panel prowadzącego
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
      background: #0f0f1a;
    }

    .login-art {
      flex: 1;
      background: linear-gradient(135deg, #12121f 0%, #1a1a2e 100%);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 3rem;
      border-right: 1px solid rgba(255,255,255,.07);

      @media (max-width: 768px) { display: none; }
    }

    .terminal-window {
      width: 100%;
      max-width: 420px;
      background: #0a0a14;
      border-radius: .875rem;
      overflow: hidden;
      border: 1px solid rgba(255,255,255,.1);
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
      .term-prompt { color: #e05a1e; }
      .term-cmd    { color: #e8e8f0; }
      .term-ok     { color: #22c55e; }
      .term-out    { color: rgba(255,255,255,.5); padding-left: 1rem; }
      .term-hl     { color: #93c5fd; }
      .blink       { animation: blink 1s step-end infinite; }
    }

    @keyframes blink { 50% { opacity: 0; } }

    .login-tagline {
      margin-top: 2rem;
      color: rgba(255,255,255,.4);
      font-size: 1.1rem;
      text-align: center;
      line-height: 1.6;
    }

    .login-form-panel {
      width: 100%;
      max-width: 460px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem 1.5rem;

      @media (max-width: 768px) { max-width: none; }
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
        color: #e05a1e;
      }
    }

    .login-title {
      text-align: center;
      font-size: 1.75rem;
      font-weight: 700;
      margin: 0 0 .35rem;
    }

    .login-subtitle {
      text-align: center;
      color: rgba(255,255,255,.45);
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
      background: #c2410c !important;
      color: #fff !important;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: .5rem;

      &:disabled { opacity: .6; }
    }

    .login-links {
      display: flex;
      flex-direction: column;
      gap: .5rem;
      border-top: 1px solid rgba(255,255,255,.08);
      padding-top: 1.25rem;
    }

    .login-link {
      display: flex;
      align-items: center;
      gap: .5rem;
      color: rgba(255,255,255,.5);
      font-size: .875rem;
      text-decoration: none;
      padding: .4rem .5rem;
      border-radius: .4rem;
      transition: color .15s, background .15s;

      .material-symbols-outlined { font-size: 1.1rem; }

      &:hover, &:focus-visible {
        color: rgba(255,255,255,.85);
        background: rgba(255,255,255,.05);
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

  loading  = signal(false);
  error    = signal<string | null>(null);
  showPwd  = signal(false);

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

  onSubmit(): void {
    this.form.markAllAsTouched();
    if (this.form.invalid || this.loading()) return;

    const { login, password, remember } = this.form.getRawValue();
    this.loading.set(true);
    this.error.set(null);

    this.auth.login(login, password, remember).subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success) {
          this.router.navigate([res.must_change_password ? '/ustawienia' : '/dane']);
        } else {
          this.error.set(res.error ?? 'Błąd logowania.');
        }
      },
      error: err => {
        this.loading.set(false);
        const msg = err?.error?.error ?? 'Błąd połączenia z serwerem. Spróbuj ponownie.';
        this.error.set(msg);
      },
    });
  }
}
