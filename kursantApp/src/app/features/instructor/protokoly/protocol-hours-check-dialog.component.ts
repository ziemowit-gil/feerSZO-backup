import { Component, inject, signal, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorProtocolHoursList } from '../../../core/models/kursant.models';

export interface ProtocolHoursCheckData {
  /** Zatwierdzony protokół (po id) albo miesiąc bez protokołu (kurs + RRRR-MM). */
  ref: { protocolId?: number; courseId?: number; yearMonth?: string };
  title?: string;
  acceptLabel: string;
}

/**
 * Okno „Sprawdź listę godzin” przed zatwierdzeniem / potwierdzeniem ewidencji:
 * tabela data — liczba godzin, wydruk PDF i obowiązkowe „sprawdziłem/am”.
 * afterClosed() → true dopiero po kliknięciu Akceptuję (zastępuje ConfirmDialog).
 * Odpowiednik ti_protocol_hours_check_modal() z klasycznego panelu.
 */
@Component({
  selector: 'app-protocol-hours-check-dialog',
  standalone: true,
  imports: [CommonModule, MatDialogModule, MatButtonModule, MatCheckboxModule],
  template: `
    <h2 mat-dialog-title>{{ data.title || 'Sprawdź listę godzin' }}</h2>
    <mat-dialog-content>
      @if (loading()) {
        <p class="text-muted text-sm" role="status">Ładowanie…</p>
      }
      @if (!loading() && list(); as l) {
        <p class="text-sm"><strong>{{ l.course_name }}</strong> — {{ l.period_name }}</p>
        @if (l.rows.length) {
          <table class="hours-table">
            <caption class="visually-hidden">Lista godzin zajęć</caption>
            <thead><tr><th scope="col">#</th><th scope="col">Data</th><th scope="col" class="r">Liczba godzin</th></tr></thead>
            <tbody>
              @for (r of l.rows; track $index) {
                <tr>
                  <td class="text-muted">{{ $index + 1 }}.</td>
                  <td>{{ dateLabel(r.date) }}@if (r.sub) { <span class="text-muted text-sm">(zastępstwo: {{ r.sub }})</span> }</td>
                  <td class="r">{{ hoursLabel(r.hours) }}</td>
                </tr>
              }
            </tbody>
            <tfoot><tr><td colspan="2">Razem ({{ l.rows.length }} zaj.)</td><td class="r">{{ hoursLabel(l.total_hours) }}</td></tr></tfoot>
          </table>
        } @else {
          <p class="text-muted">W tym okresie nie ma zajęć odbytych.</p>
        }
        <a mat-stroked-button [href]="pdfUrl" target="_blank" rel="noopener" class="print-btn">
          <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem;vertical-align:-3px">print</span>
          Drukuj listę godzin (PDF)
        </a>
        <mat-checkbox [checked]="checked()" (change)="checked.set($event.checked)" class="check">
          Sprawdziłem/am listę godzin — daty i liczba godzin są zgodne ze stanem faktycznym.
        </mat-checkbox>
      }
      @if (!loading() && !list()) {
        <p class="text-muted">Nie udało się wczytać listy godzin.</p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-stroked-button type="button" mat-dialog-close>Anuluj</button>
      <button mat-flat-button color="primary" type="button" [disabled]="!checked() || !list()" (click)="ref.close(true)">
        {{ data.acceptLabel }}
      </button>
    </mat-dialog-actions>
  `,
  styles: [`
    .hours-table { width: 100%; border-collapse: collapse; font-size: .9rem; margin: .5rem 0 1rem; min-width: 320px; }
    .hours-table th, .hours-table td { padding: .4rem .5rem; border-bottom: 1px solid var(--c-border, #e5e7eb); text-align: left; }
    .hours-table th { font-size: .78rem; color: var(--c-text-muted); font-weight: 600; }
    .hours-table tfoot td { font-weight: 700; border-bottom: none; }
    .r { text-align: right !important; }
    .print-btn { margin-bottom: .75rem; }
    .check { display: block; }
  `],
})
export class ProtocolHoursCheckDialogComponent implements OnInit {
  private api = inject(InstructorApiService);
  ref  = inject(MatDialogRef<ProtocolHoursCheckDialogComponent>);
  data: ProtocolHoursCheckData = inject(MAT_DIALOG_DATA);

  loading = signal(true);
  checked = signal(false);
  list    = signal<InstructorProtocolHoursList | null>(null);
  pdfUrl  = this.api.protocolHoursPdfUrl(this.data.ref);

  ngOnInit(): void {
    this.api.getProtocolHoursList(this.data.ref).subscribe({
      next: res => { this.loading.set(false); if (res.success && res.data) this.list.set(res.data); },
      error: () => this.loading.set(false),
    });
  }

  dateLabel(d: string): string {
    const [y, m, day] = d.split('-');
    return `${day}.${m}.${y}`;
  }

  hoursLabel(h: number): string {
    return h.toLocaleString('pl-PL', { maximumFractionDigits: 2 });
  }
}
