import { Component, signal, computed, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';
import { InstructorLessonRow } from '../../../core/models/kursant.models';
import { StatusLabelPipe } from '../../../shared/pipes/status-label.pipe';
import { LessonFormDialogComponent } from './lesson-form-dialog.component';
import { AttendanceDialogComponent } from './attendance-dialog.component';
import { RescheduleDialogComponent } from './reschedule-dialog.component';
import { CancelLessonDialogComponent } from './cancel-lesson-dialog.component';
import { SeriesFormDialogComponent } from './series-form-dialog.component';

/**
 * Lekcje prowadzącego — odpowiednik karty30/ti/dydaktyk/_tab_lekcje.php: lista
 * (bez kalendarza FullCalendar), dodawanie/edycja pojedynczej lekcji, Seria
 * lekcji (masowe tworzenie wg wzorca), obecność, odwoływanie/przywracanie
 * lekcji i zmiana terminu. Każda akcja to osobne okno modalne (na życzenie —
 * zamiast rozwijanego panelu szczegółów pod wierszem). "Zajęcia stałe"
 * (reguła cykliczna), kalendarz miesięczny i eksporty PDF/Excel zostają na
 * razie w klasycznym panelu — kolejny krok migracji.
 */
@Component({
  selector: 'app-instructor-lekcje',
  standalone: true,
  imports: [CommonModule, DatePipe, MatButtonModule, MatDialogModule, StatusLabelPipe],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie lekcji… }</div>

    <div class="page-header">
      <h1>Lekcje</h1>
      <p class="subtitle">Twoje zajęcia — obecność, terminy i odwoływanie</p>
      <div class="add-btn header-actions">
        <button mat-stroked-button type="button" (click)="startSeries()">
          <span class="material-symbols-outlined" aria-hidden="true">event_repeat</span>
          Seria lekcji
        </button>
        <button mat-flat-button type="button" (click)="startAdd()">
          <span class="material-symbols-outlined" aria-hidden="true">add</span>
          Dodaj lekcję
        </button>
      </div>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie lekcji">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      @if (filteredLessons().length === 0) {
        <div class="k-card">
          <div class="empty-state">
            <span class="material-symbols-outlined empty-icon" aria-hidden="true">calendar_month</span>
            <p>Brak lekcji.</p>
          </div>
        </div>
      } @else {
        <div class="k-card">
          <div class="k-table-wrap">
            <table class="k-table" aria-label="Lista lekcji">
              <thead>
                <tr>
                  <th scope="col">Data</th><th scope="col">Godziny</th><th scope="col">Grupa</th>
                  <th scope="col">Status</th><th scope="col">Temat</th><th scope="col">Obecność</th><th scope="col"></th>
                </tr>
              </thead>
              <tbody>
                @for (l of filteredLessons(); track l.id) {
                  <tr [class.today-row]="l.lesson_date === today">
                    <td class="text-nowrap">{{ l.lesson_date | date:'d.MM.yyyy' }}</td>
                    <td class="text-nowrap">{{ l.time_from | slice:0:5 }}@if (l.time_to) {–{{ l.time_to | slice:0:5 }}}</td>
                    <td>{{ l.course_name }}</td>
                    <td>
                      <span class="status-badge" [class.status-cancelled]="l.status === 'cancelled'"
                            [class.status-held]="l.status === 'held'"
                            [class.status-rescheduled]="!!l.rescheduled_from_date && l.status !== 'cancelled'">{{ l.status | statusLabel }}</span>
                      @if (l.is_substitution) { <span class="status-badge warn" title="Zastępstwo">zastępstwo</span> }
                      @if (l.pending_reschedule_count > 0) {
                        <button type="button" class="status-badge warn link-badge" (click)="openReschedule(l)">{{ l.pending_reschedule_count }} propozycja terminu</button>
                      }
                    </td>
                    <td>{{ l.topic || '—' }}</td>
                    <td class="text-nowrap">
                      @if (l.status === 'remote_material') { <span class="text-muted">—</span> }
                      @else if (l.total_count > 0) { {{ l.attended_count }}/{{ l.total_count }} }
                      @else { <span class="text-muted">—</span> }
                    </td>
                    <td class="text-end actions-cell">
                      <button mat-stroked-button type="button" class="btn-small btn-edit" (click)="startEdit(l)">Edytuj</button>
                      @if (l.status !== 'remote_material' && l.status !== 'cancelled') {
                        <button mat-stroked-button type="button" class="btn-small btn-success" (click)="openAttendance(l)">Obecność</button>
                      }
                      <button mat-stroked-button type="button" class="btn-small btn-amber" (click)="openReschedule(l)">Termin</button>
                      @if (l.status === 'cancelled') {
                        <button mat-stroked-button type="button" class="btn-small btn-success" (click)="openCancel(l)">Przywróć</button>
                      } @else {
                        <button mat-stroked-button type="button" class="btn-small btn-danger" (click)="openCancel(l)">Odwołaj</button>
                      }
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        </div>
      }
    }
  `,
  styles: [`
    .page-header { position: relative; }
    .add-btn { position: absolute; top: 0; right: 0; }
    .header-actions { display: flex; gap: .5rem; }

    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; }
    .actions-cell { display: flex; gap: .4rem; justify-content: flex-end; flex-wrap: wrap; }
    .btn-edit    { color: #4f46e5 !important; border-color: #4f46e5 !important; }
    .btn-success { color: #15803d !important; border-color: #15803d !important; }
    .btn-amber   { color: #92400e !important; border-color: #b45309 !important; }
    .btn-danger  { color: #b91c1c !important; border-color: #b91c1c !important; }
    .today-row { background: #eff6ff; }
    .status-badge.warn { background: var(--c-warning-bg); color: var(--c-warning); margin-left: .3rem; }
    .status-badge.status-cancelled { background: #fee2e2; color: #b91c1c; }
    .status-badge.status-held { background: #dcfce7; color: #15803d; }
    .status-badge.status-rescheduled { background: #fef3c7; color: #92400e; }
    .link-badge { border: none; cursor: pointer; font: inherit; text-decoration: underline; }
  `],
})
export class InstructorLekcjeComponent implements OnInit {
  private api    = inject(InstructorApiService);
  private dialog = inject(MatDialog);
  courseCtx      = inject(InstructorCourseContextService);

  today = new Date().toISOString().slice(0, 10);

  loading  = signal(true);
  lessons  = signal<InstructorLessonRow[]>([]);

  filteredLessons = computed(() => {
    const cid = this.courseCtx.selectedId();
    const all = this.lessons();
    return cid ? all.filter(l => l.course_id === cid) : all;
  });

  ngOnInit(): void {
    this.load();
    this.courseCtx.ensureLoaded();
  }

  load(): void {
    this.loading.set(true);
    this.api.getLessons().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.lessons.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  startAdd(): void {
    this.dialog.open(LessonFormDialogComponent, {
      width: '720px', maxWidth: '95vw',
      data: { mode: 'add', lesson: null, courses: this.courseCtx.courses() },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }

  startSeries(): void {
    this.dialog.open(SeriesFormDialogComponent, {
      width: '640px', maxWidth: '95vw',
      data: { courses: this.courseCtx.courses() },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }

  startEdit(l: InstructorLessonRow): void {
    this.dialog.open(LessonFormDialogComponent, {
      width: '720px', maxWidth: '95vw',
      data: { mode: 'edit', lesson: l, courses: this.courseCtx.courses() },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }

  openAttendance(l: InstructorLessonRow): void {
    this.dialog.open(AttendanceDialogComponent, {
      width: '480px', maxWidth: '95vw', data: { lesson: l },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }

  openReschedule(l: InstructorLessonRow): void {
    this.dialog.open(RescheduleDialogComponent, {
      width: '480px', maxWidth: '95vw', data: { lesson: l },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }

  openCancel(l: InstructorLessonRow): void {
    this.dialog.open(CancelLessonDialogComponent, {
      width: '420px', maxWidth: '95vw', data: { lesson: l },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }
}
