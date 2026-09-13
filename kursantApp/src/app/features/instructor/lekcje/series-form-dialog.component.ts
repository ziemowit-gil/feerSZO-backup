import { Component, inject, signal, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatRadioModule } from '@angular/material/radio';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorRoom } from '../../../core/models/kursant.models';

export interface SeriesFormDialogData {
  courses: { id: number; name: string }[];
}

const LESSON_METHODS = [
  { value: '', label: '— nie określono —' },
  { value: 'stacjonarna', label: 'Stacjonarna' },
  { value: 'zdalna_zoom', label: 'Zdalna (Zoom)' },
  { value: 'zdalna_inne', label: 'Zdalna (inne)' },
];
const WEEKDAYS = [
  { value: 1, label: 'Poniedziałek' }, { value: 2, label: 'Wtorek' }, { value: 3, label: 'Środa' },
  { value: 4, label: 'Czwartek' }, { value: 5, label: 'Piątek' }, { value: 6, label: 'Sobota' }, { value: 0, label: 'Niedziela' },
];
const POSITIONS = [
  { value: '1', label: '1.' }, { value: '2', label: '2.' }, { value: '3', label: '3.' },
  { value: '4', label: '4.' }, { value: 'last', label: 'ostatni' },
];

/**
 * Okno modalne "Seria lekcji" — masowe tworzenie N lekcji wg wzorca (co M
 * tygodni / N-ty dzień tygodnia miesiąca), 1:1 z index.php op=save_lesson_series
 * w zakresie prowadzącego (bez rezerwacji "na PESEL", wersji roboczej i
 * zastępstwa/pomijania dostępności — to funkcje kierownika).
 */
@Component({
  selector: 'app-series-form-dialog',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, MatDialogModule, MatButtonModule,
    MatFormFieldModule, MatInputModule, MatSelectModule, MatRadioModule, MatSnackBarModule,
  ],
  template: `
    <h2 mat-dialog-title>Seria lekcji</h2>
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
            <mat-label>Data startowa</mat-label>
            <input matInput type="date" formControlName="lesson_date" required>
          </mat-form-field>
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
                @for (r of rooms(); track r.id) { <mat-option [value]="r.id">{{ r.name }}</mat-option> }
              </mat-select>
            </mat-form-field>
          }
          @if (form.value.lesson_method === 'zdalna_zoom' || form.value.lesson_method === 'zdalna_inne') {
            <mat-form-field appearance="fill">
              <mat-label>Link do spotkania</mat-label>
              <input matInput formControlName="meeting_url">
            </mat-form-field>
          }

          <mat-form-field appearance="fill" class="span-2">
            <mat-label>Temat</mat-label>
            <input matInput formControlName="topic">
          </mat-form-field>
        </div>

        <h3>Wzorzec powtarzania</h3>
        <mat-radio-group formControlName="recur_mode" class="radio-row">
          <mat-radio-button value="weekly">Co N tygodni</mat-radio-button>
          <mat-radio-button value="monthly">N-ty dzień tygodnia miesiąca</mat-radio-button>
        </mat-radio-group>

        @if (form.value.recur_mode === 'weekly') {
          <mat-form-field appearance="fill" class="narrow">
            <mat-label>Co ile tygodni</mat-label>
            <input matInput type="number" min="1" max="8" formControlName="weeks">
          </mat-form-field>
        } @else {
          <div class="form-grid">
            <mat-form-field appearance="fill">
              <mat-label>Który</mat-label>
              <mat-select formControlName="recur_position">
                @for (p of positions; track p.value) { <mat-option [value]="p.value">{{ p.label }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <mat-form-field appearance="fill">
              <mat-label>Dzień tygodnia</mat-label>
              <mat-select formControlName="recur_dow">
                @for (w of weekdays; track w.value) { <mat-option [value]="w.value">{{ w.label }}</mat-option> }
              </mat-select>
            </mat-form-field>
          </div>
        }

        <h3>Koniec serii</h3>
        <mat-radio-group formControlName="end_mode" class="radio-row">
          <mat-radio-button value="count">Liczba lekcji</mat-radio-button>
          <mat-radio-button value="until">Do daty</mat-radio-button>
          <mat-radio-button value="hours">Do liczby godzin</mat-radio-button>
        </mat-radio-group>

        @if (form.value.end_mode === 'count') {
          <mat-form-field appearance="fill" class="narrow">
            <mat-label>Liczba lekcji</mat-label>
            <input matInput type="number" min="1" max="104" formControlName="count">
          </mat-form-field>
        } @else if (form.value.end_mode === 'until') {
          <mat-form-field appearance="fill" class="narrow">
            <mat-label>Data końcowa (włącznie)</mat-label>
            <input matInput type="date" formControlName="until">
          </mat-form-field>
        } @else {
          <mat-form-field appearance="fill" class="narrow">
            <mat-label>Docelowa liczba godzin</mat-label>
            <input matInput type="number" min="0.5" step="0.5" formControlName="target_hours">
          </mat-form-field>
        }
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-stroked-button type="button" mat-dialog-close>Anuluj</button>
        <button mat-flat-button type="submit" [disabled]="form.invalid || saving()">Utwórz serię</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: [`
    .form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0 1rem; padding-top: .25rem; min-width: 340px; }
    .form-grid mat-form-field { width: 100%; }
    .span-2 { grid-column: span 2; }
    .narrow { width: 220px; }
    h3 { font-size: .85rem; font-weight: 600; margin: .5rem 0 .5rem; color: var(--c-text-muted); }
    .radio-row { display: flex; gap: 1.25rem; flex-wrap: wrap; margin-bottom: .75rem; }
    @media (max-width: 640px) { .form-grid { grid-template-columns: 1fr 1fr; } .span-2 { grid-column: 1 / -1; } }
  `],
})
export class SeriesFormDialogComponent implements OnInit {
  private api   = inject(InstructorApiService);
  private fb    = inject(FormBuilder);
  private snack = inject(MatSnackBar);
  private ref   = inject(MatDialogRef<SeriesFormDialogComponent>);
  data: SeriesFormDialogData = inject(MAT_DIALOG_DATA);

  readonly lessonMethods = LESSON_METHODS;
  readonly weekdays = WEEKDAYS;
  readonly positions = POSITIONS;

  saving = signal(false);
  rooms  = signal<InstructorRoom[]>([]);

  form: FormGroup = this.fb.nonNullable.group({
    course_id: [null as number | null, Validators.required],
    lesson_date: ['', Validators.required],
    time_from: [''],
    time_to: [''],
    lesson_method: [''],
    room_id: [null as number | null],
    meeting_url: [''],
    topic: [''],
    recur_mode: ['weekly'],
    weeks: [1],
    recur_position: ['1'],
    recur_dow: [1],
    end_mode: ['count'],
    count: [10],
    until: [''],
    target_hours: [10],
  });

  ngOnInit(): void {
    this.api.getRooms().subscribe({ next: res => { if (res.success && res.data) this.rooms.set(res.data); } });
    const courses = this.data.courses;
    if (courses.length === 1) this.form.patchValue({ course_id: courses[0].id });
  }

  onCourseChange(_courseId: number): void {}

  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const v = this.form.getRawValue();
    this.api.saveLessonSeries({
      course_id: v.course_id!, lesson_date: v.lesson_date, time_from: v.time_from, time_to: v.time_to,
      topic: v.topic, lesson_method: v.lesson_method as '' | 'stacjonarna' | 'zdalna_zoom' | 'zdalna_inne',
      meeting_url: v.meeting_url, room_id: v.room_id,
      recur_mode: v.recur_mode, weeks: v.weeks, recur_position: v.recur_position, recur_dow: v.recur_dow,
      end_mode: v.end_mode, count: v.count, until: v.until, target_hours: v.target_hours,
    }).subscribe({
      next: res => {
        this.saving.set(false);
        this.snack.open(res.message || 'Seria utworzona.', 'OK', { duration: 6000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.saving.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się utworzyć serii.', 'OK', { duration: 6000 });
      },
    });
  }
}
