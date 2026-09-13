import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorContract, InstructorContractType } from '../../../core/models/kursant.models';

const TYPE_LABELS: Record<InstructorContractType, string> = {
  wolontariat: 'Porozumienie wolontariackie',
  zlecenie: 'Umowa zlecenie',
  dzielo: 'Umowa o dzieło',
  praca: 'Umowa o pracę',
};
const STATUS_LABELS: Record<string, string> = {
  projekt: 'Projekt', podpisana: 'Podpisana', 'w realizacji': 'W realizacji',
  zakończona: 'Zakończona', rozwiązana: 'Rozwiązana', anulowana: 'Anulowana',
};
const ACTIVE_STATUSES = ['podpisana', 'w realizacji'];

/**
 * Formalności prowadzącego — odpowiednik karty30/ti/dydaktyk/_tab_formalnosci.php:
 * własne dane kontaktowe (widoczność dla kursantów) + odczyt własnych umów
 * z rejestru (umowy_zlecenie/wolontariat/dzielo/praca). Wyłącznie odczyt umów —
 * edycja/generowanie dokumentów zostaje w module Rejestr Umów. Szczegóły
 * Instytucji Szkoleniowej (dla umów zlecenia w ramach IS) zostają na razie
 * w klasycznym panelu.
 */
@Component({
  selector: 'app-instructor-formalnosci',
  standalone: true,
  imports: [
    CommonModule, DatePipe, ReactiveFormsModule, MatButtonModule,
    MatFormFieldModule, MatInputModule, MatCheckboxModule, MatSnackBarModule,
  ],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie formalności… }</div>

    <div class="page-header">
      <h1>Formalności</h1>
      <p class="subtitle">Dane kontaktowe i Twoje umowy</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      <div class="k-card">
        <h2 class="section-title">Dane kontaktowe</h2>
        <form [formGroup]="contactForm" (ngSubmit)="saveContact()">
          <mat-form-field appearance="fill" class="full">
            <mat-label>Adres systemowy (login)</mat-label>
            <input matInput [value]="email" disabled>
            <mat-hint>Powiązany z Twoim kontem — nie można go zmienić tutaj.</mat-hint>
          </mat-form-field>
          <div class="row-2">
            <mat-form-field appearance="fill">
              <mat-label>E-mail kontaktowy</mat-label>
              <input matInput type="email" formControlName="alt_email" [placeholder]="email">
              <mat-hint>Widoczny dla kursantów i administracji.</mat-hint>
            </mat-form-field>
            <mat-form-field appearance="fill">
              <mat-label>Telefon kontaktowy</mat-label>
              <input matInput type="tel" formControlName="phone_number" placeholder="+48 000 000 000">
            </mat-form-field>
          </div>
          <mat-checkbox formControlName="share_contact">Udostępnij dane kontaktowe kursantom</mat-checkbox>
          <div class="form-actions">
            <button mat-flat-button type="submit" [disabled]="contactForm.invalid || saving()">Zapisz dane kontaktowe</button>
          </div>
        </form>
      </div>

      <h2 class="section-title page-section">Twoje formalności</h2>
      @if (contracts().length === 0) {
        <div class="k-card">
          <div class="empty-state">
            <span class="material-symbols-outlined empty-icon" aria-hidden="true">description_off</span>
            <p>Brak informacji z rejestru umów.</p>
          </div>
        </div>
      } @else {
        @for (c of contracts(); track c.id) {
          <div class="k-card contract-card" [class.contract-inactive]="!isActive(c)">
            <div class="contract-header">
              <div>
                <div class="contract-number">{{ c.numer_umowy || '(brak numeru)' }}</div>
                <div class="text-muted text-sm">{{ typeLabels[c.contract_type] }}</div>
              </div>
              <span class="status-badge" [class.active]="isActive(c)">{{ statusLabels[c.status] || c.status }}</span>
            </div>
            <dl class="contract-details">
              @if (c.imie_nazwisko) { <div><dt>Imię i nazwisko</dt><dd>{{ c.imie_nazwisko }}</dd></div> }
              @if (c.data_zawarcia) { <div><dt>Data zawarcia</dt><dd>{{ c.data_zawarcia | date:'d.MM.yyyy' }}</dd></div> }
              @if (c.data_zakonczenia) {
                <div><dt>Ważna do</dt><dd [class.expired]="isExpired(c)">{{ c.data_zakonczenia | date:'d.MM.yyyy' }}</dd></div>
              }
              @if (c.stanowisko) { <div><dt>Stanowisko / rola</dt><dd>{{ c.stanowisko }}</dd></div> }
              @if (c.miejsce_wolontariatu) { <div><dt>Miejsce</dt><dd>{{ c.miejsce_wolontariatu }}</dd></div> }
              @if (c.przedmiot_porozumienia) { <div class="span-full"><dt>Zakres działania</dt><dd class="pre-wrap">{{ c.przedmiot_porozumienia }}</dd></div> }
            </dl>
          </div>
        }
      }
    }
  `,
  styles: [`
    .section-title { font-size: 1rem; font-weight: 600; margin: 0 0 1rem; }
    .page-section { margin-top: 1.75rem; }
    .full { width: 100%; }
    .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
    @media (max-width: 640px) { .row-2 { grid-template-columns: 1fr; } }
    .form-actions { margin-top: .75rem; }

    .contract-card { &.contract-inactive { opacity: .7; } }
    .contract-header { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; margin-bottom: .85rem; }
    .contract-number { font-weight: 700; }
    .status-badge.active { background: var(--c-success-bg, #dcfce7); color: var(--c-success, #15803d); }
    .contract-details {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: .6rem 1rem; margin: 0; font-size: .88rem;
      dt { color: var(--c-text-muted); font-weight: 400; font-size: .78rem; }
      dd { margin: 0; font-weight: 600; }
      .span-full { grid-column: 1 / -1; }
      .pre-wrap { white-space: pre-wrap; font-weight: 400; }
      .expired { color: var(--c-danger, #dc2626); }
    }
  `],
})
export class InstructorFormalnosciComponent implements OnInit {
  private api   = inject(InstructorApiService);
  private fb    = inject(FormBuilder);
  private snack = inject(MatSnackBar);

  readonly typeLabels = TYPE_LABELS;
  readonly statusLabels = STATUS_LABELS;

  loading = signal(true);
  saving  = signal(false);
  email   = '';
  contracts = signal<InstructorContract[]>([]);

  contactForm: FormGroup = this.fb.nonNullable.group({
    alt_email: [''],
    phone_number: [''],
    share_contact: [false],
  });

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.getFormalnosci().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) {
          this.email = res.data.contact.email;
          this.contactForm.reset({
            alt_email: res.data.contact.alt_email, phone_number: res.data.contact.phone_number,
            share_contact: res.data.contact.share_contact,
          });
          this.contracts.set(res.data.contracts);
        }
      },
      error: () => this.loading.set(false),
    });
  }

  isActive(c: InstructorContract): boolean {
    return ACTIVE_STATUSES.includes(c.status);
  }

  isExpired(c: InstructorContract): boolean {
    return this.isActive(c) && !!c.data_zakonczenia && c.data_zakonczenia < new Date().toISOString().slice(0, 10);
  }

  saveContact(): void {
    if (this.contactForm.invalid) return;
    const v = this.contactForm.getRawValue();
    this.saving.set(true);
    this.api.updateContact(v.phone_number, v.alt_email, v.share_contact).subscribe({
      next: res => {
        this.saving.set(false);
        this.snack.open(res.message || 'Zapisano.', 'OK', { duration: 4000 });
      },
      error: err => {
        this.saving.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się zapisać danych kontaktowych.', 'OK', { duration: 5000 });
      },
    });
  }
}
