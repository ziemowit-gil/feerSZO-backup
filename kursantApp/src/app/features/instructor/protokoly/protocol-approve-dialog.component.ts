import { Component, inject, signal, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MAT_DIALOG_DATA, MatDialog, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorProtocolPending, InstructorProtocolSummary } from '../../../core/models/kursant.models';
import { ConfirmDialogComponent } from '../../../shared/components/confirm-dialog.component';

export interface ProtocolApproveDialogData { row: InstructorProtocolPending; monthLabel: string }

/** Okno modalne "Zamknij protokół" — podsumowanie miesiąca + potwierdzenie, jak krok 2 kreatora w protokoly_moje.php. */
@Component({
  selector: 'app-protocol-approve-dialog',
  standalone: true,
  imports: [CommonModule, MatDialogModule, MatButtonModule, MatSnackBarModule],
  template: `
    <h2 mat-dialog-title>{{ data.row.course_name }} — {{ data.monthLabel }}</h2>
    <mat-dialog-content>
      @if (loading()) {
        <p class="text-muted text-sm">Ładowanie…</p>
      } @else {
        @if (summary(); as s) {
          @if (data.row.is_overdue) {
            <div class="k-alert warning">
              <span class="material-symbols-outlined" aria-hidden="true">warning</span>
              <span>Ten miesiąc już się skończył — zamknij protokół najszybciej jak możesz.</span>
            </div>
          } @else {
            <div class="k-alert info">
              <span class="material-symbols-outlined" aria-hidden="true">info</span>
              <span>Bieżący miesiąc — zwykle zamyka się go po zakończeniu, ale możesz też teraz.</span>
            </div>
          }
          <dl class="summary-list">
            <div><dt>Lekcje odbyte</dt><dd>{{ s.lessons_held }} z {{ s.lessons_total }} zaplanowanych</dd></div>
            <div><dt>Średnia frekwencja</dt><dd>{{ s.attendance_pct !== null ? s.attendance_pct + '%' : '—' }}</dd></div>
            @if (s.total_min !== undefined) {
              <div><dt>Czas zajęć</dt><dd>{{ hm(s.total_min) }}</dd></div>
            }
            @if (s.has_rate) {
              <div><dt>Twoja wypłata netto</dt><dd>{{ money(s.netto ?? 0) }} <span class="text-muted">({{ s.own_lessons }} zaj.)</span></dd></div>
            }
          </dl>
          @if (s.subs?.length) {
            <div class="k-alert info">
              <span class="material-symbols-outlined" aria-hidden="true">swap_horiz</span>
              <span>Zastępstwa — wypłata dla zastępcy, poza Twoją kwotą:
                @for (x of s.subs; track x.name; let last = $last) {
                  <strong>{{ x.name || '—' }}</strong> ({{ x.lessons }} zaj.@if (s.has_rate) {, netto {{ money(x.netto) }}}){{ last ? '.' : ';' }}
                }
              </span>
            </div>
          }
          @if (s.can_approve === false) {
            <div class="k-alert warning">
              <span class="material-symbols-outlined" aria-hidden="true">lock</span>
              <span>Protokół zatwierdza główny prowadzący kursu albo kierownik.</span>
            </div>
          }
          <p class="text-muted text-sm">Zatwierdzenie zamyka protokół za ten miesiąc i utrwala ewidencję godzin i wypłaty — późniejsze zmiany lekcji jej nie zmienią. Nie da się tego cofnąć samodzielnie (odblokować może administrator, z podaniem powodu). Po zatwierdzeniu potwierdź ewidencję w zakładce „Zatwierdzone”.</p>
        }
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-stroked-button type="button" mat-dialog-close>Wstecz</button>
      <button mat-flat-button type="button" [disabled]="loading() || approving() || summary()?.can_approve === false" (click)="approve()">Zatwierdź protokół</button>
    </mat-dialog-actions>
  `,
  styles: [`
    .summary-list { display: grid; grid-template-columns: auto 1fr; gap: .4rem 1rem; margin: .85rem 0; font-size: .9rem; min-width: 300px;
      dt { color: var(--c-text-muted); } dd { margin: 0; font-weight: 600; }
    }
  `],
})
export class ProtocolApproveDialogComponent implements OnInit {
  private api    = inject(InstructorApiService);
  private snack  = inject(MatSnackBar);
  private dialog = inject(MatDialog);
  private ref    = inject(MatDialogRef<ProtocolApproveDialogComponent>);
  data: ProtocolApproveDialogData = inject(MAT_DIALOG_DATA);

  loading   = signal(true);
  approving = signal(false);
  summary   = signal<InstructorProtocolSummary | null>(null);

  hm(min: number): string {
    const h = Math.floor(min / 60), m = min % 60;
    return min <= 0 ? '0 h' : [h ? `${h} h` : '', m ? `${m} min` : ''].filter(Boolean).join(' ');
  }

  money(v: number): string {
    return v.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' zł';
  }

  ngOnInit(): void {
    this.api.getProtocolSummary(this.data.row.course_id, this.data.row.year_month).subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.summary.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  approve(): void {
    this.dialog.open(ConfirmDialogComponent, {
      width: '420px', maxWidth: '95vw',
      data: { title: 'Zatwierdź protokół', confirmLabel: 'Zatwierdź', message: `Zatwierdzić protokół za ${this.data.monthLabel}? Tej operacji nie można cofnąć samodzielnie.` },
    }).afterClosed().subscribe(confirmed => {
      if (!confirmed) return;
      this.approving.set(true);
      this.api.approveProtocol(this.data.row.course_id, this.data.row.year_month).subscribe({
        next: res => {
          this.approving.set(false);
          this.snack.open(res.message || 'Protokół zatwierdzony.', 'OK', { duration: 4000 });
          if (res.success) this.ref.close(true);
        },
        error: err => {
          this.approving.set(false);
          this.snack.open(err?.error?.error || 'Nie udało się zatwierdzić protokołu.', 'OK', { duration: 5000 });
        },
      });
    });
  }
}
