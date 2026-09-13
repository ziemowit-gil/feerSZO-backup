import { Component, signal, computed, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorMaterial, InstructorLessonRow, INSTRUCTOR_MATERIAL_TYPES } from '../../../core/models/kursant.models';

/**
 * Materiały prowadzącego — odpowiednik karty30/ti/dydaktyk/_tab_materialy.php:
 * lista (podgląd, pobranie załącznika, link) + dodawanie/edycja/usuwanie
 * (formularz z uploadem, 1:1 z klasycznym panelem). Wybór pliku z dysku
 * ownCloud (cloud_pick.php) zostaje na razie w klasycznym panelu.
 */
@Component({
  selector: 'app-instructor-materialy',
  standalone: true,
  imports: [
    CommonModule, DatePipe, FormsModule, ReactiveFormsModule,
    MatButtonModule, MatFormFieldModule, MatInputModule, MatSelectModule,
    MatCheckboxModule, MatSnackBarModule,
  ],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie materiałów… }</div>

    <div class="page-header">
      <h1>Materiały</h1>
      <p class="subtitle">Materiały / eLearning Twoich kursów</p>
      <button mat-flat-button type="button" class="add-btn" (click)="startAdd()">
        <span class="material-symbols-outlined" aria-hidden="true">add</span>
        Dodaj materiał
      </button>
    </div>

    @if (mode()) {
      <div class="k-card form-card">
        <h2 class="form-title">{{ mode() === 'add' ? 'Nowy materiał' : 'Edytuj materiał' }}</h2>
        <form [formGroup]="form" (ngSubmit)="save()">
          <div class="form-grid">
            <mat-form-field appearance="fill">
              <mat-label>Grupa</mat-label>
              <mat-select formControlName="course_id" (selectionChange)="onCourseChange($event.value)">
                @for (c of allCourses(); track c.id) { <mat-option [value]="c.id">{{ c.name }}</mat-option> }
              </mat-select>
            </mat-form-field>

            <mat-form-field appearance="fill">
              <mat-label>Typ</mat-label>
              <mat-select formControlName="type">
                @for (t of materialTypes; track t.value) { <mat-option [value]="t.value">{{ t.label }}</mat-option> }
              </mat-select>
            </mat-form-field>

            <mat-form-field appearance="fill" class="span-2">
              <mat-label>Tytuł</mat-label>
              <input matInput formControlName="title" required>
            </mat-form-field>

            <mat-form-field appearance="fill" class="span-2">
              <mat-label>Opis</mat-label>
              <textarea matInput formControlName="description" rows="3"></textarea>
            </mat-form-field>

            <mat-form-field appearance="fill" class="span-2">
              <mat-label>Link (URL)</mat-label>
              <input matInput formControlName="url" placeholder="https://…">
            </mat-form-field>

            <mat-form-field appearance="fill">
              <mat-label>Powiązana lekcja</mat-label>
              <mat-select formControlName="session_id">
                <mat-option [value]="null">— bez lekcji —</mat-option>
                @for (s of sessionOptions(); track s.id) { <mat-option [value]="s.id">{{ s.label }}</mat-option> }
              </mat-select>
            </mat-form-field>

            <mat-form-field appearance="fill">
              <mat-label>Dostępny od</mat-label>
              <input matInput type="datetime-local" formControlName="open_at">
            </mat-form-field>

            <mat-form-field appearance="fill">
              <mat-label>Dostępny do</mat-label>
              <input matInput type="datetime-local" formControlName="close_at">
            </mat-form-field>

            <div class="file-input-wrap">
              <label for="mat-file" class="file-label">
                <span class="material-symbols-outlined" aria-hidden="true">attach_file</span>
                Załącz plik (opcjonalnie)
              </label>
              <input type="file" id="mat-file" (change)="onFileChange($event)" class="file-input">
              @if (selectedFile) { <span class="text-sm" aria-live="polite">Plik: {{ selectedFile.name }}</span> }
              @if (mode() === 'edit' && editingHasFile) { <span class="text-sm text-muted">Zostaw puste, aby zachować obecny plik.</span> }
            </div>
          </div>

          <div class="form-checks">
            @if (mode() === 'edit') {
              <label class="check-row"><input type="checkbox" formControlName="is_active"> Widoczny dla kursantów</label>
            }
            <label class="check-row"><input type="checkbox" formControlName="notify"> Powiadom kursantów o zmianie</label>
          </div>

          <div class="form-actions">
            <button mat-flat-button type="submit" [disabled]="form.invalid || saving()">
              {{ mode() === 'add' ? 'Dodaj' : 'Zapisz' }}
            </button>
            <button mat-stroked-button type="button" (click)="cancelForm()">Anuluj</button>
          </div>
        </form>
      </div>
    }

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie materiałów">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      @if (allCourses().length > 1) {
        <div class="course-filter">
          <label for="course-select">Grupa</label>
          <select id="course-select" [(ngModel)]="courseFilter">
            <option [ngValue]="null">— wszystkie grupy —</option>
            @for (c of allCourses(); track c.id) { <option [ngValue]="c.id">{{ c.name }}</option> }
          </select>
        </div>
      }

      @if (filteredMaterials().length === 0) {
        <div class="k-card">
          <div class="empty-state">
            <span class="material-symbols-outlined empty-icon" aria-hidden="true">collections_bookmark</span>
            <p>Brak materiałów.</p>
          </div>
        </div>
      } @else {
        @for (m of filteredMaterials(); track m.id) {
          <div class="k-card mat-card" [class.mat-inactive]="!m.is_active">
            <div class="mat-header">
              <div class="mat-title-row">
                <span class="type-badge">{{ m.type }}</span>
                <h2 class="mat-title">{{ m.title }}</h2>
                @if (!m.is_active) { <span class="status-badge">ukryte</span> }
                <span class="status-badge" [class.upcoming]="m.availability.state === 'upcoming'"
                      [class.closed]="m.availability.state === 'closed'">{{ m.availability.label }}</span>
              </div>
              <span class="text-muted text-sm">{{ m.course_name }}</span>
            </div>
            @if (m.session_date) { <p class="text-muted text-sm mat-session">Lekcja: {{ m.session_date | date:'d.MM.yyyy' }}@if (m.session_topic) {<span> — {{ m.session_topic }}</span>}</p> }
            @if (m.description) { <p class="mat-desc">{{ m.description }}</p> }

            <div class="mat-footer">
              @if (m.has_file) {
                <a mat-stroked-button class="btn-small" [href]="fileUrl(m.id)" target="_blank" rel="noopener">
                  <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">download</span>
                  {{ m.attach_name || 'Pobierz' }}
                </a>
              }
              @if (m.url) {
                <a mat-stroked-button class="btn-small" [href]="m.url" target="_blank" rel="noopener">
                  <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">open_in_new</span>
                  Otwórz link
                </a>
              }
              <button mat-stroked-button type="button" class="btn-small" (click)="startEdit(m)">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">edit</span>
                Edytuj
              </button>
              <button mat-stroked-button type="button" class="btn-small btn-danger" (click)="remove(m)">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">delete</span>
                Usuń
              </button>
            </div>
          </div>
        }
      }
    }
  `,
  styles: [`
    .page-header { position: relative; }
    .add-btn { position: absolute; top: 0; right: 0; }
    .form-card { margin-bottom: 1.25rem; }
    .form-title { margin: 0 0 1rem; font-size: 1.05rem; }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
    .form-grid mat-form-field { width: 100%; }
    .span-2 { grid-column: 1 / -1; }
    .file-input-wrap { grid-column: 1 / -1; display: flex; align-items: center; gap: .75rem; margin: .25rem 0 .75rem; flex-wrap: wrap; }
    .file-label { display: inline-flex; align-items: center; gap: .4rem; cursor: pointer; color: var(--c-primary, #2563eb); font-size: .9rem; }
    .file-input { max-width: 220px; }
    .form-checks { display: flex; gap: 1.25rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .check-row { display: flex; align-items: center; gap: .4rem; font-size: .88rem; }
    .form-actions { display: flex; gap: .5rem; }

    .course-filter { display: flex; align-items: center; gap: .5rem; margin-bottom: 1rem;
      label { font-size: .85rem; color: var(--c-text-muted); }
      select { padding: .4rem .6rem; border: 1px solid var(--c-border-2); border-radius: .5rem; font-size: .85rem; }
    }
    .mat-card { &.mat-inactive { opacity: .6; } }
    .mat-title-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .mat-title { font-size: 1.05rem; margin: 0; }
    .type-badge { background: var(--c-surface-2); color: var(--c-text-muted); border-radius: .4rem; padding: .1rem .5rem; font-size: .75rem; text-transform: capitalize; }
    .mat-session, .mat-desc { margin: .5rem 0 0; font-size: .9rem; }
    .mat-footer { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; padding-top: .75rem; border-top: 1px solid var(--c-border); }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; display: inline-flex !important; align-items: center; gap: .3rem; }
    .btn-danger { color: var(--c-danger, #dc2626) !important; border-color: var(--c-danger, #dc2626) !important; }
    .status-badge.upcoming { background: var(--c-warning-bg); color: var(--c-warning); }
    .status-badge.closed { background: var(--c-border); color: var(--c-text-muted); }
  `],
})
export class InstructorMaterialyComponent implements OnInit {
  private api   = inject(InstructorApiService);
  private fb    = inject(FormBuilder);
  private snack = inject(MatSnackBar);

  readonly materialTypes = INSTRUCTOR_MATERIAL_TYPES;

  loading      = signal(true);
  saving       = signal(false);
  materials    = signal<InstructorMaterial[]>([]);
  courseFilter = signal<number | null>(null);

  mode            = signal<'add' | 'edit' | null>(null);
  editingId       = signal<number | null>(null);
  editingHasFile  = false;
  selectedFile: File | null = null;
  sessionOptions  = signal<{ id: number; label: string }[]>([]);

  form: FormGroup = this.fb.nonNullable.group({
    course_id: [null as number | null, Validators.required],
    type: ['inne'],
    title: ['', Validators.required],
    description: [''],
    url: [''],
    session_id: [null as number | null],
    open_at: [''],
    close_at: [''],
    is_active: [true],
    notify: [false],
  });

  allCourses = computed(() => {
    const map = new Map<number, string>();
    for (const m of this.materials()) map.set(m.course_id, m.course_name);
    return Array.from(map, ([id, name]) => ({ id, name }));
  });

  filteredMaterials = computed(() => {
    const cid = this.courseFilter();
    const all = this.materials();
    return cid ? all.filter(m => m.course_id === cid) : all;
  });

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.getMaterials().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.materials.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  fileUrl(id: number): string {
    return this.api.materialFileUrl(id);
  }

  onCourseChange(courseId: number): void {
    this.sessionOptions.set([]);
    this.form.patchValue({ session_id: null });
    if (!courseId) return;
    this.api.getLessons(courseId).subscribe({
      next: res => {
        if (res.success && res.data) {
          this.sessionOptions.set(res.data.map((s: InstructorLessonRow) => ({
            id: s.id,
            label: `${s.lesson_date} — ${s.topic || 'bez tematu'}`,
          })));
        }
      },
    });
  }

  onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.selectedFile = input.files?.[0] ?? null;
  }

  startAdd(): void {
    this.mode.set('add');
    this.editingId.set(null);
    this.editingHasFile = false;
    this.selectedFile = null;
    this.sessionOptions.set([]);
    // Gdy prowadzący ma tylko jedną grupę, wybieramy ją od razu — przy kilku
    // grupach pole zostaje puste, żeby wymusić świadomy wybór (walidator
    // Validators.required na course_id i tak nie da zapisać bez wyboru).
    const courses = this.allCourses();
    const preselected = courses.length === 1 ? courses[0].id : null;
    this.form.reset({
      course_id: preselected, type: 'inne', title: '', description: '', url: '',
      session_id: null, open_at: '', close_at: '', is_active: true, notify: false,
    });
    if (preselected) this.onCourseChange(preselected);
  }

  startEdit(m: InstructorMaterial): void {
    this.mode.set('edit');
    this.editingId.set(m.id);
    this.editingHasFile = m.has_file;
    this.selectedFile = null;
    this.form.reset({
      course_id: m.course_id, type: m.type, title: m.title, description: m.description ?? '',
      url: m.url ?? '', session_id: m.session_id, open_at: toLocalInput(m.open_at), close_at: toLocalInput(m.close_at),
      is_active: !!m.is_active, notify: false,
    });
    this.onCourseChange(m.course_id);
    // onCourseChange czyści session_id po przeładowaniu listy lekcji — przywróć wybór po jej wczytaniu.
    this.api.getLessons(m.course_id).subscribe({
      next: res => {
        if (res.success && res.data) {
          this.sessionOptions.set(res.data.map((s: InstructorLessonRow) => ({ id: s.id, label: `${s.lesson_date} — ${s.topic || 'bez tematu'}` })));
          this.form.patchValue({ session_id: m.session_id });
        }
      },
    });
  }

  cancelForm(): void {
    this.mode.set(null);
    this.editingId.set(null);
    this.selectedFile = null;
  }

  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const v  = this.form.getRawValue();
    const fd = new FormData();
    if (this.editingId()) fd.append('material_id', String(this.editingId()));
    fd.append('course_id', String(v.course_id));
    fd.append('type', v.type);
    fd.append('title', v.title);
    fd.append('description', v.description);
    fd.append('url', v.url);
    if (v.session_id) fd.append('session_id', String(v.session_id));
    if (v.open_at) fd.append('open_at', v.open_at);
    if (v.close_at) fd.append('close_at', v.close_at);
    if (v.is_active) fd.append('is_active', '1');
    if (v.notify) fd.append('notify', '1');
    if (this.selectedFile) fd.append('attach', this.selectedFile);

    this.api.saveMaterial(fd).subscribe({
      next: res => {
        this.saving.set(false);
        this.snack.open(res.message || 'Zapisano.', 'OK', { duration: 4000 });
        if (res.success) { this.cancelForm(); this.load(); }
      },
      error: err => {
        this.saving.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się zapisać materiału.', 'OK', { duration: 5000 });
      },
    });
  }

  remove(m: InstructorMaterial): void {
    if (!confirm(`Usunąć materiał „${m.title}"?`)) return;
    this.api.deleteMaterial(m.id).subscribe({
      next: res => {
        this.snack.open(res.message || 'Usunięto.', 'OK', { duration: 4000 });
        if (res.success) this.load();
      },
      error: err => this.snack.open(err?.error?.error || 'Nie udało się usunąć materiału.', 'OK', { duration: 5000 }),
    });
  }
}

/** 'YYYY-MM-DD HH:mm:ss' (SQLite) → 'YYYY-MM-DDTHH:mm' (input[type=datetime-local]). */
function toLocalInput(v: string | null): string {
  if (!v) return '';
  return v.replace(' ', 'T').slice(0, 16);
}
