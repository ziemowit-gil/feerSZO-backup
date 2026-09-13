import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatListModule } from '@angular/material/list';

export interface CoursePickerDialogData {
  courses: { id: number; name: string }[];
  selectedId: number | null;
}

/** Okno modalne wyboru aktywnej grupy — otwierane z przycisku w panelu bocznym (InstructorShellComponent). */
@Component({
  selector: 'app-course-picker-dialog',
  standalone: true,
  imports: [CommonModule, MatDialogModule, MatButtonModule, MatListModule],
  template: `
    <h2 mat-dialog-title>Wybierz grupę</h2>
    <mat-dialog-content>
      <mat-nav-list>
        <a mat-list-item [class.active]="data.selectedId === null" (click)="ref.close({ picked: true, id: null })">
          — wszystkie grupy —
        </a>
        @for (c of data.courses; track c.id) {
          <a mat-list-item [class.active]="data.selectedId === c.id" (click)="ref.close({ picked: true, id: c.id })">
            {{ c.name }}
          </a>
        }
      </mat-nav-list>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-stroked-button type="button" mat-dialog-close>Zamknij</button>
    </mat-dialog-actions>
  `,
  styles: [`
    mat-nav-list { min-width: 280px; }
    a.active { background: #eff6ff; color: #2563eb; font-weight: 600; }
  `],
})
export class CoursePickerDialogComponent {
  ref  = inject(MatDialogRef<CoursePickerDialogComponent>);
  data: CoursePickerDialogData = inject(MAT_DIALOG_DATA);
}
