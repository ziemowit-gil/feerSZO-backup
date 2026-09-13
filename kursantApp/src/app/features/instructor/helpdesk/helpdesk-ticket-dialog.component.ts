import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { HD_CATEGORIES, HD_PRIORITIES } from '../../../core/models/kursant.models';

/** Okno modalne "Nowe zgłoszenie" — Helpdesk IT, odpowiednik api_helpdesk.php action=create. */
@Component({
  selector: 'app-helpdesk-ticket-dialog',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, MatDialogModule, MatButtonModule,
    MatFormFieldModule, MatInputModule, MatSelectModule, MatSnackBarModule,
  ],
  template: `
    <h2 mat-dialog-title>Nowe zgłoszenie do Helpdesk IT</h2>
    <form [formGroup]="form" (ngSubmit)="save()">
      <mat-dialog-content>
        <mat-form-field appearance="fill" class="full">
          <mat-label>Temat</mat-label>
          <input matInput formControlName="title" required>
        </mat-form-field>
        <div class="row-2">
          <mat-form-field appearance="fill">
            <mat-label>Kategoria</mat-label>
            <mat-select formControlName="category">
              @for (c of categories; track c.value) { <mat-option [value]="c.value">{{ c.label }}</mat-option> }
            </mat-select>
          </mat-form-field>
          <mat-form-field appearance="fill">
            <mat-label>Priorytet</mat-label>
            <mat-select formControlName="priority">
              @for (p of priorities; track p.value) { <mat-option [value]="p.value">{{ p.label }}</mat-option> }
            </mat-select>
          </mat-form-field>
        </div>
        <mat-form-field appearance="fill" class="full">
          <mat-label>Opis</mat-label>
          <textarea matInput formControlName="description" rows="5" required></textarea>
        </mat-form-field>

        <div class="file-input-wrap">
          <label for="hd-file" class="file-label">
            <span class="material-symbols-outlined" aria-hidden="true">attach_file</span>
            Załącz plik (opcjonalnie, np. zrzut ekranu)
          </label>
          <input type="file" id="hd-file" (change)="onFileChange($event)" class="file-input">
          @if (selectedFile) { <span class="text-sm" aria-live="polite">Plik: {{ selectedFile.name }}</span> }
        </div>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-stroked-button type="button" mat-dialog-close>Anuluj</button>
        <button mat-flat-button type="submit" [disabled]="form.invalid || saving()">Wyślij zgłoszenie</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: [`
    .full { width: 100%; min-width: 340px; }
    .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
    .file-input-wrap { display: flex; align-items: center; gap: .75rem; margin: .25rem 0 .5rem; flex-wrap: wrap; }
    .file-label { display: inline-flex; align-items: center; gap: .4rem; cursor: pointer; color: var(--c-primary, #2563eb); font-size: .9rem; }
    .file-input { max-width: 220px; }
  `],
})
export class HelpdeskTicketDialogComponent {
  private api   = inject(InstructorApiService);
  private fb    = inject(FormBuilder);
  private snack = inject(MatSnackBar);
  private ref   = inject(MatDialogRef<HelpdeskTicketDialogComponent>);

  readonly categories = Object.entries(HD_CATEGORIES).map(([value, label]) => ({ value, label }));
  readonly priorities = Object.entries(HD_PRIORITIES).map(([value, label]) => ({ value, label }));

  saving = signal(false);
  selectedFile: File | null = null;

  form: FormGroup = this.fb.nonNullable.group({
    title: ['', Validators.required],
    category: ['it_inne'],
    priority: ['normalny'],
    description: ['', Validators.required],
  });

  onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.selectedFile = input.files?.[0] ?? null;
  }

  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const v  = this.form.getRawValue();
    const fd = new FormData();
    fd.append('title', v.title);
    fd.append('category', v.category);
    fd.append('priority', v.priority);
    fd.append('description', v.description);
    if (this.selectedFile) fd.append('attachments[]', this.selectedFile);

    this.api.createHelpdeskTicket(fd).subscribe({
      next: res => {
        this.saving.set(false);
        this.snack.open(res.message || 'Zgłoszenie wysłane.', 'OK', { duration: 4000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.saving.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się wysłać zgłoszenia.', 'OK', { duration: 5000 });
      },
    });
  }
}
