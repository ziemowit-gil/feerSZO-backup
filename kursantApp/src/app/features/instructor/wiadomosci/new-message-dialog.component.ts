import { Component, inject, signal } from '@angular/core';
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
import { InstructorMessageRecipient, InstructorAdminRecipient } from '../../../core/models/kursant.models';

export interface NewMessageDialogData {
  recipients: InstructorMessageRecipient[];
  adminRecipients: InstructorAdminRecipient[];
}

/** Okno "Nowa wiadomość" — wybór adresata (kursant/kierownictwo) + treść. */
@Component({
  selector: 'app-new-message-dialog',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, MatDialogModule, MatButtonModule,
    MatFormFieldModule, MatInputModule, MatSelectModule, MatRadioModule, MatSnackBarModule,
  ],
  template: `
    <h2 mat-dialog-title>Nowa wiadomość</h2>
    <form [formGroup]="form" (ngSubmit)="send()">
      <mat-dialog-content>
        <mat-radio-group formControlName="kind" class="kind-group">
          <mat-radio-button value="student">Do kursanta</mat-radio-button>
          <mat-radio-button value="admin">Do kierownictwa</mat-radio-button>
        </mat-radio-group>

        @if (form.value.kind === 'student') {
          <mat-form-field appearance="fill" class="full">
            <mat-label>Kursant</mat-label>
            <mat-select formControlName="account_id">
              @for (r of data.recipients; track r.id) { <mat-option [value]="r.id">{{ r.name }} — {{ r.course_name }}</mat-option> }
            </mat-select>
          </mat-form-field>
        } @else {
          <mat-form-field appearance="fill" class="full">
            <mat-label>Adresat</mat-label>
            <mat-select formControlName="to_admin_id">
              @for (r of data.adminRecipients; track r.id) { <mat-option [value]="r.id">{{ r.label }}</mat-option> }
            </mat-select>
          </mat-form-field>
        }

        <mat-form-field appearance="fill" class="full">
          <mat-label>Temat</mat-label>
          <input matInput formControlName="subject">
        </mat-form-field>
        <mat-form-field appearance="fill" class="full">
          <mat-label>Treść</mat-label>
          <textarea matInput formControlName="body" rows="5" required></textarea>
        </mat-form-field>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-stroked-button type="button" mat-dialog-close>Anuluj</button>
        <button mat-flat-button type="submit" [disabled]="form.invalid || sending()">Wyślij</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: [`
    .kind-group { display: flex; gap: 1.25rem; margin-bottom: 1rem; }
    .full { width: 100%; }
  `],
})
export class NewMessageDialogComponent {
  private api   = inject(InstructorApiService);
  private fb    = inject(FormBuilder);
  private snack = inject(MatSnackBar);
  private ref   = inject(MatDialogRef<NewMessageDialogComponent>);
  data: NewMessageDialogData = inject(MAT_DIALOG_DATA);

  sending = signal(false);

  form: FormGroup = this.fb.nonNullable.group({
    kind: ['student'],
    account_id: [null as number | null],
    to_admin_id: [0],
    subject: [''],
    body: ['', Validators.required],
  });

  send(): void {
    if (this.form.invalid) return;
    const v = this.form.getRawValue();
    if (v.kind === 'student' && !v.account_id) {
      this.snack.open('Wybierz kursanta.', 'OK', { duration: 3000 });
      return;
    }
    this.sending.set(true);
    const req$ = v.kind === 'admin'
      ? this.api.sendAdminMessage(v.to_admin_id, v.body, v.subject)
      : this.api.sendStudentMessage(v.account_id!, v.body, v.subject);
    req$.subscribe({
      next: res => {
        this.sending.set(false);
        this.snack.open(res.message || 'Wysłano.', 'OK', { duration: 4000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.sending.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się wysłać wiadomości.', 'OK', { duration: 5000 });
      },
    });
  }
}
