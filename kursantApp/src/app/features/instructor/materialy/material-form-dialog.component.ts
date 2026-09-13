import { Component, inject, signal, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';
import { InstructorMaterial, InstructorLessonRow, INSTRUCTOR_MATERIAL_TYPES } from '../../../core/models/kursant.models';

export interface MaterialFormDialogData {
  mode: 'add' | 'edit';
  material: InstructorMaterial | null;
  courses: { id: number; name: string }[];
}

/** Okno modalne dodawania/edycji materiału (na życzenie: materiały też w oknach modalnych, jak lekcje). */
@Component({
  selector: 'app-material-form-dialog',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, MatDialogModule, MatButtonModule,
    MatFormFieldModule, MatInputModule, MatSelectModule, MatCheckboxModule, MatSnackBarModule,
  ],
  template: `
    <h2 mat-dialog-title>{{ data.mode === 'add' ? 'Nowy materiał' : 'Edytuj materiał' }}</h2>
    <form [formGroup]="form" (ngSubmit)="save()">
      <mat-dialog-content>
        <div class="form-grid">
          <mat-form-field appearance="fill">
            <mat-label>Grupa</mat-label>
            <mat-select formControlName="course_id" (selectionChange)="onCourseChange($event.value)">
              @for (c of data.courses; track c.id) { <mat-option [value]="c.id">{{ c.name }}</mat-option> }
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
            @if (data.mode === 'edit' && editingHasFile) { <span class="text-sm text-muted">Zostaw puste, aby zachować obecny plik.</span> }
          </div>
        </div>

        <div class="form-checks">
          @if (data.mode === 'edit') {
            <mat-checkbox formControlName="is_active">Widoczny dla kursantów</mat-checkbox>
          }
          <mat-checkbox formControlName="notify">Powiadom kursantów o zmianie</mat-checkbox>
        </div>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-stroked-button type="button" mat-dialog-close>Anuluj</button>
        <button mat-flat-button type="submit" [disabled]="form.invalid || saving()">
          {{ data.mode === 'add' ? 'Dodaj' : 'Zapisz' }}
        </button>
      </mat-dialog-actions>
    </form>
  `,
  styles: [`
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; padding-top: .25rem; min-width: 320px; }
    .form-grid mat-form-field { width: 100%; }
    .span-2 { grid-column: 1 / -1; }
    .file-input-wrap { grid-column: 1 / -1; display: flex; align-items: center; gap: .75rem; margin: .25rem 0 .75rem; flex-wrap: wrap; }
    .file-label { display: inline-flex; align-items: center; gap: .4rem; cursor: pointer; color: var(--c-primary, #2563eb); font-size: .9rem; }
    .file-input { max-width: 220px; }
    .form-checks { display: flex; gap: 1.25rem; flex-wrap: wrap; margin-bottom: .5rem; }
    @media (max-width: 640px) { .form-grid { grid-template-columns: 1fr; } .span-2 { grid-column: 1; } }
  `],
})
export class MaterialFormDialogComponent implements OnInit {
  private api       = inject(InstructorApiService);
  private fb        = inject(FormBuilder);
  private snack     = inject(MatSnackBar);
  private ref       = inject(MatDialogRef<MaterialFormDialogComponent>);
  private courseCtx = inject(InstructorCourseContextService);
  data: MaterialFormDialogData = inject(MAT_DIALOG_DATA);

  readonly materialTypes = INSTRUCTOR_MATERIAL_TYPES;

  saving = signal(false);
  editingHasFile = false;
  selectedFile: File | null = null;
  sessionOptions = signal<{ id: number; label: string }[]>([]);

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

  ngOnInit(): void {
    const m = this.data.material;
    if (this.data.mode === 'edit' && m) {
      this.editingHasFile = m.has_file;
      this.form.reset({
        course_id: m.course_id, type: m.type, title: m.title, description: m.description ?? '',
        url: m.url ?? '', session_id: m.session_id, open_at: toLocalInput(m.open_at), close_at: toLocalInput(m.close_at),
        is_active: !!m.is_active, notify: false,
      });
      this.api.getLessons(m.course_id).subscribe({
        next: res => {
          if (res.success && res.data) {
            this.sessionOptions.set(res.data.map((s: InstructorLessonRow) => ({ id: s.id, label: `${s.lesson_date} — ${s.topic || 'bez tematu'}` })));
            this.form.patchValue({ session_id: m.session_id });
          }
        },
      });
    } else {
      const courses = this.data.courses;
      const preselected = this.courseCtx.selectedId() ?? (courses.length === 1 ? courses[0].id : null);
      this.form.patchValue({ course_id: preselected });
      if (preselected) this.onCourseChange(preselected);
    }
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

  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const v  = this.form.getRawValue();
    const fd = new FormData();
    if (this.data.mode === 'edit' && this.data.material) fd.append('material_id', String(this.data.material.id));
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
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.saving.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się zapisać materiału.', 'OK', { duration: 5000 });
      },
    });
  }
}

/** 'YYYY-MM-DD HH:mm:ss' (SQLite) → 'YYYY-MM-DDTHH:mm' (input[type=datetime-local]). */
function toLocalInput(v: string | null): string {
  if (!v) return '';
  return v.replace(' ', 'T').slice(0, 16);
}
