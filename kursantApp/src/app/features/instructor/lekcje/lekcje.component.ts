import { Component, signal, computed, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorLessonRow, InstructorAttendanceEntry } from '../../../core/models/kursant.models';
import { StatusLabelPipe } from '../../../shared/pipes/status-label.pipe';

/**
 * Lekcje prowadzącego — odpowiednik karty30/ti/dydaktyk/_tab_lekcje.php,
 * wyłącznie widok tabelaryczny (bez kalendarza FullCalendar) i tylko
 * podstawowe akcje (obecność, odwołaj/przywróć). Dodawanie/edycja lekcji,
 * serie, zmiana terminu i eksporty PDF/Excel zostają na razie w klasycznym
 * panelu — kolejny krok migracji.
 */
@Component({
  selector: 'app-instructor-lekcje',
  standalone: true,
  imports: [CommonModule, DatePipe, FormsModule, MatButtonModule, MatSnackBarModule, StatusLabelPipe],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie lekcji… }</div>

    <div class="page-header">
      <h1>Lekcje</h1>
      <p class="subtitle">Twoje zajęcia — obecność, odwoływanie i przywracanie terminów</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie lekcji">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      @if (courses().length > 1) {
        <div class="course-filter">
          <label for="course-select">Grupa</label>
          <select id="course-select" [(ngModel)]="courseFilter">
            <option [ngValue]="null">— wszystkie grupy —</option>
            @for (c of courses(); track c.id) { <option [ngValue]="c.id">{{ c.name }}</option> }
          </select>
        </div>
      }

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
                      <span class="status-badge">{{ l.status | statusLabel }}</span>
                      @if (l.is_substitution) { <span class="status-badge warn" title="Zastępstwo">zastępstwo</span> }
                    </td>
                    <td>{{ l.topic || '—' }}</td>
                    <td class="text-nowrap">
                      @if (l.status === 'remote_material') { <span class="text-muted">—</span> }
                      @else if (l.total_count > 0) { {{ l.attended_count }}/{{ l.total_count }} }
                      @else { <span class="text-muted">—</span> }
                    </td>
                    <td class="text-end">
                      <button mat-stroked-button type="button" class="btn-small" (click)="toggleExpand(l)">
                        {{ expandedId() === l.id ? 'Zwiń' : 'Szczegóły' }}
                      </button>
                    </td>
                  </tr>
                  @if (expandedId() === l.id) {
                    <tr class="detail-row">
                      <td [attr.colspan]="7">
                        <div class="detail-panel">
                          <!-- Obecność -->
                          @if (l.status !== 'remote_material' && l.status !== 'cancelled') {
                            <div class="detail-section">
                              <h3>Obecność</h3>
                              @if (attendanceLoading()) {
                                <p class="text-muted text-sm">Ładowanie…</p>
                              } @else if (attendance().length === 0) {
                                <p class="text-muted text-sm mb-0">Brak zapisanych kursantów.</p>
                              } @else {
                                <ul class="attendance-list">
                                  @for (a of attendance(); track a.client_id) {
                                    <li>
                                      <label>
                                        <input type="checkbox" [(ngModel)]="attendedMap[a.client_id]" [name]="'att-' + a.client_id">
                                        {{ a.client_name }}
                                        @if (a.cancelled) { <span class="text-muted text-sm"> (odwołany udział)</span> }
                                      </label>
                                    </li>
                                  }
                                </ul>
                                <button mat-flat-button type="button" [disabled]="savingAttendance()" (click)="saveAttendance(l)">
                                  Zapisz obecność
                                </button>
                              }
                            </div>
                          }

                          <!-- Odwołaj / przywróć -->
                          <div class="detail-section">
                            <h3>Termin</h3>
                            @if (l.status === 'cancelled') {
                              <button mat-stroked-button type="button" [disabled]="cancelSubmitting()" (click)="uncancel(l)">
                                Przywróć lekcję
                              </button>
                            } @else {
                              <label for="cancel-reason" class="field-label">Powód odwołania</label>
                              <input type="text" id="cancel-reason" class="text-input" [(ngModel)]="cancelReason" name="cancelReason">
                              <button mat-stroked-button type="button" color="warn" [disabled]="cancelSubmitting()" (click)="cancel(l)">
                                Odwołaj lekcję
                              </button>
                            }
                          </div>
                        </div>
                      </td>
                    </tr>
                  }
                }
              </tbody>
            </table>
          </div>
        </div>
      }
    }
  `,
  styles: [`
    .course-filter { display: flex; align-items: center; gap: .5rem; margin-bottom: 1rem;
      label { font-size: .85rem; color: var(--c-text-muted); }
      select { padding: .4rem .6rem; border: 1px solid var(--c-border-2); border-radius: .5rem; font-size: .85rem; }
    }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; }
    .today-row { background: #eff6ff; }
    .status-badge.warn { background: var(--c-warning-bg); color: var(--c-warning); margin-left: .3rem; }
    .detail-row td { padding: 0; border-top: none; }
    .detail-panel { padding: 1rem 1.25rem 1.25rem; background: var(--c-surface-2); border-top: 1px solid var(--c-border); display: flex; flex-direction: column; gap: 1rem; }
    .detail-section h3 { font-size: .9rem; margin: 0 0 .5rem; }
    .attendance-list { list-style: none; margin: 0 0 .75rem; padding: 0; display: flex; flex-direction: column; gap: .4rem;
      label { display: flex; align-items: center; gap: .5rem; font-size: .9rem; }
    }
    .field-label { display: block; font-size: .8rem; font-weight: 600; margin-bottom: .3rem; }
    .text-input { width: 100%; max-width: 320px; padding: .4rem .6rem; border: 1px solid var(--c-border); border-radius: .4rem; margin-bottom: .6rem; display: block; }
  `],
})
export class InstructorLekcjeComponent implements OnInit {
  private api   = inject(InstructorApiService);
  private snack = inject(MatSnackBar);

  today = new Date().toISOString().slice(0, 10);

  loading  = signal(true);
  lessons  = signal<InstructorLessonRow[]>([]);
  courseFilter = signal<number | null>(null);

  expandedId        = signal<number | null>(null);
  attendance        = signal<InstructorAttendanceEntry[]>([]);
  attendanceLoading = signal(false);
  attendedMap: Record<number, boolean> = {};
  savingAttendance  = signal(false);

  cancelReason      = '';
  cancelSubmitting  = signal(false);

  courses = computed(() => {
    const map = new Map<number, string>();
    for (const l of this.lessons()) map.set(l.course_id, l.course_name);
    return Array.from(map, ([id, name]) => ({ id, name }));
  });

  filteredLessons = computed(() => {
    const cid = this.courseFilter();
    const all = this.lessons();
    return cid ? all.filter(l => l.course_id === cid) : all;
  });

  ngOnInit(): void {
    this.load();
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

  toggleExpand(l: InstructorLessonRow): void {
    if (this.expandedId() === l.id) { this.expandedId.set(null); return; }
    this.expandedId.set(l.id);
    this.cancelReason = '';
    this.attendance.set([]);
    if (l.status !== 'remote_material' && l.status !== 'cancelled') {
      this.attendanceLoading.set(true);
      this.api.getSessionAttendance(l.id).subscribe({
        next: res => {
          this.attendanceLoading.set(false);
          if (res.success && res.data) {
            this.attendance.set(res.data);
            this.attendedMap = {};
            for (const a of res.data) this.attendedMap[a.client_id] = !!a.attended;
          }
        },
        error: () => this.attendanceLoading.set(false),
      });
    }
  }

  saveAttendance(l: InstructorLessonRow): void {
    const attended = Object.entries(this.attendedMap).filter(([, v]) => v).map(([k]) => Number(k));
    this.savingAttendance.set(true);
    this.api.markAttendance(l.id, attended).subscribe({
      next: res => {
        this.savingAttendance.set(false);
        this.snack.open(res.message || 'Obecność zapisana.', 'OK', { duration: 4000 });
        if (res.success) { this.expandedId.set(null); this.load(); }
      },
      error: err => {
        this.savingAttendance.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się zapisać obecności.', 'OK', { duration: 5000 });
      },
    });
  }

  cancel(l: InstructorLessonRow): void {
    if (!this.cancelReason.trim()) {
      this.snack.open('Podaj powód odwołania lekcji.', 'OK', { duration: 3000 });
      return;
    }
    this.cancelSubmitting.set(true);
    this.api.cancelLesson(l.id, this.cancelReason.trim()).subscribe({
      next: res => {
        this.cancelSubmitting.set(false);
        this.snack.open(res.message || 'Lekcja odwołana.', 'OK', { duration: 4000 });
        if (res.success) { this.expandedId.set(null); this.load(); }
      },
      error: err => {
        this.cancelSubmitting.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się odwołać lekcji.', 'OK', { duration: 5000 });
      },
    });
  }

  uncancel(l: InstructorLessonRow): void {
    this.cancelSubmitting.set(true);
    this.api.uncancelLesson(l.id).subscribe({
      next: res => {
        this.cancelSubmitting.set(false);
        this.snack.open(res.message || 'Lekcja przywrócona.', 'OK', { duration: 4000 });
        if (res.success) { this.expandedId.set(null); this.load(); }
      },
      error: err => {
        this.cancelSubmitting.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się przywrócić lekcji.', 'OK', { duration: 5000 });
      },
    });
  }
}
