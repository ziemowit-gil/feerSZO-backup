import { Component, inject, signal, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorAttendanceEntry, InstructorLessonRow } from '../../../core/models/kursant.models';

export interface AttendanceDialogData { lesson: InstructorLessonRow }

/** Okno modalne obecności — jedna lekcja, lista kursantów + odwołanie/przywrócenie udziału. */
@Component({
  selector: 'app-attendance-dialog',
  standalone: true,
  imports: [CommonModule, FormsModule, MatDialogModule, MatButtonModule, MatCheckboxModule, MatSnackBarModule],
  template: `
    <h2 mat-dialog-title>Obecność — {{ data.lesson.topic || (data.lesson.lesson_date | date:'d.MM.yyyy') }}</h2>
    <mat-dialog-content>
      @if (loading()) {
        <p class="text-muted text-sm">Ładowanie…</p>
      } @else if (attendance().length === 0) {
        <p class="text-muted text-sm mb-0">Brak zapisanych kursantów.</p>
      } @else {
        <ul class="attendance-list">
          @for (a of attendance(); track a.client_id) {
            <li class="attendee-row">
              <mat-checkbox [(ngModel)]="attendedMap[a.client_id]" [name]="'att-' + a.client_id" [disabled]="!!a.cancelled">
                {{ a.client_name }}
                @if (a.cancelled) { <span class="text-muted text-sm"> (odwołany)</span> }
              </mat-checkbox>
              <span class="attendee-action">
                @if (a.cancelled) {
                  <button mat-stroked-button type="button" class="btn-small" [disabled]="busy()" (click)="restore(a)">Przywróć</button>
                } @else {
                  <button mat-stroked-button type="button" class="btn-small" [disabled]="busy()" (click)="cancelOne(a)">Odwołaj</button>
                }
              </span>
            </li>
          }
        </ul>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-stroked-button type="button" mat-dialog-close>Zamknij</button>
      <button mat-flat-button type="button" [disabled]="loading() || saving()" (click)="save()">Zapisz obecność</button>
    </mat-dialog-actions>
  `,
  styles: [`
    .attendance-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .25rem; min-width: 320px; }
    .attendee-row { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .35rem 0; border-bottom: 1px solid var(--c-border); &:last-child { border-bottom: none; } }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; }
  `],
})
export class AttendanceDialogComponent implements OnInit {
  private api    = inject(InstructorApiService);
  private snack  = inject(MatSnackBar);
  private ref    = inject(MatDialogRef<AttendanceDialogComponent>);
  data: AttendanceDialogData = inject(MAT_DIALOG_DATA);

  loading = signal(true);
  saving  = signal(false);
  busy    = signal(false);
  attendance = signal<InstructorAttendanceEntry[]>([]);
  attendedMap: Record<number, boolean> = {};
  private changed = false;

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.getSessionAttendance(this.data.lesson.id).subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) {
          this.attendance.set(res.data);
          this.attendedMap = {};
          for (const a of res.data) this.attendedMap[a.client_id] = !!a.attended;
        }
      },
      error: () => this.loading.set(false),
    });
  }

  save(): void {
    const attended = Object.entries(this.attendedMap).filter(([, v]) => v).map(([k]) => Number(k));
    this.saving.set(true);
    this.api.markAttendance(this.data.lesson.id, attended).subscribe({
      next: res => {
        this.saving.set(false);
        this.snack.open(res.message || 'Obecność zapisana.', 'OK', { duration: 4000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.saving.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się zapisać obecności.', 'OK', { duration: 5000 });
      },
    });
  }

  cancelOne(a: InstructorAttendanceEntry): void {
    const reason = prompt(`Powód odwołania udziału (${a.client_name}):`, '') ?? '';
    this.busy.set(true);
    this.api.cancelAttendee(this.data.lesson.id, a.client_id, reason).subscribe({
      next: res => { this.busy.set(false); this.changed = true; this.snack.open(res.message || 'Udział odwołany.', 'OK', { duration: 4000 }); this.load(); },
      error: err => { this.busy.set(false); this.snack.open(err?.error?.error || 'Nie udało się odwołać udziału.', 'OK', { duration: 5000 }); },
    });
  }

  restore(a: InstructorAttendanceEntry): void {
    this.busy.set(true);
    this.api.restoreAttendee(this.data.lesson.id, a.client_id).subscribe({
      next: res => { this.busy.set(false); this.changed = true; this.snack.open(res.message || 'Udział przywrócony.', 'OK', { duration: 4000 }); this.load(); },
      error: err => { this.busy.set(false); this.snack.open(err?.error?.error || 'Nie udało się przywrócić udziału.', 'OK', { duration: 5000 }); },
    });
  }
}
