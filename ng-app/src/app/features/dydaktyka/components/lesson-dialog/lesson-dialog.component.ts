import { Component, inject, OnInit } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatButtonModule } from '@angular/material/button';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatNativeDateModule } from '@angular/material/core';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatIconModule } from '@angular/material/icon';
import { CommonModule } from '@angular/common';
import { SessionDialogData, SessionStatus } from '../../models/dydaktyka.model';

@Component({
  selector: 'app-lesson-dialog',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, MatDialogModule,
    MatFormFieldModule, MatInputModule, MatSelectModule,
    MatButtonModule, MatDatepickerModule, MatNativeDateModule,
    MatCheckboxModule, MatIconModule,
  ],
  templateUrl: './lesson-dialog.component.html',
})
export class LessonDialogComponent implements OnInit {
  private fb = inject(FormBuilder);
  readonly dialogRef = inject(MatDialogRef<LessonDialogComponent>);
  readonly data: SessionDialogData = inject(MAT_DIALOG_DATA);

  readonly statuses: { value: SessionStatus; label: string }[] = [
    { value: 'scheduled',        label: 'Zaplanowana' },
    { value: 'completed',        label: 'Odbyta' },
    { value: 'cancelled',        label: 'Odwołana' },
    { value: 'remote_material',  label: 'Praca własna' },
  ];

  readonly durations = [30, 45, 60, 90, 120];

  form = this.fb.group({
    course_id:    [this.data.courseId ?? null, Validators.required],
    lesson_date:  ['', Validators.required],
    time_from:    ['', [Validators.required, Validators.pattern(/^\d{2}:\d{2}$/)]],
    time_to:      ['', [Validators.required, Validators.pattern(/^\d{2}:\d{2}$/)]],
    duration_min: [60, Validators.required],
    status:       ['scheduled' as SessionStatus, Validators.required],
    topic:        [''],
    notes:        [''],
    instructor_notes: [''],
    has_homework: [false],
    meeting_url:  [''],
  });

  get isEdit(): boolean { return !!this.data.session?.id; }
  get title(): string   { return this.isEdit ? 'Edytuj lekcję' : 'Dodaj lekcję'; }

  ngOnInit(): void {
    if (this.data.session) {
      this.form.patchValue(this.data.session as any);
    }
  }

  save(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    this.dialogRef.close(this.form.getRawValue());
  }

  cancel(): void {
    this.dialogRef.close(null);
  }
}
