import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorCurriculumItem } from '../../../core/models/kursant.models';

export interface CurriculumItemDialogData { courseId: number; item: InstructorCurriculumItem | null }

/** Okno modalne dodawania/edycji pozycji planu (sylabusu) kursu. */
@Component({
  selector: 'app-curriculum-item-dialog',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, MatDialogModule, MatButtonModule, MatFormFieldModule, MatInputModule, MatCheckboxModule, MatSnackBarModule],
  template: `
    <h2 mat-dialog-title>{{ data.item ? 'Edytuj pozycję planu' : 'Nowa pozycja planu' }}</h2>
    <form [formGroup]="form" (ngSubmit)="save()">
      <mat-dialog-content>
        <mat-form-field appearance="fill" class="full">
          <mat-label>Temat</mat-label>
          <input matInput formControlName="title" required>
        </mat-form-field>
        <div class="row-2">
          <mat-form-field appearance="fill">
            <mat-label>Dział / moduł</mat-label>
            <input matInput formControlName="section" placeholder="np. Podstawy obsługi komputera">
          </mat-form-field>
          <mat-form-field appearance="fill">
            <mat-label>Czas (min)</mat-label>
            <input matInput type="number" min="0" step="5" formControlName="est_minutes">
          </mat-form-field>
        </div>
        <mat-form-field appearance="fill" class="full">
          <mat-label>Opis</mat-label>
          <textarea matInput formControlName="description" rows="3"></textarea>
        </mat-form-field>
        <mat-checkbox formControlName="is_active">Pozycja aktywna (widoczna w planie kursanta)</mat-checkbox>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-stroked-button type="button" mat-dialog-close>Anuluj</button>
        <button mat-flat-button type="submit" [disabled]="form.invalid || saving()">Zapisz</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: [`
    .full { width: 100%; min-width: 340px; }
    .row-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 0 1rem; }
  `],
})
export class CurriculumItemDialogComponent {
  private api   = inject(InstructorApiService);
  private fb    = inject(FormBuilder);
  private snack = inject(MatSnackBar);
  private ref   = inject(MatDialogRef<CurriculumItemDialogComponent>);
  data: CurriculumItemDialogData = inject(MAT_DIALOG_DATA);

  saving = signal(false);

  form: FormGroup = this.fb.nonNullable.group({
    title: [this.data.item?.title ?? '', Validators.required],
    section: [this.data.item?.section ?? ''],
    est_minutes: [this.data.item?.est_minutes ?? 0],
    description: [this.data.item?.description ?? ''],
    is_active: [this.data.item ? !!this.data.item.is_active : true],
  });

  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const v = this.form.getRawValue();
    this.api.saveCurriculumItem({
      item_id: this.data.item?.id, course_id: this.data.courseId,
      section: v.section, title: v.title, description: v.description,
      est_minutes: v.est_minutes, is_active: v.is_active,
    }).subscribe({
      next: res => {
        this.saving.set(false);
        this.snack.open(res.message || 'Zapisano.', 'OK', { duration: 4000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.saving.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się zapisać pozycji.', 'OK', { duration: 5000 });
      },
    });
  }
}
