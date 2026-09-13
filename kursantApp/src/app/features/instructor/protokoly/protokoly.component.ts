import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatTabsModule } from '@angular/material/tabs';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorProtocolPending, InstructorProtocolClosed } from '../../../core/models/kursant.models';
import { ProtocolApproveDialogComponent } from './protocol-approve-dialog.component';

const MONTHS_PL = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

/**
 * Protokoły (prowadzący) — odpowiednik karty30/ti/dydaktyk/protokoly_moje.php:
 * kreator "zamknij miesiąc" (osobny tor od protokoly.php widoku kierownika).
 * Otwarte = miesiące czekające na zatwierdzenie, Zamknięte = już zatwierdzone.
 * Zamknięcie miesiąca (podsumowanie + potwierdzenie) w oknie modalnym.
 */
@Component({
  selector: 'app-instructor-protokoly',
  standalone: true,
  imports: [CommonModule, MatButtonModule, MatDialogModule, MatTabsModule],
  template: `
    <div class="page-header">
      <h1>Protokoły</h1>
      <p class="subtitle">Miesięczne protokoły zajęć Twoich grup</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      <mat-tab-group>
        <mat-tab [label]="'Otwarte (' + pending().length + ')'">
          <div class="tab-card">
            @if (pending().length === 0) {
              <div class="k-card">
                <div class="empty-state">
                  <span class="material-symbols-outlined empty-icon" aria-hidden="true">check_circle</span>
                  <p>Nic do zrobienia — wszystkie protokoły są zamknięte.</p>
                </div>
              </div>
            } @else {
              @for (p of pending(); track p.course_id + p.year_month) {
                <div class="k-card protocol-row">
                  <div>
                    <div class="protocol-course">{{ p.course_name }}</div>
                    <div class="text-muted text-sm">{{ monthLabel(p.year_month) }}</div>
                  </div>
                  <span class="status-badge" [class.warn]="p.is_overdue">{{ p.is_overdue ? 'zaległy' : 'bieżący' }}</span>
                  <button mat-flat-button type="button" (click)="openApprove(p)">Zamknij protokół</button>
                </div>
              }
            }
          </div>
        </mat-tab>

        <mat-tab [label]="'Zamknięte (' + closed().length + ')'">
          <div class="tab-card">
            @if (closed().length === 0) {
              <div class="k-card">
                <div class="empty-state">
                  <span class="material-symbols-outlined empty-icon" aria-hidden="true">inventory_2</span>
                  <p>Brak jeszcze zamkniętych protokołów.</p>
                </div>
              </div>
            } @else {
              @for (c of closed(); track c.protocol_id) {
                <div class="k-card protocol-row">
                  <div>
                    <div class="protocol-course">{{ c.course_name }}</div>
                    <div class="text-muted text-sm">{{ monthLabel(c.year_month) }}</div>
                  </div>
                  <span class="status-badge active">zatwierdzony</span>
                </div>
              }
            }
          </div>
        </mat-tab>
      </mat-tab-group>
    }
  `,
  styles: [`
    .tab-card { padding-top: 1.25rem; }
    .protocol-row { display: flex; align-items: center; gap: 1rem; }
    .protocol-course { font-weight: 600; }
    .status-badge.warn { background: var(--c-warning-bg); color: var(--c-warning); }
    .status-badge.active { background: var(--c-success-bg, #dcfce7); color: var(--c-success, #15803d); }
  `],
})
export class InstructorProtokolyComponent implements OnInit {
  private api    = inject(InstructorApiService);
  private dialog = inject(MatDialog);

  loading = signal(true);
  pending = signal<InstructorProtocolPending[]>([]);
  closed  = signal<InstructorProtocolClosed[]>([]);

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.getProtocolsPending().subscribe({
      next: res => { if (res.success && res.data) this.pending.set(res.data); },
    });
    this.api.getProtocolsClosed().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.closed.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  monthLabel(ym: string): string {
    const [y, m] = ym.split('-').map(Number);
    return `${MONTHS_PL[m - 1]} ${y}`;
  }

  openApprove(row: InstructorProtocolPending): void {
    this.dialog.open(ProtocolApproveDialogComponent, {
      width: '480px', maxWidth: '95vw',
      data: { row, monthLabel: this.monthLabel(row.year_month) },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }
}
