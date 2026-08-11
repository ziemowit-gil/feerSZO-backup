import { Component, signal, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatStepperModule } from '@angular/material/stepper';
import { KursantApiService } from '../../core/services/kursant-api.service';

@Component({
  selector: 'app-pfron',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule,
    MatButtonModule, MatFormFieldModule, MatInputModule, MatStepperModule,
  ],
  template: `
    <div class="page-header">
      <h1>PFRON — Aktywny Samorząd</h1>
      <p class="subtitle">Sekcja dofinansowań i rozliczeń PFRON</p>
    </div>

    <div class="k-alert info" role="note">
      <span class="material-symbols-outlined" aria-hidden="true">info</span>
      <div>
        Dostęp do danych PFRON wymaga weryfikacji tożsamości za pomocą kodu SMS.
        Sesja wygasa po 30 minutach.
      </div>
    </div>

    @if (!verified()) {
      <section class="k-card" aria-labelledby="pfron-auth-heading">
        <h2 class="k-card-title" id="pfron-auth-heading">
          <span class="material-symbols-outlined" aria-hidden="true">lock</span>
          Weryfikacja dostępu
        </h2>

        @if (authError()) {
          <div class="k-alert danger" role="alert" aria-live="assertive">
            {{ authError() }}
          </div>
        }

        <mat-stepper linear [selectedIndex]="step()">
          <!-- Step 1: Contract no + phone -->
          <mat-step label="Identyfikacja">
            <form [formGroup]="authForm" (ngSubmit)="sendOtp()" novalidate style="margin-top:1rem">
              <mat-form-field appearance="fill" style="width:100%;margin-bottom:.75rem">
                <mat-label>Numer umowy PFRON</mat-label>
                <input matInput
                       formControlName="contract_no"
                       type="text"
                       id="pfron-contract"
                       autocomplete="off"
                       [attr.aria-required]="true">
              </mat-form-field>

              <mat-form-field appearance="fill" style="width:100%;margin-bottom:1rem">
                <mat-label>Numer telefonu (ostatnie 4 cyfry)</mat-label>
                <input matInput
                       formControlName="phone_last4"
                       type="tel"
                       id="pfron-phone"
                       maxlength="4"
                       inputmode="numeric"
                       [attr.aria-required]="true">
              </mat-form-field>

              <button mat-flat-button
                      type="submit"
                      [disabled]="sendingOtp()">
                Wyślij kod SMS
              </button>
            </form>
          </mat-step>

          <!-- Step 2: OTP -->
          <mat-step label="Kod SMS">
            <form [formGroup]="otpForm" (ngSubmit)="verifyOtp()" novalidate style="margin-top:1rem">
              <p class="text-muted text-sm" style="margin-bottom:.75rem">
                Wpisz 6-cyfrowy kod przesłany na Twój numer telefonu.
              </p>
              <mat-form-field appearance="fill" style="width:200px;margin-bottom:1rem">
                <mat-label>Kod SMS</mat-label>
                <input matInput
                       formControlName="otp"
                       type="text"
                       id="pfron-otp"
                       maxlength="6"
                       inputmode="numeric"
                       autocomplete="one-time-code"
                       [attr.aria-required]="true">
              </mat-form-field>

              <div style="display:flex;gap:.75rem">
                <button mat-flat-button type="submit" [disabled]="verifying()">
                  Zweryfikuj
                </button>
                <button mat-stroked-button type="button" (click)="step.set(0)">
                  Wróć
                </button>
              </div>
            </form>
          </mat-step>
        </mat-stepper>
      </section>
    } @else {
      <!-- Verified: show PFRON data -->
      <section class="k-card" aria-labelledby="pfron-data-heading">
        <div class="pfron-header">
          <h2 class="k-card-title" id="pfron-data-heading">
            <span class="material-symbols-outlined" aria-hidden="true">accessibility</span>
            Dane PFRON
          </h2>
          <button mat-stroked-button (click)="lock()" aria-label="Zablokuj sekcję PFRON">
            <span class="material-symbols-outlined" aria-hidden="true">lock</span>
            Zablokuj
          </button>
        </div>
        <div class="k-alert success">
          <span class="material-symbols-outlined" aria-hidden="true">verified</span>
          Tożsamość zweryfikowana. Sekcja jest aktywna.
        </div>
        <p class="text-muted text-sm">
          Szczegółowe dane PFRON (dofinansowania, rozliczenia) ładowane są z systemu PFRON.
          Aby zobaczyć pełne dane, przejdź do dedykowanego portalu.
        </p>
        <a href="/karty30/ti/kursant/pfron.php"
           class="pfron-portal-link"
           target="_blank" rel="noopener noreferrer"
           aria-label="Otwórz pełny portal PFRON — nowa karta">
          <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
          Pełny portal PFRON
        </a>
      </section>
    }
  `,
  styles: [`
    .pfron-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1rem;
    }

    .pfron-portal-link {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      color: #1d4ed8;
      text-decoration: none;
      font-size: .9rem;
      margin-top: 1rem;
      padding: .5rem .875rem;
      border: 1px solid #bfdbfe;
      border-radius: .5rem;

      &:hover { background: #eff6ff; }
    }
  `],
})
export class PfronComponent {
  private fb  = inject(FormBuilder);
  private api = inject(KursantApiService);

  step       = signal(0);
  verified   = signal(false);
  authError  = signal<string | null>(null);
  sendingOtp = signal(false);
  verifying  = signal(false);

  authForm = this.fb.nonNullable.group({
    contract_no: ['', Validators.required],
    phone_last4: ['', [Validators.required, Validators.pattern(/^\d{4}$/)]],
  });

  otpForm = this.fb.nonNullable.group({
    otp: ['', [Validators.required, Validators.pattern(/^\d{6}$/)]],
  });

  sendOtp(): void {
    if (this.authForm.invalid) { this.authForm.markAllAsTouched(); return; }
    this.sendingOtp.set(true);
    this.authError.set(null);
    // POST to pfron_auth endpoint
    const { contract_no, phone_last4 } = this.authForm.getRawValue();
    this.api['post']<void>('pfron_auth', { contract_no, phone_last4 }).subscribe({
      next: (res: any) => {
        this.sendingOtp.set(false);
        if (res.success) {
          this.step.set(1);
        } else {
          this.authError.set(res.error ?? 'Nieprawidłowe dane.');
        }
      },
      error: () => {
        this.sendingOtp.set(false);
        this.authError.set('Błąd połączenia.');
      },
    });
  }

  verifyOtp(): void {
    if (this.otpForm.invalid) { this.otpForm.markAllAsTouched(); return; }
    this.verifying.set(true);
    this.authError.set(null);
    const { otp } = this.otpForm.getRawValue();
    this.api['post']<void>('pfron_verify_otp', { otp }).subscribe({
      next: (res: any) => {
        this.verifying.set(false);
        if (res.success) {
          this.verified.set(true);
        } else {
          this.authError.set('Nieprawidłowy kod SMS.');
        }
      },
      error: () => {
        this.verifying.set(false);
        this.authError.set('Błąd weryfikacji.');
      },
    });
  }

  lock(): void { this.verified.set(false); this.step.set(0); }
}
