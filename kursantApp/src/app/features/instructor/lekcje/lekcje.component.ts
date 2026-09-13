import { Component, signal, computed, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorLessonRow, InstructorAttendanceEntry, InstructorRescheduleRequest } from '../../../core/models/kursant.models';
import { StatusLabelPipe } from '../../../shared/pipes/status-label.pipe';
import { LessonFormDialogComponent } from './lesson-form-dialog.component';

/**
 * Lekcje prowadzącego — odpowiednik karty30/ti/dydaktyk/_tab_lekcje.php: lista
 * (bez kalendarza FullCalendar), dodawanie/edycja pojedynczej lekcji, obecność
 * (całościowo + per kursant), odwoływanie/przywracanie lekcji, zmiana terminu
 * (bezpośrednia + decyzje ws. propozycji kursanta/opiekuna). Seria lekcji,
 * "Zajęcia stałe", kalendarz miesięczny i eksporty PDF/Excel zostają na razie
 * w klasycznym panelu — kolejny krok migracji.
 */
@Component({
  selector: 'app-instructor-lekcje',
  standalone: true,
  imports: [CommonModule, DatePipe, FormsModule, MatButtonModule, MatDialogModule, MatSnackBarModule, StatusLabelPipe],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie lekcji… }</div>

    <div class="page-header">
      <h1>Lekcje</h1>
      <p class="subtitle">Twoje zajęcia — obecność, terminy i odwoływanie</p>
      <button mat-flat-button type="button" class="add-btn" (click)="startAdd()">
        <span class="material-symbols-outlined" aria-hidden="true">add</span>
        Dodaj lekcję
      </button>
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
                      @if (l.pending_reschedule_count > 0) { <span class="status-badge warn">{{ l.pending_reschedule_count }} propozycja terminu</span> }
                    </td>
                    <td>{{ l.topic || '—' }}</td>
                    <td class="text-nowrap">
                      @if (l.status === 'remote_material') { <span class="text-muted">—</span> }
                      @else if (l.total_count > 0) { {{ l.attended_count }}/{{ l.total_count }} }
                      @else { <span class="text-muted">—</span> }
                    </td>
                    <td class="text-end actions-cell">
                      <button mat-stroked-button type="button" class="btn-small" (click)="startEdit(l)">Edytuj</button>
                      <button mat-stroked-button type="button" class="btn-small" (click)="toggleExpand(l)">
                        {{ expandedId() === l.id ? 'Zwiń' : 'Szczegóły' }}
                      </button>
                    </td>
                  </tr>
                  @if (expandedId() === l.id) {
                    <tr class="detail-row">
                      <td [attr.colspan]="7">
                        <div class="detail-panel">
                          <!-- Propozycje zmiany terminu od kursanta/opiekuna -->
                          @if (pendingLoading()) {
                            <p class="text-muted text-sm">Sprawdzanie propozycji terminu…</p>
                          } @else if (pendingRequests().length > 0) {
                            <div class="detail-section">
                              <h3>Propozycje zmiany terminu</h3>
                              @for (r of pendingRequests(); track r.id) {
                                <div class="reschedule-request">
                                  <p class="mb-0">
                                    <strong>{{ r.client_name || r.requested_by }}</strong> proponuje
                                    {{ r.proposed_date | date:'d.MM.yyyy' }} {{ r.proposed_from | slice:0:5 }}–{{ r.proposed_to | slice:0:5 }}
                                    @if (r.reason) { <span class="text-muted"> — {{ r.reason }}</span> }
                                  </p>
                                  <div class="reschedule-actions">
                                    <button mat-flat-button type="button" class="btn-small" [disabled]="decidingId() === r.id" (click)="decide(r, true)">Akceptuj</button>
                                    <button mat-stroked-button type="button" class="btn-small" [disabled]="decidingId() === r.id" (click)="decide(r, false)">Odrzuć</button>
                                  </div>
                                </div>
                              }
                            </div>
                          }

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
                                        <input type="checkbox" [(ngModel)]="attendedMap[a.client_id]" [name]="'att-' + a.client_id" [disabled]="!!a.cancelled">
                                        {{ a.client_name }}
                                        @if (a.cancelled) { <span class="text-muted text-sm"> (udział odwołany)</span> }
                                      </label>
                                      @if (a.cancelled) {
                                        <button mat-stroked-button type="button" class="btn-small" (click)="restoreAttendee(l, a)">Przywróć udział</button>
                                      } @else {
                                        <button mat-stroked-button type="button" class="btn-small" (click)="cancelAttendee(l, a)">Odwołaj udział</button>
                                      }
                                    </li>
                                  }
                                </ul>
                                <button mat-flat-button type="button" [disabled]="savingAttendance()" (click)="saveAttendance(l)">
                                  Zapisz obecność
                                </button>
                              }
                            </div>
                          }

                          <!-- Zmiana terminu -->
                          <div class="detail-section">
                            <h3>Zmień termin</h3>
                            <div class="reschedule-form">
                              <label class="field-label" for="resch-date">Nowa data</label>
                              <input type="date" id="resch-date" class="text-input" [(ngModel)]="reschDate" name="reschDate">
                              <div class="reschedule-times">
                                <input type="time" [(ngModel)]="reschFrom" name="reschFrom" aria-label="Nowa godzina od">
                                <input type="time" [(ngModel)]="reschTo" name="reschTo" aria-label="Nowa godzina do">
                              </div>
                              <label class="check-row"><input type="checkbox" [(ngModel)]="reschNotify" name="reschNotify"> Powiadom e-mailem</label>
                              <label class="check-row"><input type="checkbox" [(ngModel)]="reschNotifySms" name="reschNotifySms"> także SMS-em</label>
                              <button mat-stroked-button type="button" [disabled]="reschSubmitting()" (click)="submitReschedule(l)">Zmień termin</button>
                            </div>
                          </div>

                          <!-- Odwołaj / przywróć całą lekcję -->
                          <div class="detail-section">
                            <h3>Odwołanie lekcji</h3>
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
    .page-header { position: relative; }
    .add-btn { position: absolute; top: 0; right: 0; }
    .check-row { display: flex; align-items: center; gap: .4rem; font-size: .88rem; }

    .course-filter { display: flex; align-items: center; gap: .5rem; margin-bottom: 1rem;
      label { font-size: .85rem; color: var(--c-text-muted); }
      select { padding: .4rem .6rem; border: 1px solid var(--c-border-2); border-radius: .5rem; font-size: .85rem; }
    }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; }
    .actions-cell { display: flex; gap: .4rem; justify-content: flex-end; }
    .today-row { background: #eff6ff; }
    .status-badge.warn { background: var(--c-warning-bg); color: var(--c-warning); margin-left: .3rem; }
    .detail-row td { padding: 0; border-top: none; }
    .detail-panel { padding: 1rem 1.25rem 1.25rem; background: var(--c-surface-2); border-top: 1px solid var(--c-border); display: flex; flex-direction: column; gap: 1rem; }
    .detail-section h3 { font-size: .9rem; margin: 0 0 .5rem; }
    .attendance-list { list-style: none; margin: 0 0 .75rem; padding: 0; display: flex; flex-direction: column; gap: .4rem;
      li { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
      label { display: flex; align-items: center; gap: .5rem; font-size: .9rem; }
    }
    .field-label { display: block; font-size: .8rem; font-weight: 600; margin-bottom: .3rem; }
    .text-input { width: 100%; max-width: 320px; padding: .4rem .6rem; border: 1px solid var(--c-border); border-radius: .4rem; margin-bottom: .6rem; display: block; }
    .reschedule-form { display: flex; flex-direction: column; align-items: flex-start; gap: .4rem; }
    .reschedule-times { display: flex; gap: .5rem; margin-bottom: .4rem; }
    .reschedule-request { padding: .6rem .75rem; border: 1px solid var(--c-border); border-radius: .5rem; background: #fff; margin-bottom: .5rem; }
    .reschedule-actions { display: flex; gap: .5rem; margin-top: .5rem; }
  `],
})
export class InstructorLekcjeComponent implements OnInit {
  private api    = inject(InstructorApiService);
  private snack  = inject(MatSnackBar);
  private dialog = inject(MatDialog);

  today = new Date().toISOString().slice(0, 10);

  loading  = signal(true);
  lessons  = signal<InstructorLessonRow[]>([]);
  courseFilter = signal<number | null>(null);

  expandedId        = signal<number | null>(null);
  attendance        = signal<InstructorAttendanceEntry[]>([]);
  attendanceLoading = signal(false);
  attendedMap: Record<number, boolean> = {};
  savingAttendance  = signal(false);

  pendingRequests = signal<InstructorRescheduleRequest[]>([]);
  pendingLoading  = signal(false);
  decidingId      = signal<number | null>(null);

  reschDate = ''; reschFrom = ''; reschTo = ''; reschNotify = true; reschNotifySms = false;
  reschSubmitting = signal(false);

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
    this.reschDate = l.lesson_date; this.reschFrom = l.time_from?.slice(0, 5) ?? ''; this.reschTo = l.time_to?.slice(0, 5) ?? '';
    this.reschNotify = true; this.reschNotifySms = false;
    this.attendance.set([]);
    this.pendingRequests.set([]);

    if (l.pending_reschedule_count > 0) {
      this.pendingLoading.set(true);
      this.api.getReschedulePending(l.id).subscribe({
        next: res => { this.pendingLoading.set(false); if (res.success && res.data) this.pendingRequests.set(res.data); },
        error: () => this.pendingLoading.set(false),
      });
    }

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

  cancelAttendee(l: InstructorLessonRow, a: InstructorAttendanceEntry): void {
    const reason = prompt(`Powód odwołania udziału (${a.client_name}):`, '') ?? '';
    this.api.cancelAttendee(l.id, a.client_id, reason).subscribe({
      next: res => { this.snack.open(res.message || 'Udział odwołany.', 'OK', { duration: 4000 }); if (res.success) this.toggleExpandRefresh(l); },
      error: err => this.snack.open(err?.error?.error || 'Nie udało się odwołać udziału.', 'OK', { duration: 5000 }),
    });
  }

  restoreAttendee(l: InstructorLessonRow, a: InstructorAttendanceEntry): void {
    this.api.restoreAttendee(l.id, a.client_id).subscribe({
      next: res => { this.snack.open(res.message || 'Udział przywrócony.', 'OK', { duration: 4000 }); if (res.success) this.toggleExpandRefresh(l); },
      error: err => this.snack.open(err?.error?.error || 'Nie udało się przywrócić udziału.', 'OK', { duration: 5000 }),
    });
  }

  private toggleExpandRefresh(l: InstructorLessonRow): void {
    this.expandedId.set(null);
    this.load();
    setTimeout(() => this.toggleExpand(l), 0);
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

  submitReschedule(l: InstructorLessonRow): void {
    if (!this.reschDate) { this.snack.open('Podaj nową datę.', 'OK', { duration: 3000 }); return; }
    this.reschSubmitting.set(true);
    this.api.rescheduleLesson(l.id, this.reschDate, this.reschFrom, this.reschTo, this.reschNotify, this.reschNotifySms).subscribe({
      next: res => {
        this.reschSubmitting.set(false);
        this.snack.open(res.message || 'Termin zmieniony.', 'OK', { duration: 4000 });
        if (res.success) { this.expandedId.set(null); this.load(); }
      },
      error: err => {
        this.reschSubmitting.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się zmienić terminu.', 'OK', { duration: 5000 });
      },
    });
  }

  decide(r: InstructorRescheduleRequest, accept: boolean): void {
    const note = accept ? '' : (prompt('Komentarz do odrzucenia (opcjonalnie):', '') ?? '');
    this.decidingId.set(r.id);
    this.api.rescheduleDecide(r.id, accept, note).subscribe({
      next: res => {
        this.decidingId.set(null);
        this.snack.open(res.message || 'Zapisano decyzję.', 'OK', { duration: 4000 });
        if (res.success) { this.expandedId.set(null); this.load(); }
      },
      error: err => {
        this.decidingId.set(null);
        this.snack.open(err?.error?.error || 'Nie udało się zapisać decyzji.', 'OK', { duration: 5000 });
      },
    });
  }

  startAdd(): void {
    this.dialog.open(LessonFormDialogComponent, {
      width: '720px', maxWidth: '95vw',
      data: { mode: 'add', lesson: null, courses: this.courses() },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }

  startEdit(l: InstructorLessonRow): void {
    this.dialog.open(LessonFormDialogComponent, {
      width: '720px', maxWidth: '95vw',
      data: { mode: 'edit', lesson: l, courses: this.courses() },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }
}
