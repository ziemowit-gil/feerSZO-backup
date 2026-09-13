import { Component, signal, computed, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';
import { InstructorHomework, InstructorHomeworkSubmission } from '../../../core/models/kursant.models';
import { HomeworkFormDialogComponent } from './homework-form-dialog.component';

/**
 * Zadania domowe prowadzącego — odpowiednik karty30/ti/dydaktyk/_tab_zadania.php:
 * lista zadań, ocenianie oddań, oraz dodawanie/edycja/usuwanie zadania w oknie
 * modalnym (ten sam wzorzec co Materiały/Lekcje).
 */
@Component({
  selector: 'app-instructor-zadania',
  standalone: true,
  imports: [CommonModule, DatePipe, FormsModule, MatButtonModule, MatDialogModule, MatSnackBarModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie zadań… }</div>

    <div class="page-header">
      <h1>Zadania</h1>
      <p class="subtitle">Zadania domowe Twoich kursów — oddania i ocenianie</p>
      <button mat-flat-button type="button" class="add-btn" (click)="startAdd()">
        <span class="material-symbols-outlined" aria-hidden="true">add</span>
        Dodaj zadanie
      </button>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie zadań">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      @if (filteredHomework().length === 0) {
        <div class="k-card">
          <div class="empty-state">
            <span class="material-symbols-outlined empty-icon" aria-hidden="true">assignment</span>
            <p>Brak zadań domowych.</p>
          </div>
        </div>
      } @else {
        @for (hw of filteredHomework(); track hw.id) {
          <div class="k-card hw-card" [class.hw-inactive]="!hw.is_active">
            <div class="hw-header">
              <div class="hw-title-row">
                <h2 class="hw-title">{{ hw.title }}</h2>
                @if (!hw.is_active) { <span class="status-badge">ukryte</span> }
                <span class="status-badge" [class.upcoming]="hw.availability.state === 'upcoming'"
                      [class.closed]="hw.availability.state === 'closed'">{{ hw.availability.label }}</span>
              </div>
              <span class="text-muted text-sm">{{ hw.course_name }}</span>
            </div>
            @if (hw.due_at) { <p class="text-muted text-sm hw-due">Termin: {{ hw.due_at | slice:0:16 }}</p> }
            @if (hw.description) { <p class="hw-desc">{{ hw.description }}</p> }
            @if (hw.hint) { <p class="hw-hint"><strong>Podpowiedź:</strong> {{ hw.hint }}</p> }
            @if (hw.has_file) {
              <a mat-stroked-button class="btn-small" [href]="fileUrl(hw.id)" target="_blank" rel="noopener">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">download</span>
                {{ hw.attach_name || 'Załącznik' }}
              </a>
            }

            <div class="hw-footer">
              <span class="text-muted text-sm">{{ hw.sub_count }} oddań · {{ hw.graded_count }} ocenionych</span>
              <span class="hw-footer-actions">
                <button mat-stroked-button type="button" class="btn-small" (click)="toggleExpand(hw)">
                  {{ expandedId() === hw.id ? 'Zwiń' : 'Oddania / oceny' }}
                </button>
                <button mat-stroked-button type="button" class="btn-small btn-edit" (click)="startEdit(hw)">Edytuj</button>
                <button mat-stroked-button type="button" class="btn-small btn-danger" (click)="remove(hw)">Usuń</button>
              </span>
            </div>

            @if (expandedId() === hw.id) {
              <div class="detail-panel">
                @if (detailLoading()) {
                  <p class="text-muted text-sm">Ładowanie…</p>
                } @else if (submissions().length === 0) {
                  <p class="text-muted text-sm mb-0">Brak oddanych prac dla tego zadania.</p>
                } @else {
                  @for (s of submissions(); track s.id) {
                    <div class="submission">
                      <div class="submission-header">
                        <span class="fw-semibold">{{ s.client_name }}</span>
                        <span class="status-badge" [class.graded]="s.status === 'graded'">
                          {{ s.status === 'graded' ? 'ocenione' : 'do oceny' }}
                        </span>
                        <span class="text-muted text-sm submission-date">{{ s.submitted_at | date:'d.MM.yyyy HH:mm' }}</span>
                      </div>
                      @if (s.body) { <p class="submission-body">{{ s.body }}</p> }
                      <div class="submission-form">
                        <input type="text" [(ngModel)]="gradeMap[s.id]" [name]="'grade-' + s.id" placeholder="Ocena (np. 4 / 85%)" class="grade-input">
                        <input type="text" [(ngModel)]="feedbackMap[s.id]" [name]="'fb-' + s.id" placeholder="Informacja zwrotna" class="feedback-input">
                        <button mat-flat-button type="button" class="btn-small" [disabled]="savingId() === s.id" (click)="save(s)">Zapisz</button>
                      </div>
                    </div>
                  }
                }
              </div>
            }
          </div>
        }
      }
    }
  `,
  styles: [`
    .page-header { position: relative; }
    .add-btn { position: absolute; top: 0; right: 0; }
    .hw-card { &.hw-inactive { opacity: .6; } }
    .hw-title-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .hw-title { font-size: 1.05rem; margin: 0; }
    .hw-due, .hw-desc, .hw-hint { margin: .5rem 0 0; font-size: .9rem; }
    .hw-hint { color: var(--c-info); }
    .hw-footer { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-top: 1rem; padding-top: .75rem; border-top: 1px solid var(--c-border); flex-wrap: wrap; }
    .hw-footer-actions { display: flex; gap: .4rem; flex-wrap: wrap; }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; }
    .btn-edit   { color: #4f46e5 !important; border-color: #4f46e5 !important; }
    .btn-danger { color: #b91c1c !important; border-color: #b91c1c !important; }
    .status-badge.upcoming { background: var(--c-warning-bg); color: var(--c-warning); }
    .status-badge.closed { background: var(--c-border); color: var(--c-text-muted); }
    .status-badge.graded { background: var(--c-success-bg); color: var(--c-success); }
    .detail-panel { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--c-border); display: flex; flex-direction: column; gap: .75rem; }
    .submission { padding: .75rem; border: 1px solid var(--c-border); border-radius: .6rem; background: var(--c-surface-2); }
    .submission-header { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .submission-date { margin-left: auto; }
    .submission-body { margin: .5rem 0 0; font-size: .88rem; white-space: pre-wrap; }
    .submission-form { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .6rem; align-items: center; }
    .grade-input { width: 140px; padding: .35rem .5rem; border: 1px solid var(--c-border); border-radius: .4rem; font-size: .85rem; }
    .feedback-input { flex: 1; min-width: 180px; padding: .35rem .5rem; border: 1px solid var(--c-border); border-radius: .4rem; font-size: .85rem; }
  `],
})
export class InstructorZadaniaComponent implements OnInit {
  private api    = inject(InstructorApiService);
  private snack  = inject(MatSnackBar);
  private dialog = inject(MatDialog);
  courseCtx      = inject(InstructorCourseContextService);

  loading  = signal(true);
  homework = signal<InstructorHomework[]>([]);

  expandedId     = signal<number | null>(null);
  detailLoading  = signal(false);
  submissions    = signal<InstructorHomeworkSubmission[]>([]);
  savingId       = signal<number | null>(null);
  gradeMap: Record<number, string> = {};
  feedbackMap: Record<number, string> = {};

  filteredHomework = computed(() => {
    const cid = this.courseCtx.selectedId();
    const all = this.homework();
    return cid ? all.filter(h => h.course_id === cid) : all;
  });

  ngOnInit(): void {
    this.load();
    this.courseCtx.ensureLoaded();
  }

  load(): void {
    this.loading.set(true);
    this.api.getHomework().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.homework.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  toggleExpand(hw: InstructorHomework): void {
    if (this.expandedId() === hw.id) { this.expandedId.set(null); return; }
    this.expandedId.set(hw.id);
    this.submissions.set([]);
    this.detailLoading.set(true);
    this.api.getHomeworkSubmissions(hw.id).subscribe({
      next: res => {
        this.detailLoading.set(false);
        if (res.success && res.data) {
          this.submissions.set(res.data.submissions);
          this.gradeMap = {};
          this.feedbackMap = {};
          for (const s of res.data.submissions) {
            this.gradeMap[s.id] = s.grade ?? '';
            this.feedbackMap[s.id] = s.feedback ?? '';
          }
        }
      },
      error: () => this.detailLoading.set(false),
    });
  }

  save(s: InstructorHomeworkSubmission): void {
    this.savingId.set(s.id);
    this.api.gradeSubmission(s.id, this.gradeMap[s.id] ?? '', this.feedbackMap[s.id] ?? '').subscribe({
      next: res => {
        this.savingId.set(null);
        this.snack.open(res.message || 'Ocena zapisana.', 'OK', { duration: 4000 });
        if (res.success) this.load();
      },
      error: err => {
        this.savingId.set(null);
        this.snack.open(err?.error?.error || 'Nie udało się zapisać oceny.', 'OK', { duration: 5000 });
      },
    });
  }

  fileUrl(id: number): string {
    return this.api.homeworkFileUrl(id);
  }

  startAdd(): void {
    this.dialog.open(HomeworkFormDialogComponent, {
      width: '640px', maxWidth: '95vw',
      data: { mode: 'add', homework: null, courses: this.courseCtx.courses() },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }

  startEdit(hw: InstructorHomework): void {
    this.dialog.open(HomeworkFormDialogComponent, {
      width: '640px', maxWidth: '95vw',
      data: { mode: 'edit', homework: hw, courses: this.courseCtx.courses() },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }

  remove(hw: InstructorHomework): void {
    if (!confirm(`Usunąć zadanie „${hw.title}"? Usunie to też wszystkie oddania kursantów.`)) return;
    this.api.deleteHomework(hw.id).subscribe({
      next: res => {
        this.snack.open(res.message || 'Usunięto.', 'OK', { duration: 4000 });
        if (res.success) this.load();
      },
      error: err => this.snack.open(err?.error?.error || 'Nie udało się usunąć zadania.', 'OK', { duration: 5000 }),
    });
  }
}
