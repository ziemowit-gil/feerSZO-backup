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
import { AuthService } from '../../core/auth/auth.service';
import { PushService } from '../../core/services/push.service';

function passwordMatch(control: AbstractControl): ValidationErrors | null {
  const pwd  = control.get('new_password');
  const conf = control.get('confirm');
  return pwd?.value && conf?.value && pwd.value !== conf.value
    ? { passwordMismatch: true }
    : null;
}

@Component({
  selector: 'app-ustawienia',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule,
    MatButtonModule, MatFormFieldModule, MatInputModule,
    MatCheckboxModule, MatSnackBarModule, MatTabsModule,
  ],
  template: `
    <div class="page-header">
      <h1>Ustawienia</h1>
      <p class="subtitle">Zarządzaj swoim kontem i powiadomieniami</p>
    </div>

    <mat-tab-group animationDuration="200ms" aria-label="Sekcje ustawień">
      <!-- Tab 1: Password -->
      <mat-tab label="Hasło">
        <section class="k-card tab-content" aria-labelledby="pwd-heading">
          <h2 class="k-card-title" id="pwd-heading">
            <span class="material-symbols-outlined" aria-hidden="true">lock</span>
            Zmiana hasła
          </h2>

          @if (pwdMsg()) {
            <div class="k-alert success" role="status" aria-live="polite">{{ pwdMsg() }}</div>
          }
          @if (pwdError()) {
            <div class="k-alert danger" role="alert">{{ pwdError() }}</div>
          }

          <form [formGroup]="pwdForm" (ngSubmit)="changePassword()" novalidate>
            <mat-form-field appearance="fill" style="width:100%;max-width:400px;margin-bottom:.75rem">
              <mat-label>Obecne hasło</mat-label>
              <input matInput formControlName="current_password"
                     [type]="showCurr() ? 'text' : 'password'"
                     id="curr-pwd" autocomplete="current-password"
                     [attr.aria-required]="true">
              <button matIconSuffix type="button" mat-icon-button
                      [attr.aria-label]="showCurr() ? 'Ukryj obecne hasło' : 'Pokaż obecne hasło'"
                      (click)="toggleCurr()">
                <span class="material-symbols-outlined" aria-hidden="true">
                  {{ showCurr() ? 'visibility_off' : 'visibility' }}
                </span>
              </button>
              @if (pwdForm.controls.current_password.invalid && pwdForm.controls.current_password.touched) {
                <mat-error>Obecne hasło jest wymagane</mat-error>
              }
            </mat-form-field>

            <mat-form-field appearance="fill" style="width:100%;max-width:400px;margin-bottom:.75rem">
              <mat-label>Nowe hasło</mat-label>
              <input matInput formControlName="new_password"
                     [type]="showNew() ? 'text' : 'password'"
                     id="new-pwd" autocomplete="new-password"
                     [attr.aria-required]="true">
              <button matIconSuffix type="button" mat-icon-button
                      [attr.aria-label]="showNew() ? 'Ukryj nowe hasło' : 'Pokaż nowe hasło'"
                      (click)="toggleNew()">
                <span class="material-symbols-outlined" aria-hidden="true">
                  {{ showNew() ? 'visibility_off' : 'visibility' }}
                </span>
              </button>
              @if (pwdForm.controls.new_password.invalid && pwdForm.controls.new_password.touched) {
                <mat-error>Hasło musi mieć min. 8 znaków</mat-error>
              }
            </mat-form-field>

            <mat-form-field appearance="fill" style="width:100%;max-width:400px;margin-bottom:1rem">
              <mat-label>Potwierdź nowe hasło</mat-label>
              <input matInput formControlName="confirm"
                     [type]="showNew() ? 'text' : 'password'"
                     id="conf-pwd" autocomplete="new-password"
                     [attr.aria-required]="true"
                     [attr.aria-describedby]="pwdForm.hasError('passwordMismatch') ? 'pwd-mismatch' : null">
              @if (pwdForm.hasError('passwordMismatch') && pwdForm.controls.confirm.touched) {
                <mat-error id="pwd-mismatch">Hasła nie są identyczne</mat-error>
              }
            </mat-form-field>

            <button mat-flat-button type="submit" [disabled]="changingPwd()">
              Zmień hasło
            </button>
          </form>
        </section>
      </mat-tab>

      <!-- Tab 2: Alias & Email -->
      <mat-tab label="Login / E-mail">
        <section class="k-card tab-content" aria-labelledby="alias-heading">
          <h2 class="k-card-title" id="alias-heading">
            <span class="material-symbols-outlined" aria-hidden="true">alternate_email</span>
            Alias logowania
          </h2>
          <p class="text-muted text-sm" style="margin:0 0 1rem">
            Możesz ustawić własny alias logowania zamiast systemowego loginu.
          </p>
          <form [formGroup]="aliasForm" (ngSubmit)="setAlias()" novalidate>
            <div style="display:flex;gap:.75rem;align-items:flex-start;max-width:400px">
              <mat-form-field appearance="fill" style="flex:1">
                <mat-label>Alias logowania</mat-label>
                <input matInput formControlName="alias" id="login-alias"
                       placeholder="np. jan.kowalski">
              </mat-form-field>
              <button mat-flat-button type="submit" style="margin-top:4px" [disabled]="savingAlias()">
                Zapisz
              </button>
            </div>
          </form>

          <hr class="k-divider">

          <h2 class="k-card-title" id="email-heading">
            <span class="material-symbols-outlined" aria-hidden="true">mail</span>
            Adres e-mail
          </h2>
          <form [formGroup]="emailForm" (ngSubmit)="changeEmail()" novalidate>
            <div style="display:flex;gap:.75rem;align-items:flex-start;max-width:400px">
              <mat-form-field appearance="fill" style="flex:1">
                <mat-label>Nowy adres e-mail</mat-label>
                <input matInput formControlName="email" id="email-field"
                       type="email" autocomplete="email">
                @if (emailForm.controls.email.invalid && emailForm.controls.email.touched) {
                  <mat-error>Podaj prawidłowy adres e-mail</mat-error>
                }
              </mat-form-field>
              <button mat-flat-button type="submit" style="margin-top:4px" [disabled]="savingEmail()">
                Zmień
              </button>
            </div>
          </form>
        </section>
      </mat-tab>

      <!-- Tab 3: Notifications -->
      <mat-tab label="Powiadomienia">
        <section class="k-card tab-content" aria-labelledby="push-heading">
          <h2 class="k-card-title" id="push-heading">
            <span class="material-symbols-outlined" aria-hidden="true">notification_add</span>
            Powiadomienia push
          </h2>

          @if (!push.isSupported) {
            <div class="k-alert info" role="note">
              <span class="material-symbols-outlined" aria-hidden="true">info</span>
              Twoja przeglądarka nie obsługuje powiadomień push.
            </div>
          }

          @if (push.isSupported && push.permDenied()) {
            <div class="k-alert warning" role="alert">
              <span class="material-symbols-outlined" aria-hidden="true">block</span>
              Powiadomienia zablokowane w przeglądarce. Odblokuj je w ustawieniach strony i odśwież stronę.
            </div>
          }

          @if (push.isSupported && !push.permDenied()) {
            <div class="push-row">
              <div>
                <p class="push-label">
                  {{ push.enabled() ? 'Powiadomienia push włączone' : 'Powiadomienia push wyłączone' }}
                </p>
                <p class="text-muted text-sm" style="margin:0">
                  Otrzymuj powiadomienia o wiadomościach i aktualnościach nawet gdy przeglądarka jest w tle.
                </p>
              </div>
              @if (push.enabled()) {
                <button mat-stroked-button
                        class="btn-push-off"
                        [disabled]="push.loading()"
                        (click)="disablePush()"
                        aria-label="Wyłącz powiadomienia push">
                  <span class="material-symbols-outlined" aria-hidden="true">notifications_off</span>
                  Wyłącz
                </button>
              } @else {
                <button mat-flat-button
                        [disabled]="push.loading()"
                        (click)="enablePush()"
                        aria-label="Włącz powiadomienia push">
                  <span class="material-symbols-outlined" aria-hidden="true">notifications_active</span>
                  Włącz
                </button>
              }
            </div>
          }
        </section>

        <section class="k-card tab-content" aria-labelledby="notif-heading">
          <h2 class="k-card-title" id="notif-heading">
            <span class="material-symbols-outlined" aria-hidden="true">notifications</span>
            Powiadomienia e-mail i SMS
          </h2>

          <form [formGroup]="notifForm" (ngSubmit)="saveNotifs()" novalidate>
            <fieldset class="notif-group">
              <legend class="notif-legend">Wiadomości</legend>
              <mat-checkbox formControlName="notify_email_messages" id="email-msg">
                Powiadomienia e-mail o nowych wiadomościach
              </mat-checkbox>
              <mat-checkbox formControlName="notify_sms_messages" id="sms-msg">
                Powiadomienia SMS o nowych wiadomościach
              </mat-checkbox>
            </fieldset>

            <fieldset class="notif-group">
              <legend class="notif-legend">Dydaktyka</legend>
              <mat-checkbox formControlName="notify_email_dyd" id="email-dyd">
                Powiadomienia e-mail o zadaniach i materiałach
              </mat-checkbox>
              <mat-checkbox formControlName="notify_sms_dyd" id="sms-dyd">
                Powiadomienia SMS o zadaniach i materiałach
              </mat-checkbox>
            </fieldset>

            <fieldset class="notif-group">
              <legend class="notif-legend">Lekcje</legend>
              <mat-checkbox formControlName="notify_sms_lessons" id="sms-lessons">
                Przypomnienia SMS przed lekcją
              </mat-checkbox>
            </fieldset>

            <button mat-flat-button type="submit" [disabled]="savingNotifs()">
              Zapisz preferencje
            </button>
          </form>
        </section>
      </mat-tab>
    </mat-tab-group>
  `,
  styles: [`
    .tab-content { margin-top: 1rem; }

    .notif-group {
      border: none;
      padding: 0;
      margin: 0 0 1.25rem;
    }

    .notif-legend {
      font-size: .85rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #6b7280;
      margin-bottom: .5rem;
    }

    mat-checkbox { display: block; margin-bottom: .4rem; }

    .push-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: .75rem 0;
      flex-wrap: wrap;
    }

    .push-label {
      font-weight: 600;
      margin: 0 0 .2rem;
      color: #111827;
      font-size: .95rem;
    }

    .btn-push-off {
      color: #6b7280 !important;
      border-color: #d1d5db !important;
    }
  `],
})
export class UstawieniaComponent implements OnInit {
  private fb    = inject(FormBuilder);
  private api   = inject(KursantApiService);
  private auth  = inject(AuthService);
  private snack = inject(MatSnackBar);
  push = inject(PushService);

  showCurr = signal(false);
  showNew  = signal(false);

  toggleCurr(): void { this.showCurr.set(!this.showCurr()); }
  toggleNew(): void  { this.showNew.set(!this.showNew()); }

  changingPwd  = signal(false);
  pwdMsg       = signal<string | null>(null);
  pwdError     = signal<string | null>(null);
  savingAlias  = signal(false);
  savingEmail  = signal(false);
  savingNotifs = signal(false);

  pwdForm = this.fb.group({
    current_password: ['', Validators.required],
    new_password: ['', [Validators.required, Validators.minLength(8)]],
    confirm: ['', Validators.required],
  }, { validators: passwordMatch });

  aliasForm  = this.fb.nonNullable.group({ alias: [''] });
  emailForm  = this.fb.nonNullable.group({ email: ['', [Validators.email]] });
  notifForm  = this.fb.nonNullable.group({
    notify_email_messages: [false],
    notify_sms_messages:   [false],
    notify_email_dyd:      [false],
    notify_sms_dyd:        [false],
    notify_sms_lessons:    [false],
  });

  ngOnInit(): void {
    const s = this.auth.student();
    if (s) {
      this.aliasForm.patchValue({ alias: s.login_alias ?? '' });
      this.notifForm.patchValue({
        notify_email_messages: !!s.notify_email_messages,
        notify_sms_messages:   !!s.notify_sms_messages,
        notify_email_dyd:      !!s.notify_email_dyd,
        notify_sms_dyd:        !!s.notify_sms_dyd,
        notify_sms_lessons:    !!s.notify_sms_lessons,
      });
    }
  }

  changePassword(): void {
    this.pwdForm.markAllAsTouched();
    if (this.pwdForm.invalid || this.changingPwd()) return;
    const { current_password, new_password } = this.pwdForm.getRawValue();
    this.changingPwd.set(true);
    this.pwdMsg.set(null); this.pwdError.set(null);
    this.api.changePassword(current_password!, new_password!).subscribe({
      next: res => {
        this.changingPwd.set(false);
        if (res.success) {
          this.pwdMsg.set('Hasło zostało zmienione.');
          this.pwdForm.reset();
        } else {
          this.pwdError.set(res.error ?? 'Błąd zmiany hasła.');
        }
      },
      error: () => { this.changingPwd.set(false); this.pwdError.set('Błąd połączenia.'); },
    });
  }

  setAlias(): void {
    const { alias } = this.aliasForm.getRawValue();
    this.savingAlias.set(true);
    this.api.setAlias(alias).subscribe({
      next: res => {
        this.savingAlias.set(false);
        if (res.success) this.snack.open('Alias zapisany.', 'OK', { duration: 3000 });
      },
      error: () => this.savingAlias.set(false),
    });
  }

  changeEmail(): void {
    this.emailForm.markAllAsTouched();
    if (this.emailForm.invalid) return;
    this.savingEmail.set(true);
    this.api.changeEmail(this.emailForm.getRawValue().email).subscribe({
      next: res => {
        this.savingEmail.set(false);
        if (res.success) this.snack.open('Adres e-mail zmieniony.', 'OK', { duration: 3000 });
      },
      error: () => this.savingEmail.set(false),
    });
  }

  enablePush(): void {
    this.push.enable().catch(() => {
      this.snack.open('Nie udało się włączyć powiadomień push.', 'OK', { duration: 4000 });
    });
  }

  disablePush(): void {
    this.push.disable().catch(() => {
      this.snack.open('Błąd podczas wyłączania powiadomień push.', 'OK', { duration: 4000 });
    });
  }

  saveNotifs(): void {
    this.savingNotifs.set(true);
    const prefs = Object.fromEntries(
      Object.entries(this.notifForm.getRawValue()).map(([k, v]) => [k, v ? 1 : 0])
    );
    this.api.updateSettings(prefs).subscribe({
      next: res => {
        this.savingNotifs.set(false);
        if (res.success) this.snack.open('Preferencje zapisane.', 'OK', { duration: 3000 });
      },
      error: () => this.savingNotifs.set(false),
    });
  }
}
