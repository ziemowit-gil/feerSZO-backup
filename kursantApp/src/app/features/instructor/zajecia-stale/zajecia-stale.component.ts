import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';
import { InstructorRecurringRule } from '../../../core/models/kursant.models';
import { RecurringRuleDialogComponent } from './recurring-rule-dialog.component';

const WEEKDAY_LABELS = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
const POSITION_LABELS: Record<string, string> = { '1': '1.', '2': '2.', '3': '3.', '4': '4.', last: 'ostatni' };

/**
 * Zajęcia stałe — odpowiednik reguł cyklicznych w klasycznym panelu
 * (op=save_recurring_rule/delete_recurring_rule w _tab_lekcje.php). OSOBNE
 * od Serii lekcji (Lekcje → "Seria lekcji"): tu reguła jest TRWAŁA (zapisana
 * w k30_ti_series) i wygenerowane lekcje pamiętają powiązanie — usuwając
 * regułę można też usunąć jej przyszłe lekcje, albo zostawić je jako zwykłe.
 */
@Component({
  selector: 'app-instructor-zajecia-stale',
  standalone: true,
  imports: [CommonModule, DatePipe, MatButtonModule, MatDialogModule, MatSnackBarModule],
  template: `
    <div class="page-header">
      <h1>Zajęcia stałe</h1>
      <p class="subtitle">Trwałe reguły cykliczne — inne niż jednorazowa Seria lekcji</p>
      <button mat-flat-button type="button" class="add-btn" (click)="startAdd()">
        <span class="material-symbols-outlined" aria-hidden="true">event_repeat</span>
        Nowa reguła
      </button>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      @if (rules().length === 0) {
        <div class="k-card">
          <div class="empty-state">
            <span class="material-symbols-outlined empty-icon" aria-hidden="true">event_repeat</span>
            <p>Brak reguł zajęć stałych.</p>
          </div>
        </div>
      } @else {
        @for (r of rules(); track r.id) {
          <div class="k-card rule-card">
            <div class="rule-header">
              <div>
                <div class="rule-course">{{ r.course_name }}</div>
                <div class="text-muted text-sm">{{ patternLabel(r) }}</div>
              </div>
              <span class="text-muted text-sm">{{ r.sessions_count }} lekcji ({{ r.future_count }} nadchodzących)</span>
            </div>
            <p class="rule-range text-sm">
              {{ r.date_from | date:'d.MM.yyyy' }} – {{ r.date_to | date:'d.MM.yyyy' }}
              @if (r.time_from) { , {{ r.time_from | slice:0:5 }}@if (r.time_to) {–{{ r.time_to | slice:0:5 }}} }
              @if (r.topic) { — {{ r.topic }} }
            </p>
            <div class="rule-actions">
              <button mat-stroked-button type="button" class="btn-small" (click)="deleteRule(r, false)">Usuń regułę (zachowaj lekcje)</button>
              <button mat-stroked-button type="button" class="btn-small btn-danger" (click)="deleteRule(r, true)">Usuń regułę i nadchodzące lekcje</button>
            </div>
          </div>
        }
      }
    }
  `,
  styles: [`
    .page-header { position: relative; }
    .add-btn { position: absolute; top: 0; right: 0; }
    .rule-header { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
    .rule-course { font-weight: 600; }
    .rule-range { margin: .5rem 0; }
    .rule-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .75rem; padding-top: .75rem; border-top: 1px solid var(--c-border); }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; }
    .btn-danger { color: #b91c1c !important; border-color: #b91c1c !important; }
  `],
})
export class InstructorZajeciaStaleComponent implements OnInit {
  private api    = inject(InstructorApiService);
  private dialog = inject(MatDialog);
  private snack  = inject(MatSnackBar);
  courseCtx      = inject(InstructorCourseContextService);

  loading = signal(true);
  rules   = signal<InstructorRecurringRule[]>([]);

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.getRecurringRules().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.rules.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  patternLabel(r: InstructorRecurringRule): string {
    if (r.recur_mode === 'monthly') {
      const pos = POSITION_LABELS[r.recur_position] ?? r.recur_position;
      const dow = WEEKDAY_LABELS[r.recur_dow ?? 1];
      return `${pos} ${dow} miesiąca`;
    }
    return `co ${r.interval_weeks} tyg.`;
  }

  startAdd(): void {
    this.dialog.open(RecurringRuleDialogComponent, {
      width: '640px', maxWidth: '95vw',
      data: { courses: this.courseNames() },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }

  private courseNames(): { id: number; name: string }[] {
    const map = new Map<number, string>();
    for (const r of this.rules()) map.set(r.course_id, r.course_name);
    for (const c of this.courseCtx.courses()) map.set(c.id, c.name);
    return Array.from(map, ([id, name]) => ({ id, name }));
  }

  deleteRule(r: InstructorRecurringRule, delFuture: boolean): void {
    const msg = delFuture
      ? `Usunąć regułę "${this.patternLabel(r)}" i ${r.future_count} nadchodzących lekcji? Tej operacji nie można cofnąć.`
      : `Usunąć regułę "${this.patternLabel(r)}"? Istniejące lekcje zostaną zachowane (odłączone od reguły).`;
    if (!confirm(msg)) return;
    this.api.deleteRecurringRule(r.id, delFuture).subscribe({
      next: res => {
        this.snack.open(res.message || 'Usunięto.', 'OK', { duration: 4000 });
        if (res.success) this.load();
      },
      error: err => this.snack.open(err?.error?.error || 'Nie udało się usunąć reguły.', 'OK', { duration: 5000 }),
    });
  }
}
