import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators, AbstractControl, ValidationErrors } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { MatTabsModule } from '@angular/material/tabs';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { GuardianNotifyPrefs } from '../../core/models/kursant.models';

function passwordMatch(control: AbstractControl): ValidationErrors | null {
  const pwd  = control.get('new_password');
  const conf = control.get('confirm');
  return pwd?.value && conf?.value && pwd.value !== conf.value ? { passwordMismatch: true } : null;
}

/**
 * Zakładka opiekuna „Zarządzaj dostępem dziecka" — odpowiednik sekcji
 * karty30/ti/kursant/parent.php:1146-1269 (reset/blokada hasła dziecka,
 * powiadomienia, własne hasło opiekuna). Widoczna tylko dla roli parent.
 */
@Component({
  selector: 'app-dostep',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule,
    MatButtonModule, MatFormFieldModule, MatInputModule,
    MatCheckboxModule, MatSnackBarModule, MatTabsModule,
  ],
  template: `
    <div class="page-header">
      <h1>Dostęp opiekuna</h1>
      <p class="subtitle">Zarządzaj kontem dziecka, powiadomieniami i własnym hasłem</p>
    </div>

    <mat-tab-group animationDuration="200ms" aria-label="Sekcje dostępu opiekuna">
      <mat-tab label="Konto dziecka">
        <section class="k-card tab-content">
          <h2 class="k-card-title">Logowanie dziecka do panelu</h2>
          @if (newChildPassword()) {
            <div class="k-alert warning" role="alert">
              <span class="material-symbols-outlined" aria-hidden="true">key</span>
              <div>
                <strong>Nowe hasło dziecka — zapisz teraz, nie pokażemy go ponownie:</strong>
                <div class="new-pass">{{ newChildPassword() }}</div>
              </div>
            </div>
          }
          <div class="btn-row">
            <button mat-stroked-button color="warn" [disabled]="busy()" (click)="resetChildPassword()">
              Ustaw nowe hasło dziecka
            </button>
            <button mat-stroked-button [disabled]="busy()" (click)="toggleBlock()">
              {{ blocked() ? 'Przywróć dostęp dziecka' : 'Wstrzymaj dostęp dziecka' }}
            </button>
          </div>
          <p class="text-muted text-sm">Tu zarządzasz logowaniem dziecka do panelu (hasło, wstrzymanie dostępu). Twój dostęp opiekuna pozostaje aktywny niezależnie.</p>
        </section>
      </mat-tab>

      <mat-tab label="Powiadomienia">
        <section class="k-card tab-content">
          <h2 class="k-card-title">Powiadomienia dla opiekuna</h2>
          <form [formGroup]="notifyForm" (ngSubmit)="saveNotify()">
            <mat-checkbox formControlName="parent_notify_absence">Nieobecność dziecka</mat-checkbox><br>
            <mat-checkbox formControlName="parent_notify_grade">Nowa ocena</mat-checkbox><br>
            <mat-checkbox formControlName="parent_notify_messages">Nowa wiadomość od prowadzącego</mat-checkbox><br>
            <mat-checkbox formControlName="parent_notify_lessons">Zmiana terminu lekcji (SMS)</mat-checkbox>
            <div class="btn-row">
              <button mat-flat-button color="primary" type="submit" [disabled]="busy()">Zapisz</button>
            </div>
          </form>
        </section>
      </mat-tab>

      <mat-tab label="Moje hasło">
        <section class="k-card tab-content">
          <h2 class="k-card-title">Zmiana hasła opiekuna</h2>
          <form [formGroup]="pwdForm" (ngSubmit)="changePassword()">
            <mat-form-field appearance="fill" class="w-100">
              <mat-label>Nowe hasło (min. 8 znaków)</mat-label>
              <input matInput type="password" formControlName="new_password" autocomplete="new-password">
            </mat-form-field>
            <mat-form-field appearance="fill" class="w-100">
              <mat-label>Powtórz nowe hasło</mat-label>
              <input matInput type="password" formControlName="confirm" autocomplete="new-password">
            </mat-form-field>
            @if (pwdForm.hasError('passwordMismatch') && pwdForm.get('confirm')?.touched) {
              <p class="k-alert danger">Hasła nie są identyczne.</p>
            }
            <button mat-flat-button color="primary" type="submit" [disabled]="busy() || pwdForm.invalid">Zmień hasło</button>
          </form>
        </section>
      </mat-tab>
    </mat-tab-group>
  `,
  styles: [`
    .tab-content { margin-top: 1rem; }
    .k-card-title { font-size: 1rem; font-weight: 600; margin: 0 0 1rem; }
    .btn-row { display: flex; gap: .75rem; margin-top: 1rem; flex-wrap: wrap; }
    .new-pass { font-family: monospace; font-weight: 700; color: #b45309; margin-top: .25rem; }
    .w-100 { width: 100%; }
  `],
})
export class DostepComponent implements OnInit {
  private api = inject(KursantApiService);
  private fb  = inject(FormBuilder);
  private snack = inject(MatSnackBar);

  busy = signal(false);
  newChildPassword = signal<string | null>(null);
  blocked = signal(false);

  notifyForm = this.fb.nonNullable.group({
    parent_notify_absence:  [true],
    parent_notify_grade:    [true],
    parent_notify_messages: [true],
    parent_notify_lessons:  [true],
  });

  pwdForm = this.fb.nonNullable.group({
    new_password: ['', [Validators.required, Validators.minLength(8)]],
    confirm:      ['', Validators.required],
  }, { validators: passwordMatch });

  ngOnInit(): void {
    this.api.getGuardianNotifyPrefs().subscribe({
      next: res => {
        if (res.success && res.data) {
          const d: GuardianNotifyPrefs = res.data;
          this.notifyForm.patchValue({
            parent_notify_absence: d.parent_notify_absence,
            parent_notify_grade: d.parent_notify_grade,
            parent_notify_messages: d.parent_notify_messages,
            parent_notify_lessons: d.parent_notify_lessons,
          });
          this.blocked.set(d.child_access_blocked);
        }
      },
      error: () => {},
    });
  }

  private run(obs: any, okMsg: string): void {
    this.busy.set(true);
    obs.subscribe({
      next: (res: any) => {
        this.busy.set(false);
        this.snack.open(res.success ? okMsg : (res.error ?? 'Wystąpił błąd.'), 'OK', { duration: 4000 });
      },
      error: (err: any) => {
        this.busy.set(false);
        this.snack.open(err?.error?.error ?? 'Wystąpił błąd połączenia.', 'OK', { duration: 4000 });
      },
    });
  }

  resetChildPassword(): void {
    if (!confirm('Ustawić nowe hasło dziecka? Dotychczasowe przestanie działać.')) return;
    this.busy.set(true);
    this.api.guardianChildAccess('reset_password').subscribe({
      next: res => {
        this.busy.set(false);
        if (res.success && res.data?.new_password) this.newChildPassword.set(res.data.new_password);
        this.snack.open(res.message ?? 'Hasło ustawione.', 'OK', { duration: 4000 });
      },
      error: err => { this.busy.set(false); this.snack.open(err?.error?.error ?? 'Błąd.', 'OK', { duration: 4000 }); },
    });
  }

  toggleBlock(): void {
    const next = !this.blocked();
    this.busy.set(true);
    this.api.guardianChildAccess(next ? 'block' : 'unblock').subscribe({
      next: res => {
        this.busy.set(false);
        if (res.success) this.blocked.set(next);
        this.snack.open(res.message ?? 'Zapisano.', 'OK', { duration: 4000 });
      },
      error: err => { this.busy.set(false); this.snack.open(err?.error?.error ?? 'Błąd.', 'OK', { duration: 4000 }); },
    });
  }

  saveNotify(): void {
    this.run(this.api.saveGuardianNotifyPrefs(this.notifyForm.getRawValue()), 'Ustawienia powiadomień zapisane.');
  }

  changePassword(): void {
    if (this.pwdForm.invalid) { this.pwdForm.markAllAsTouched(); return; }
    const { new_password } = this.pwdForm.getRawValue();
    this.busy.set(true);
    this.api.guardianChangePassword(new_password).subscribe({
      next: res => {
        this.busy.set(false);
        this.snack.open(res.message ?? 'Hasło zmienione.', 'OK', { duration: 4000 });
        if (res.success) this.pwdForm.reset();
      },
      error: err => { this.busy.set(false); this.snack.open(err?.error?.error ?? 'Błąd.', 'OK', { duration: 4000 }); },
    });
  }
}
