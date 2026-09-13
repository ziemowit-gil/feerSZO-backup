import { Component, inject, signal } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorLessonRow } from '../../../core/models/kursant.models';

export interface CancelLessonDialogData { lesson: InstructorLessonRow }

/** Okno modalne odwołania/przywrócenia CAŁEJ lekcji. */
@Component({
  selector: 'app-cancel-lesson-dialog',
  standalone: true,
  imports: [CommonModule, DatePipe, FormsModule, MatDialogModule, MatButtonModule, MatFormFieldModule, MatInputModule, MatSnackBarModule],
  template: `
    <h2 mat-dialog-title>{{ data.lesson.status === 'cancelled' ? 'Przywróć lekcję' : 'Odwołaj lekcję' }} — {{ data.lesson.topic || (data.lesson.lesson_date | date:'d.MM.yyyy') }}</h2>
    <mat-dialog-content>
      @if (data.lesson.status === 'cancelled') {
        <p>Lekcja jest obecnie odwołana. Przywrócić ją jako zaplanowaną?</p>
      } @else {
        <mat-form-field appearance="fill" class="full">
          <mat-label>Powód odwołania</mat-label>
          <input matInput [(ngModel)]="reason" name="reason" required>
        </mat-form-field>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-stroked-button type="button" mat-dialog-close>Anuluj</button>
      @if (data.lesson.status === 'cancelled') {
        <button mat-flat-button type="button" [disabled]="submitting()" (click)="uncancel()">Przywróć lekcję</button>
      } @else {
        <button mat-flat-button type="button" color="warn" [disabled]="submitting() || !reason.trim()" (click)="cancel()">Odwołaj lekcję</button>
      }
    </mat-dialog-actions>
  `,
  styles: [`.full { width: 100%; min-width: 320px; }`],
})
export class CancelLessonDialogComponent {
  private api   = inject(InstructorApiService);
  private snack = inject(MatSnackBar);
  private ref   = inject(MatDialogRef<CancelLessonDialogComponent>);
  data: CancelLessonDialogData = inject(MAT_DIALOG_DATA);

  reason = '';
  submitting = signal(false);

  cancel(): void {
    if (!this.reason.trim()) return;
    this.submitting.set(true);
    this.api.cancelLesson(this.data.lesson.id, this.reason.trim()).subscribe({
      next: res => {
        this.submitting.set(false);
        this.snack.open(res.message || 'Lekcja odwołana.', 'OK', { duration: 4000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.submitting.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się odwołać lekcji.', 'OK', { duration: 5000 });
      },
    });
  }

  uncancel(): void {
    this.submitting.set(true);
    this.api.uncancelLesson(this.data.lesson.id).subscribe({
      next: res => {
        this.submitting.set(false);
        this.snack.open(res.message || 'Lekcja przywrócona.', 'OK', { duration: 4000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.submitting.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się przywrócić lekcji.', 'OK', { duration: 5000 });
      },
    });
  }
}
