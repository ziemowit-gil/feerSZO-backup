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
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';
import { InstructorRoom } from '../../../core/models/kursant.models';
import { QuickDateChipsComponent } from '../../../shared/components/quick-date-chips.component';

export interface RecurringRuleDialogData {
  courses: { id: number; name: string }[];
}

const WEEKDAYS = [
  { value: 1, label: 'Poniedziałek' }, { value: 2, label: 'Wtorek' }, { value: 3, label: 'Środa' },
  { value: 4, label: 'Czwartek' }, { value: 5, label: 'Piątek' }, { value: 6, label: 'Sobota' }, { value: 0, label: 'Niedziela' },
];
const POSITIONS = [
  { value: '1', label: '1.' }, { value: '2', label: '2.' }, { value: '3', label: '3.' },
  { value: '4', label: '4.' }, { value: 'last', label: 'ostatni' },
];

/**
 * Okno modalne "Zajęcia stałe" (nowa reguła cykliczna) — odpowiednik
 * op=save_recurring_rule w klasycznym panelu. OSOBNE od Serii lekcji: reguła
 * jest trwała (zapisana w k30_ti_series), koniec zawsze przez datę
 * (bez trybów "liczba lekcji"/"do godzin" jak w Serii), bez formy zajęć
 * (klasyczna reguła też jej nie ma — Zoom sprawdzany tylko gdy kurs ma stały link).
 */
@Component({
  selector: 'app-recurring-rule-dialog',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, MatDialogModule, MatButtonModule,
    MatFormFieldModule, MatInputModule, MatSelectModule, MatRadioModule, MatSnackBarModule,
    QuickDateChipsComponent,
  ],
  template: `
    <h2 mat-dialog-title>Zajęcia stałe — nowa reguła</h2>
    <form [formGroup]="form" (ngSubmit)="save()">
      <mat-dialog-content>
        <div class="form-grid">
          <mat-form-field appearance="fill">
            <mat-label>Grupa</mat-label>
            <mat-select formControlName="course_id">
              @for (c of data.courses; track c.id) { <mat-option [value]="c.id">{{ c.name }}</mat-option> }
            </mat-select>
          </mat-form-field>
          <mat-form-field appearance="fill">
            <mat-label>Sala</mat-label>
            <mat-select formControlName="room_id">
              <mat-option [value]="null">— bez sali —</mat-option>
              @for (r of rooms(); track r.id) { <mat-option [value]="r.id">{{ r.name }}</mat-option> }
            </mat-select>
          </mat-form-field>

          <mat-form-field appearance="fill">
            <mat-label>Od (data)</mat-label>
            <input matInput type="date" formControlName="date_from" required>
          </mat-form-field>
          <mat-form-field appearance="fill">
            <mat-label>Do (data, włącznie)</mat-label>
            <input matInput type="date" formControlName="date_to" required>
          </mat-form-field>
          <app-quick-date-chips class="date-chips-row" (picked)="form.patchValue({ date_to: $event })" />

          <mat-form-field appearance="fill">
            <mat-label>Od (godzina)</mat-label>
            <input matInput type="time" formControlName="time_from">
          </mat-form-field>
          <mat-form-field appearance="fill">
            <mat-label>Do (godzina)</mat-label>
            <input matInput type="time" formControlName="time_to">
          </mat-form-field>

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
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-stroked-button type="button" mat-dialog-close>Anuluj</button>
        <button mat-flat-button type="submit" [disabled]="form.invalid || saving()">Dodaj regułę</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: [`
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; padding-top: .25rem; min-width: 340px; }
    .form-grid mat-form-field { width: 100%; }
    .span-2 { grid-column: 1 / -1; }
    .date-chips-row { grid-column: 1 / -1; margin-top: -.5rem; }
    .narrow { width: 220px; }
    h3 { font-size: .85rem; font-weight: 600; margin: .5rem 0 .5rem; color: var(--c-text-muted); }
    .radio-row { display: flex; gap: 1.25rem; flex-wrap: wrap; margin-bottom: .75rem; }
  `],
})
export class RecurringRuleDialogComponent implements OnInit {
  private api       = inject(InstructorApiService);
  private fb        = inject(FormBuilder);
  private snack     = inject(MatSnackBar);
  private ref       = inject(MatDialogRef<RecurringRuleDialogComponent>);
  private courseCtx = inject(InstructorCourseContextService);
  data: RecurringRuleDialogData = inject(MAT_DIALOG_DATA);

  readonly weekdays = WEEKDAYS;
  readonly positions = POSITIONS;

  saving = signal(false);
  rooms  = signal<InstructorRoom[]>([]);

  form: FormGroup = this.fb.nonNullable.group({
    course_id: [null as number | null, Validators.required],
    room_id: [null as number | null],
    date_from: ['', Validators.required],
    date_to: ['', Validators.required],
    time_from: [''],
    time_to: [''],
    topic: [''],
    recur_mode: ['weekly'],
    weeks: [1],
    recur_position: ['1'],
    recur_dow: [1],
  });

  ngOnInit(): void {
    this.api.getRooms().subscribe({ next: res => { if (res.success && res.data) this.rooms.set(res.data); } });
    const courses = this.data.courses;
    const preselected = this.courseCtx.selectedId() ?? (courses.length === 1 ? courses[0].id : null);
    if (preselected) this.form.patchValue({ course_id: preselected });
  }

  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const v = this.form.getRawValue();
    this.api.saveRecurringRule({
      course_id: v.course_id!, date_from: v.date_from, date_to: v.date_to, time_from: v.time_from, time_to: v.time_to,
      topic: v.topic, room_id: v.room_id, recur_mode: v.recur_mode, weeks: v.weeks,
      recur_dow: v.recur_dow, recur_position: v.recur_position,
    }).subscribe({
      next: res => {
        this.saving.set(false);
        this.snack.open(res.message || 'Reguła dodana.', 'OK', { duration: 6000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.saving.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się dodać reguły.', 'OK', { duration: 6000 });
      },
    });
  }
}
