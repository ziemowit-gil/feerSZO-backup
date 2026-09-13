import { Component, signal, inject, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { InstructorAuthService } from '../../../core/auth/instructor-auth.service';

type Stage = 'choice' | 'password' | 'totp';

/**
 * Logowanie prowadzącego — na życzenie zaczyna się od jawnego wyboru metody
 * (Microsoft 365 / hasło lokalne), zamiast pokazywać oba naraz na jednym
 * ekranie. Logowanie hasłem jest zawsze dwuetapowe (e-mail+hasło, potem kod
 * TOTP), bo 2FA jest obowiązkowe dla każdego konta dydaktyka (patrz
 * dyd_require() w karty30/ti/dydaktyk/auth.php). Zakładanie 2FA od zera (QR)
 * nie jest tu obsługiwane — konto musi mieć je już aktywne z klasycznego panelu.
 */
@Component({
  selector: 'app-instructor-login',
  standalone: true,
  imports: [CommonModule, FormsModule, MatFormFieldModule, MatInputModule, MatButtonModule, MatCheckboxModule, MatProgressSpinnerModule],
  template: `
    <div class="page">
      <section class="k-card form-card">
        <div class="logo" aria-hidden="true"><span class="material-symbols-outlined">person_book</span></div>
        <h1 class="title">Panel prowadzącego</h1>
        <p class="subtitle">
          @switch (stage()) {
            @case ('choice') { Jak chcesz się zalogować? }
            @case ('password') { Zaloguj się danymi z konta SZO. }
            @default { Podaj kod z aplikacji uwierzytelniającej. }
          }
        </p>

        @if (error()) {
          <div class="k-alert danger" role="alert" aria-live="assertive">
            <span class="material-symbols-outlined" aria-hidden="true">error</span>
            <span>{{ error() }}</span>
          </div>
        }

        @if (stage() === 'choice') {
          <a mat-flat-button href="/auth/ms365_prowadzacy.php" class="m365-btn">
            <span class="material-symbols-outlined" aria-hidden="true">badge</span>
            Zaloguj przez Microsoft 365
          </a>
          <div class="divider"><span>lub</span></div>
          <button mat-stroked-button type="button" class="submit" (click)="stage.set('password')">
            <span class="material-symbols-outlined" aria-hidden="true">password</span>
            Zaloguj hasłem
          </button>
        }

        @if (stage() === 'password') {
          <form (ngSubmit)="onPasswordSubmit()" novalidate>
            <mat-form-field appearance="fill" class="field">
              <mat-label>E-mail</mat-label>
              <input matInput type="email" [(ngModel)]="email" name="email" autocomplete="username" required>
            </mat-form-field>
            <mat-form-field appearance="fill" class="field">
              <mat-label>Hasło</mat-label>
              <input matInput type="password" [(ngModel)]="password" name="password" autocomplete="current-password" required>
            </mat-form-field>
            <button mat-flat-button type="submit" class="submit" [disabled]="loading()" [attr.aria-busy]="loading()">
              @if (loading()) { <mat-progress-spinner diameter="20" mode="indeterminate" aria-label="Logowanie…"></mat-progress-spinner> } @else { Dalej }
            </button>
            <button type="button" class="back" (click)="stage.set('choice'); error.set(null)">Wróć</button>
          </form>
        }

        @if (stage() === 'totp') {
          <form (ngSubmit)="onTotpSubmit()" novalidate>
            <mat-form-field appearance="fill" class="field">
              <mat-label>Kod (aplikacja lub kod zapasowy)</mat-label>
              <input matInput type="text" inputmode="numeric" [(ngModel)]="code" name="code" required autofocus>
            </mat-form-field>
            <mat-checkbox [(ngModel)]="remember" name="remember" class="remember">Zapamiętaj mnie</mat-checkbox>
            <button mat-flat-button type="submit" class="submit" [disabled]="loading()" [attr.aria-busy]="loading()">
              @if (loading()) { <mat-progress-spinner diameter="20" mode="indeterminate" aria-label="Weryfikacja…"></mat-progress-spinner> } @else { Zaloguj się }
            </button>
            <button type="button" class="back" (click)="stage.set('password'); error.set(null)">Wróć</button>
          </form>
        }
      </section>
    </div>
  `,
  styles: [`
    /* To samo tło co ekrany wejścia całego SZO (includes/auth_screen.php,
       auth_screen_bg_layers()) — kolor marki + geometryczny wzór SVG + skos. */
    .page {
      min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem;
      background-color: #DC2626;
      background-image:
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='420' height='420' viewBox='0 0 420 420'%3E%3Cg fill='%23000' fill-opacity='.055'%3E%3Crect x='24' y='40' width='120' height='120' rx='8'/%3E%3Ccircle cx='330' cy='96' r='58'/%3E%3Crect x='210' y='250' width='150' height='150' rx='8'/%3E%3Cpath d='M0 210l70-70v46l-24 24zm52 132l96-96v46l-50 50z'/%3E%3Cpath d='M300 0l60 60-24 24-60-60z'/%3E%3C/g%3E%3C/svg%3E"),
        repeating-linear-gradient(135deg, rgba(0,0,0,.045) 0 3px, transparent 3px 26px);
      background-size: 420px 420px, auto;
      background-attachment: fixed;
    }
    .form-card { width: 100%; max-width: 380px; background: #fff; }
    .logo { display: flex; justify-content: center; margin-bottom: .75rem;
      .material-symbols-outlined { font-size: 2.5rem; color: var(--c-brand); }
    }
    .title { text-align: center; font-size: 1.35rem; margin-bottom: .25rem; }
    .subtitle { text-align: center; color: var(--c-text-muted); font-size: .9rem; margin: 0 0 1.25rem; }
    .field { width: 100%; }
    .remember { display: block; margin-bottom: 1rem; }
    .submit { width: 100%; }
    .back { width: 100%; margin-top: .5rem; background: none; border: none; color: var(--c-link); cursor: pointer; font-size: .9rem; }

    .divider {
      display: flex;
      align-items: center;
      gap: .75rem;
      color: #9ca3af;
      font-size: .8rem;
      margin: 1rem 0;

      &::before, &::after { content: ''; flex: 1; height: 1px; background: #e5e7eb; }
    }

    .m365-btn {
      width: 100%;
      height: 2.75rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: .5rem;
    }
  `],
})
export class InstructorLoginComponent implements OnInit {
  private auth   = inject(InstructorAuthService);
  private router = inject(Router);
  private route  = inject(ActivatedRoute);

  stage   = signal<Stage>('choice');
  loading = signal(false);
  error   = signal<string | null>(null);

  email    = '';
  password = '';
  code     = '';
  remember = false;
  private pendingToken = '';

  private static readonly M365_ERRORS: Record<string, string> = {
    no_account:  'To konto Microsoft nie jest powiązane z żadnym kontem prowadzącego. Zaloguj się e-mailem i hasłem.',
    no_access:   'Konto zostało rozpoznane, ale nie ma dostępu do panelu prowadzącego.',
    unavailable: 'Logowanie przez Microsoft 365 jest obecnie niedostępne.',
  };

  ngOnInit(): void {
    const params = this.route.snapshot.queryParamMap;
    const pending = params.get('pending_token');
    if (pending) {
      this.pendingToken = pending;
      this.stage.set('totp');
      return;
    }
    const code = params.get('m365_error');
    if (code) this.error.set(InstructorLoginComponent.M365_ERRORS[code] ?? 'Nie udało się zalogować przez Microsoft 365.');
  }

  onPasswordSubmit(): void {
    if (!this.email || !this.password) return;
    this.loading.set(true);
    this.error.set(null);
    this.auth.login(this.email, this.password).subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data?.pending_token) {
          this.pendingToken = res.data.pending_token;
          this.stage.set('totp');
        } else {
          this.error.set(res.error || 'Nie udało się zalogować.');
        }
      },
      error: err => {
        this.loading.set(false);
        this.error.set(err?.error?.error || 'Nie udało się zalogować.');
      },
    });
  }

  onTotpSubmit(): void {
    if (!this.code) return;
    this.loading.set(true);
    this.error.set(null);
    this.auth.verifyTotp(this.pendingToken, this.code, this.remember).subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success) this.router.navigate(['/prowadzacy']);
        else this.error.set(res.error || 'Nieprawidłowy kod.');
      },
      error: err => {
        this.loading.set(false);
        this.error.set(err?.error?.error || 'Nieprawidłowy kod.');
      },
    });
  }
}
