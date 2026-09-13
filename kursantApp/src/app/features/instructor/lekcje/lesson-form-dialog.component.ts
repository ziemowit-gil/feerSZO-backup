import { Component, inject, signal, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';
import { InstructorLessonRow, InstructorRoom } from '../../../core/models/kursant.models';
import { QuickDateChipsComponent } from '../../../shared/components/quick-date-chips.component';

const LESSON_METHODS = [
  { value: '', label: '— nie określono —' },
  { value: 'stacjonarna', label: 'Stacjonarna' },
  { value: 'zdalna_zoom', label: 'Zdalna (Zoom)' },
  { value: 'zdalna_inne', label: 'Zdalna (inne)' },
];

export interface LessonFormDialogData {
  mode: 'add' | 'edit';
  lesson: InstructorLessonRow | null;
  courses: { id: number; name: string }[];
}

/**
 * Okno modalne dodawania/edycji lekcji — otwierane z InstructorLekcjeComponent
 * (na życzenie: lekcje mają otwierać się w oknach wyskakujących, jak modale
 * addL/edL w klasycznym panelu, zamiast panelu wpisanego w stronę).
 */
@Component({
  selector: 'app-lesson-form-dialog',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, MatDialogModule,
    MatButtonModule, MatFormFieldModule, MatInputModule, MatSelectModule, MatSnackBarModule,
    QuickDateChipsComponent,
  ],
  template: `
    <h2 mat-dialog-title>{{ data.mode === 'add' ? 'Nowa lekcja' : 'Edytuj lekcję' }}</h2>
    <form [formGroup]="form" (ngSubmit)="save()">
      <mat-dialog-content>
        <div class="form-grid">
          <mat-form-field appearance="fill">
            <mat-label>Grupa</mat-label>
            <mat-select formControlName="course_id">
              @for (c of data.courses; track c.id) { <mat-option [value]="c.id">{{ c.name }}</mat-option> }
            </mat-select>
          </mat-form-field>

          @if (data.mode === 'edit') {
            <mat-form-field appearance="fill">
              <mat-label>Status</mat-label>
              <mat-select formControlName="status">
                <mat-option value="planned">Zaplanowana</mat-option>
                <mat-option value="held">Odbyta</mat-option>
                <mat-option value="remote_material">Praca własna</mat-option>
              </mat-select>
            </mat-form-field>
          }

          <mat-form-field appearance="fill">
            <mat-label>Data</mat-label>
            <input matInput type="date" formControlName="lesson_date" required>
          </mat-form-field>
          <app-quick-date-chips class="date-chips-row" (picked)="form.patchValue({ lesson_date: $event })" />
          <mat-form-field appearance="fill">
            <mat-label>Od</mat-label>
            <input matInput type="time" formControlName="time_from">
          </mat-form-field>
          <mat-form-field appearance="fill">
            <mat-label>Do</mat-label>
            <input matInput type="time" formControlName="time_to">
          </mat-form-field>

          <mat-form-field appearance="fill">
            <mat-label>Forma zajęć</mat-label>
            <mat-select formControlName="lesson_method">
              @for (o of lessonMethods; track o.value) { <mat-option [value]="o.value">{{ o.label }}</mat-option> }
            </mat-select>
          </mat-form-field>

          @if (form.value.lesson_method === 'stacjonarna') {
            <mat-form-field appearance="fill">
              <mat-label>Sala</mat-label>
              <mat-select formControlName="room_id">
                <mat-option [value]="null">— bez sali —</mat-option>
                @for (r of rooms(); track r.id) { <mat-option [value]="r.id">{{ r.name }}@if (r.building_name) { ({{ r.building_name }}) }</mat-option> }
              </mat-select>
            </mat-form-field>
          }
          @if (form.value.lesson_method === 'zdalna_zoom' || form.value.lesson_method === 'zdalna_inne') {
            <mat-form-field appearance="fill">
              <mat-label>Link do spotkania</mat-label>
              <input matInput formControlName="meeting_url" placeholder="https://…">
            </mat-form-field>
          }

          <mat-form-field appearance="fill" class="span-2">
            <mat-label>Temat</mat-label>
            <input matInput formControlName="topic">
          </mat-form-field>

          <mat-form-field appearance="fill" class="span-2">
            <mat-label>Notatki</mat-label>
            <textarea matInput formControlName="notes" rows="2"></textarea>
          </mat-form-field>
        </div>

        <div class="form-checks">
          <label class="check-row"><input type="checkbox" formControlName="has_homework"> Zadano pracę domową</label>
          <label class="check-row"><input type="checkbox" formControlName="self_prep_remote"> Praca własna kursanta (bez sprawdzania obecności)</label>
          @if (data.mode === 'add') {
            <label class="check-row"><input type="checkbox" formControlName="notify"> Powiadom kursantów SMS-em</label>
          }
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
    .form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0 1rem; padding-top: .25rem; }
    .form-grid mat-form-field { width: 100%; }
    .span-2 { grid-column: span 2; }
    .date-chips-row { grid-column: 1 / -1; margin-top: -.5rem; }
    .form-checks { display: flex; gap: 1.25rem; flex-wrap: wrap; margin: 0 0 .5rem; }
    .check-row { display: flex; align-items: center; gap: .4rem; font-size: .88rem; }
    @media (max-width: 640px) { .form-grid { grid-template-columns: 1fr 1fr; } .span-2 { grid-column: 1 / -1; } }
  `],
})
export class LessonFormDialogComponent implements OnInit {
  private api       = inject(InstructorApiService);
  private fb        = inject(FormBuilder);
  private snack     = inject(MatSnackBar);
  private ref       = inject(MatDialogRef<LessonFormDialogComponent>);
  private courseCtx = inject(InstructorCourseContextService);
  data: LessonFormDialogData = inject(MAT_DIALOG_DATA);

  readonly lessonMethods = LESSON_METHODS;

  saving = signal(false);
  rooms  = signal<InstructorRoom[]>([]);

  form: FormGroup = this.fb.nonNullable.group({
    course_id: [null as number | null, Validators.required],
    status: ['planned'],
    lesson_date: ['', Validators.required],
    time_from: [''],
    time_to: [''],
    lesson_method: [''],
    room_id: [null as number | null],
    meeting_url: [''],
    topic: [''],
    notes: [''],
    has_homework: [false],
    self_prep_remote: [false],
    notify: [true],
  });

  ngOnInit(): void {
    this.api.getRooms().subscribe({ next: res => { if (res.success && res.data) this.rooms.set(res.data); } });

    const l = this.data.lesson;
    if (this.data.mode === 'edit' && l) {
      this.form.reset({
        course_id: l.course_id, status: l.status, lesson_date: l.lesson_date,
        time_from: l.time_from?.slice(0, 5) ?? '', time_to: l.time_to?.slice(0, 5) ?? '',
        lesson_method: l.lesson_method || '', room_id: l.room_id, meeting_url: l.meeting_url || '',
        topic: l.topic || '', notes: l.notes || '', has_homework: !!l.has_homework,
        self_prep_remote: !!l.self_prep_remote, notify: false,
      });
    } else {
      const courses = this.data.courses;
      const preselected = this.courseCtx.selectedId() ?? (courses.length === 1 ? courses[0].id : null);
      this.form.patchValue({ course_id: preselected });
    }
  }

  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const v = this.form.getRawValue();
    this.api.saveLesson({
      session_id: this.data.mode === 'edit' ? this.data.lesson!.id : undefined,
      course_id: v.course_id!, lesson_date: v.lesson_date, time_from: v.time_from, time_to: v.time_to,
      topic: v.topic, notes: v.notes, has_homework: v.has_homework, self_prep_remote: v.self_prep_remote,
      lesson_method: v.lesson_method as '' | 'stacjonarna' | 'zdalna_zoom' | 'zdalna_inne', meeting_url: v.meeting_url,
      room_id: v.room_id, status: this.data.mode === 'edit' ? v.status : undefined, notify: v.notify,
    }).subscribe({
      next: res => {
        this.saving.set(false);
        this.snack.open(res.message || 'Zapisano.', 'OK', { duration: 4000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.saving.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się zapisać lekcji.', 'OK', { duration: 5000 });
      },
    });
  }
}
