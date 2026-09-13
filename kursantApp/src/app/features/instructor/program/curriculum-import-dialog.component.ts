import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';

export interface CurriculumImportDialogData { courseId: number }

/** Okno modalne "Wgraj sylabus (CSV)" — odpowiednik op=curr_import w _tab_program.php. */
@Component({
  selector: 'app-curriculum-import-dialog',
  standalone: true,
  imports: [CommonModule, FormsModule, MatDialogModule, MatButtonModule, MatFormFieldModule, MatInputModule, MatSnackBarModule],
  template: `
    <h2 mat-dialog-title>Wgraj sylabus (CSV)</h2>
    <mat-dialog-content>
      <p class="text-muted text-sm">Kolumny (separator <code>;</code> lub <code>,</code>): <strong>dział; temat; opis; czas_min</strong>. Pierwszy wiersz może być nagłówkiem. Wymagany jest tylko temat. Import <strong>dokłada</strong> pozycje — nie usuwa istniejących.</p>

      <div class="file-input-wrap">
        <label for="csv-file" class="file-label">
          <span class="material-symbols-outlined" aria-hidden="true">upload_file</span>
          Wybierz plik CSV
        </label>
        <input type="file" id="csv-file" accept=".csv,text/csv,text/plain" (change)="onFileChange($event)">
        @if (selectedFile) { <span class="text-sm">{{ selectedFile.name }}</span> }
        <span class="text-muted text-sm">Do 2 MB.</span>
      </div>

      <mat-form-field appearance="fill" class="full">
        <mat-label>… albo wklej treść</mat-label>
        <textarea matInput [(ngModel)]="csvText" rows="6" placeholder="Podstawy;Uruchamianie komputera;Włączanie i logowanie;45"></textarea>
      </mat-form-field>
      <p class="text-muted text-sm">Wgrany plik ma pierwszeństwo nad wklejoną treścią.</p>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-stroked-button type="button" mat-dialog-close>Anuluj</button>
      <button mat-flat-button type="button" [disabled]="saving() || (!selectedFile && !csvText.trim())" (click)="save()">Importuj</button>
    </mat-dialog-actions>
  `,
  styles: [`
    .full { width: 100%; min-width: 420px; }
    .file-input-wrap { display: flex; align-items: center; gap: .75rem; margin: .5rem 0 1rem; flex-wrap: wrap; }
    .file-label { display: inline-flex; align-items: center; gap: .4rem; cursor: pointer; color: var(--c-primary, #2563eb); font-size: .9rem; }
  `],
})
export class CurriculumImportDialogComponent {
  private api   = inject(InstructorApiService);
  private snack = inject(MatSnackBar);
  private ref   = inject(MatDialogRef<CurriculumImportDialogComponent>);
  data: CurriculumImportDialogData = inject(MAT_DIALOG_DATA);

  saving = signal(false);
  selectedFile: File | null = null;
  csvText = '';

  onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.selectedFile = input.files?.[0] ?? null;
  }

  save(): void {
    this.saving.set(true);
    const fd = new FormData();
    fd.append('course_id', String(this.data.courseId));
    if (this.csvText.trim()) fd.append('csv', this.csvText);
    if (this.selectedFile) fd.append('csv_file', this.selectedFile);

    this.api.importCurriculumCsv(fd).subscribe({
      next: res => {
        this.saving.set(false);
        this.snack.open(res.message || 'Zaimportowano.', 'OK', { duration: 7000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.saving.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się zaimportować sylabusa.', 'OK', { duration: 6000 });
      },
    });
  }
}
