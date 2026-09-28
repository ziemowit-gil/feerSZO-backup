import { Component, signal, computed, inject, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatTabsModule } from '@angular/material/tabs';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';
import { InstructorProtocolPending, InstructorProtocolClosed } from '../../../core/models/kursant.models';
import { ProtocolHoursCheckDialogComponent } from './protocol-hours-check-dialog.component';
import { ProtocolApproveDialogComponent } from './protocol-approve-dialog.component';

const MONTHS_PL = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

/**
 * Protokoły (prowadzący) — odpowiednik karty30/ti/dydaktyk/protokoly_moje.php:
 * kreator "zamknij miesiąc" (osobny tor od protokoly.php widoku kierownika).
 *
 * Do zamknięcia = miesiące z odbytą lekcją bez zatwierdzonego protokołu
 * (zaległe najpierw); Zatwierdzone = protokoły z utrwaloną ewidencją, na których
 * główny prowadzący składa potwierdzenie ewidencji godzin i wypłaty.
 * Zatwierdza i potwierdza tylko główny prowadzący kursu (can_approve/can_ack) —
 * współprowadzący widzi informację zamiast przycisku. Lista respektuje wybraną
 * grupę z panelu bocznego (InstructorCourseContextService).
 */
@Component({
  selector: 'app-instructor-protokoly',
  standalone: true,
  imports: [CommonModule, MatButtonModule, MatDialogModule, MatTabsModule, MatSnackBarModule],
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
    } @else {
      <div class="stats-row" role="list">
        <div class="stat" role="listitem" [class.stat--warn]="overdueCount() > 0">
          <span class="stat-num">{{ overdueCount() }}</span><span class="stat-lbl">zaległe</span>
        </div>
        <div class="stat" role="listitem">
          <span class="stat-num">{{ pendingF().length - overdueCount() }}</span><span class="stat-lbl">bieżące</span>
        </div>
        <div class="stat" role="listitem" [class.stat--warn]="toAckCount() > 0">
          <span class="stat-num">{{ toAckCount() }}</span><span class="stat-lbl">do potwierdzenia ewidencji</span>
        </div>
        <div class="stat" role="listitem">
          <span class="stat-num">{{ closedF().length }}</span><span class="stat-lbl">zatwierdzone</span>
        </div>
      </div>

      <mat-tab-group>
        <mat-tab [label]="'Do zamknięcia (' + pendingF().length + ')'">
          <div class="tab-card">
            @if (pendingF().length === 0) {
              <div class="k-card">
                <div class="empty-state">
                  <span class="material-symbols-outlined empty-icon" aria-hidden="true">check_circle</span>
                  <p>Nic do zrobienia — wszystkie protokoły są zamknięte.</p>
                </div>
              </div>
            } @else {
              <div class="k-card table-card">
                <table class="k-table">
                  <caption class="visually-hidden">Miesiące do zamknięcia protokołem</caption>
                  <thead><tr>
                    <th scope="col">Grupa</th><th scope="col">Miesiąc</th><th scope="col">Stan</th>
                    <th scope="col" class="actions-col">Akcje</th>
                  </tr></thead>
                  <tbody>
                    @for (p of pendingF(); track p.course_id + p.year_month) {
                      <tr [class.row-warn]="p.is_overdue">
                        <td class="fw">{{ p.course_name }}</td>
                        <td>{{ monthLabel(p.year_month) }}</td>
                        <td><span class="status-badge" [class.warn]="p.is_overdue">{{ p.is_overdue ? 'zaległy' : 'bieżący' }}</span></td>
                        <td class="actions-col">
                          <a mat-stroked-button [href]="draftPdfUrl(p)" target="_blank" rel="noopener"
                             [attr.aria-label]="'PDF roboczy: ' + p.course_name + ', ' + monthLabel(p.year_month)">
                            <span class="material-symbols-outlined btn-ico" aria-hidden="true">picture_as_pdf</span>PDF roboczy
                          </a>
                          @if (p.can_approve !== false) {
                            <button mat-flat-button color="primary" type="button" (click)="openApprove(p)">
                              <span class="material-symbols-outlined btn-ico" aria-hidden="true">task_alt</span>Zamknij miesiąc
                            </button>
                          } @else {
                            <span class="text-muted text-sm lock-note">
                              <span class="material-symbols-outlined btn-ico" aria-hidden="true">lock</span>zatwierdza główny prowadzący
                            </span>
                          }
                        </td>
                      </tr>
                    }
                  </tbody>
                </table>
              </div>
            }
          </div>
        </mat-tab>

        <mat-tab [label]="'Zatwierdzone (' + closedF().length + ')'">
          <div class="tab-card">
            @if (closedF().length === 0) {
              <div class="k-card">
                <div class="empty-state">
                  <span class="material-symbols-outlined empty-icon" aria-hidden="true">inventory_2</span>
                  <p>Brak jeszcze zatwierdzonych protokołów.</p>
                </div>
              </div>
            } @else {
              <div class="k-card table-card">
                <table class="k-table">
                  <caption class="visually-hidden">Zatwierdzone protokoły miesięczne</caption>
                  <thead><tr>
                    <th scope="col">Grupa</th><th scope="col">Miesiąc</th><th scope="col">Zatwierdzenie</th>
                    <th scope="col">Ewidencja godzin</th><th scope="col">Organizator</th>
                    <th scope="col" class="actions-col">Akcje</th>
                  </tr></thead>
                  <tbody>
                    @for (c of closedF(); track c.protocol_id) {
                      <tr>
                        <td class="fw">{{ c.course_name }}</td>
                        <td>{{ monthLabel(c.year_month) }}</td>
                        <td class="text-sm">
                          {{ c.approved_name || '—' }}@if (c.approved_at) {<br><span class="text-muted">{{ dateLabel(c.approved_at) }}</span>}
                          @if (c.drift) {
                            <div class="drift" role="note">
                              <span class="material-symbols-outlined btn-ico" aria-hidden="true">warning</span>
                              lekcje zmieniono po zatwierdzeniu — protokół pokazuje dane utrwalone
                            </div>
                          }
                        </td>
                        <td class="text-sm">
                          @if (c.hours_ack_at) {
                            <span class="status-badge active">potwierdzona</span>
                            <div class="text-muted">{{ c.hours_ack_label }}, {{ dateLabel(c.hours_ack_at) }}</div>
                          } @else if (c.can_ack) {
                            <button mat-stroked-button type="button" [disabled]="acking() === c.protocol_id" (click)="ackHours(c)">
                              <span class="material-symbols-outlined btn-ico" aria-hidden="true">draw</span>Potwierdź ewidencję
                            </button>
                          } @else {
                            <span class="status-badge warn">niepotwierdzona</span>
                          }
                        </td>
                        <td class="text-sm">
                          @if (c.org_ack_at) {
                            <span class="status-badge active">podpisany</span>
                            <div class="text-muted">{{ c.org_ack_name }}</div>
                          } @else {
                            <span class="text-muted">czeka na kierownika</span>
                          }
                        </td>
                        <td class="actions-col">
                          <a mat-stroked-button [href]="closedPdfUrl(c)" target="_blank" rel="noopener"
                             [attr.aria-label]="'Pobierz PDF: ' + c.course_name + ', ' + monthLabel(c.year_month)">
                            <span class="material-symbols-outlined btn-ico" aria-hidden="true">picture_as_pdf</span>PDF
                          </a>
                        </td>
                      </tr>
                    }
                  </tbody>
                </table>
              </div>
            }
          </div>
        </mat-tab>
      </mat-tab-group>
    }
  `,
  styles: [`
    .tab-card { padding-top: 1.25rem; }
    .stats-row { display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .stat { background: var(--c-surface, #fff); border: 1px solid var(--c-border, #e5e7eb); border-radius: 10px;
      padding: .6rem .9rem; min-width: 120px; display: flex; flex-direction: column; }
    .stat--warn { border-color: var(--c-warning, #b45309); }
    .stat-num { font-size: 1.4rem; font-weight: 700; line-height: 1.1; }
    .stat-lbl { font-size: .8rem; color: var(--c-text-muted); }
    .table-card { padding: 0; overflow-x: auto; }
    .k-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .k-table th, .k-table td { padding: .6rem .75rem; border-bottom: 1px solid var(--c-border, #e5e7eb); text-align: left; vertical-align: top; }
    .k-table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; color: var(--c-text-muted); font-weight: 600; }
    .row-warn td:first-child { box-shadow: inset 3px 0 0 var(--c-warning, #b45309); }
    .fw { font-weight: 600; }
    .actions-col { text-align: right; white-space: nowrap; }
    .actions-col > * + * { margin-left: .4rem; }
    .btn-ico { font-size: 1rem; vertical-align: -3px; margin-right: .25rem; }
    .lock-note { display: inline-flex; align-items: center; }
    .drift { margin-top: .35rem; color: var(--c-warning, #b45309); font-size: .8rem; }
    .status-badge.warn { background: var(--c-warning-bg); color: var(--c-warning); }
    .status-badge.active { background: var(--c-success-bg, #dcfce7); color: var(--c-success, #15803d); }
  `],
})
export class InstructorProtokolyComponent implements OnInit {
  private api       = inject(InstructorApiService);
  private dialog    = inject(MatDialog);
  private snack     = inject(MatSnackBar);
  private courseCtx = inject(InstructorCourseContextService);

  loading = signal(true);
  acking  = signal<number | null>(null);
  pending = signal<InstructorProtocolPending[]>([]);
  closed  = signal<InstructorProtocolClosed[]>([]);

  /** Filtr wybranej grupy z panelu bocznego; zaległe najpierw. */
  pendingF = computed(() => {
    const cid = this.courseCtx.selectedId();
    return this.pending()
      .filter(p => cid === null || p.course_id === cid)
      .sort((a, b) => Number(b.is_overdue) - Number(a.is_overdue) || a.year_month.localeCompare(b.year_month));
  });
  closedF = computed(() => {
    const cid = this.courseCtx.selectedId();
    return this.closed().filter(c => cid === null || c.course_id === cid);
  });
  overdueCount = computed(() => this.pendingF().filter(p => p.is_overdue).length);
  toAckCount   = computed(() => this.closedF().filter(c => !c.hours_ack_at && c.can_ack).length);

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    let left = 2;
    const done = () => { if (--left === 0) this.loading.set(false); };
    this.api.getProtocolsPending().subscribe({
      next: res => { if (res.success && res.data) this.pending.set(res.data); done(); },
      error: () => done(),
    });
    this.api.getProtocolsClosed().subscribe({
      next: res => { if (res.success && res.data) this.closed.set(res.data); done(); },
      error: () => done(),
    });
  }

  monthLabel(ym: string): string {
    const [y, m] = ym.split('-').map(Number);
    return `${MONTHS_PL[m - 1]} ${y}`;
  }

  dateLabel(dt: string): string {
    const d = new Date(dt.replace(' ', 'T'));
    return isNaN(d.getTime()) ? dt : d.toLocaleDateString('pl-PL');
  }

  draftPdfUrl(p: InstructorProtocolPending): string {
    return this.api.protocolPdfUrlForMonth(p.course_id, p.year_month);
  }

  closedPdfUrl(c: InstructorProtocolClosed): string {
    return this.api.protocolPdfUrlById(c.protocol_id);
  }

  openApprove(row: InstructorProtocolPending): void {
    this.dialog.open(ProtocolApproveDialogComponent, {
      width: '520px', maxWidth: '95vw',
      data: { row, monthLabel: this.monthLabel(row.year_month) },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }

  ackHours(c: InstructorProtocolClosed): void {
    this.dialog.open(ProtocolHoursCheckDialogComponent, {
      width: '520px', maxWidth: '95vw',
      data: {
        ref: { protocolId: c.protocol_id },
        title: `Potwierdź ewidencję — ${c.course_name}, ${this.monthLabel(c.year_month)}`,
        acceptLabel: 'Akceptuję i potwierdzam ewidencję',
      },
    }).afterClosed().subscribe(ok => {
      if (!ok) return;
      this.acking.set(c.protocol_id);
      this.api.ackProtocolHours(c.protocol_id).subscribe({
        next: res => {
          this.acking.set(null);
          this.snack.open(res.message || 'Ewidencja potwierdzona.', 'OK', { duration: 4000 });
          if (res.success) this.load();
        },
        error: err => {
          this.acking.set(null);
          this.snack.open(err?.error?.error || 'Nie udało się potwierdzić ewidencji.', 'OK', { duration: 5000 });
        },
      });
    });
  }
}
