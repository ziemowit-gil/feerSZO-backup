import { Component, inject, signal, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorLessonRow, InstructorRescheduleRequest } from '../../../core/models/kursant.models';
import { QuickDateChipsComponent } from '../../../shared/components/quick-date-chips.component';

export interface RescheduleDialogData { lesson: InstructorLessonRow }

/** Okno modalne zmiany terminu — propozycje kursanta/opiekuna (akceptuj/odrzuć) + zmiana bezpośrednia. */
@Component({
  selector: 'app-reschedule-dialog',
  standalone: true,
  imports: [
    CommonModule, DatePipe, FormsModule, MatDialogModule, MatButtonModule,
    MatCheckboxModule, MatFormFieldModule, MatInputModule, MatSnackBarModule,
    QuickDateChipsComponent,
  ],
  template: `
    <h2 mat-dialog-title>Zmień termin — {{ data.lesson.topic || (data.lesson.lesson_date | date:'d.MM.yyyy') }}</h2>
    <mat-dialog-content>
      @if (pendingLoading()) {
        <p class="text-muted text-sm">Sprawdzanie propozycji…</p>
      } @else if (pendingRequests().length > 0) {
        <div class="pending-block">
          <h3>Propozycje od kursanta/opiekuna</h3>
          @for (r of pendingRequests(); track r.id) {
            <div class="reschedule-request">
              <div class="reschedule-request-text">
                <strong>{{ r.client_name || r.requested_by }}</strong> proponuje
                {{ r.proposed_date | date:'d.MM.yyyy' }} {{ r.proposed_from | slice:0:5 }}–{{ r.proposed_to | slice:0:5 }}
                @if (r.reason) { <span class="text-muted"> — {{ r.reason }}</span> }
              </div>
              <div class="reschedule-actions">
                <button mat-flat-button type="button" class="btn-small" [disabled]="decidingId() === r.id" (click)="decide(r, true)">Akceptuj</button>
                <button mat-stroked-button type="button" class="btn-small" [disabled]="decidingId() === r.id" (click)="decide(r, false)">Odrzuć</button>
              </div>
            </div>
          }
        </div>
      }

      <h3>Zmiana bezpośrednia</h3>
      <div class="resch-form">
        <mat-form-field appearance="fill" class="full">
          <mat-label>Nowa data</mat-label>
          <input matInput type="date" [(ngModel)]="reschDate" name="reschDate">
        </mat-form-field>
        <app-quick-date-chips (picked)="reschDate = $event" />
        <div class="times">
          <mat-form-field appearance="fill"><mat-label>Od</mat-label><input matInput type="time" [(ngModel)]="reschFrom" name="reschFrom"></mat-form-field>
          <mat-form-field appearance="fill"><mat-label>Do</mat-label><input matInput type="time" [(ngModel)]="reschTo" name="reschTo"></mat-form-field>
        </div>
        <mat-checkbox [(ngModel)]="reschNotify" name="reschNotify">Powiadom e-mailem</mat-checkbox>
        <mat-checkbox [(ngModel)]="reschNotifySms" name="reschNotifySms">także SMS-em</mat-checkbox>
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-stroked-button type="button" mat-dialog-close>Zamknij</button>
      <button mat-flat-button type="button" [disabled]="reschSubmitting()" (click)="submit()">Zmień termin</button>
    </mat-dialog-actions>
  `,
  styles: [`
    :host { display: block; min-width: 320px; }
    h3 { font-size: .85rem; font-weight: 600; margin: 0 0 .6rem; color: var(--c-text-muted); }
    .pending-block { margin-bottom: 1.25rem; }
    .reschedule-request {
      display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
      padding: .65rem .85rem; border: 1px solid var(--c-warning, #d97706); border-radius: .6rem;
      background: var(--c-warning-bg, #fffbeb); margin-bottom: .5rem;
      &:last-child { margin-bottom: 0; }
    }
    .reschedule-request-text { font-size: .88rem; }
    .reschedule-actions { display: flex; gap: .5rem; flex-shrink: 0; }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; }
    .resch-form { display: flex; flex-direction: column; gap: .1rem; }
    .resch-form .full { width: 100%; }
    .times { display: flex; gap: .5rem; }
    .times mat-form-field { flex: 1; }
  `],
})
export class RescheduleDialogComponent implements OnInit {
  private api    = inject(InstructorApiService);
  private snack  = inject(MatSnackBar);
  private ref    = inject(MatDialogRef<RescheduleDialogComponent>);
  data: RescheduleDialogData = inject(MAT_DIALOG_DATA);

  pendingRequests = signal<InstructorRescheduleRequest[]>([]);
  pendingLoading  = signal(false);
  decidingId      = signal<number | null>(null);

  reschDate = ''; reschFrom = ''; reschTo = ''; reschNotify = true; reschNotifySms = false;
  reschSubmitting = signal(false);

  ngOnInit(): void {
    const l = this.data.lesson;
    this.reschDate = l.lesson_date;
    this.reschFrom = l.time_from?.slice(0, 5) ?? '';
    this.reschTo   = l.time_to?.slice(0, 5) ?? '';
    if (l.pending_reschedule_count > 0) {
      this.pendingLoading.set(true);
      this.api.getReschedulePending(l.id).subscribe({
        next: res => { this.pendingLoading.set(false); if (res.success && res.data) this.pendingRequests.set(res.data); },
        error: () => this.pendingLoading.set(false),
      });
    }
  }

  decide(r: InstructorRescheduleRequest, accept: boolean): void {
    const note = accept ? '' : (prompt('Komentarz do odrzucenia (opcjonalnie):', '') ?? '');
    this.decidingId.set(r.id);
    this.api.rescheduleDecide(r.id, accept, note).subscribe({
      next: res => {
        this.decidingId.set(null);
        this.snack.open(res.message || 'Zapisano decyzję.', 'OK', { duration: 4000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.decidingId.set(null);
        this.snack.open(err?.error?.error || 'Nie udało się zapisać decyzji.', 'OK', { duration: 5000 });
      },
    });
  }

  submit(): void {
    if (!this.reschDate) { this.snack.open('Podaj nową datę.', 'OK', { duration: 3000 }); return; }
    this.reschSubmitting.set(true);
    this.api.rescheduleLesson(this.data.lesson.id, this.reschDate, this.reschFrom, this.reschTo, this.reschNotify, this.reschNotifySms).subscribe({
      next: res => {
        this.reschSubmitting.set(false);
        this.snack.open(res.message || 'Termin zmieniony.', 'OK', { duration: 4000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.reschSubmitting.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się zmienić terminu.', 'OK', { duration: 5000 });
      },
    });
  }
}
