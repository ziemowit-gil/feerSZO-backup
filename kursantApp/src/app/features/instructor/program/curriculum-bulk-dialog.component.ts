import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';

export interface CurriculumBulkDialogData { courseId: number }

interface BulkRow { section: string; title: string; description: string; est_minutes: number | null }

/** Okno modalne "Dodaj kilka tematów naraz" — odpowiednik formularza zbiorczego (op=curr_bulk) w _tab_program.php. */
@Component({
  selector: 'app-curriculum-bulk-dialog',
  standalone: true,
  imports: [CommonModule, FormsModule, MatDialogModule, MatButtonModule, MatSnackBarModule],
  template: `
    <h2 mat-dialog-title>Dodaj kilka tematów naraz</h2>
    <mat-dialog-content>
      <p class="text-muted text-sm">Wypełnij tyle wierszy, ile potrzebujesz — puste zostaną pominięte. Wymagany jest tylko temat.</p>
      <div class="k-table-wrap">
        <table class="bulk-table">
          <thead><tr><th>#</th><th>Dział</th><th>Temat *</th><th>Opis</th><th>Czas (min)</th></tr></thead>
          <tbody>
            @for (r of rows; track $index) {
              <tr>
                <td class="text-muted text-sm">{{ $index + 1 }}</td>
                <td><input type="text" [(ngModel)]="r.section" [name]="'sec' + $index" placeholder="np. Podstawy obsługi"></td>
                <td><input type="text" [(ngModel)]="r.title" [name]="'title' + $index" placeholder="np. Włączanie i logowanie"></td>
                <td><input type="text" [(ngModel)]="r.description" [name]="'desc' + $index"></td>
                <td><input type="number" min="0" step="5" [(ngModel)]="r.est_minutes" [name]="'min' + $index" class="min-input"></td>
              </tr>
            }
          </tbody>
        </table>
      </div>
      <button mat-stroked-button type="button" class="btn-small" (click)="addRow()">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">add</span>
        Kolejny wiersz
      </button>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-stroked-button type="button" mat-dialog-close>Anuluj</button>
      <button mat-flat-button type="button" [disabled]="saving()" (click)="save()">Dodaj tematy</button>
    </mat-dialog-actions>
  `,
  styles: [`
    .bulk-table { width: 100%; border-collapse: collapse; min-width: 560px; }
    .bulk-table th { text-align: left; font-size: .78rem; color: var(--c-text-muted); padding: .3rem .4rem; }
    .bulk-table td { padding: .25rem .4rem; }
    .bulk-table input { width: 100%; padding: .35rem .5rem; border: 1px solid var(--c-border); border-radius: .4rem; font-size: .85rem; }
    .min-input { max-width: 5rem; }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; margin-top: .5rem; }
  `],
})
export class CurriculumBulkDialogComponent {
  private api   = inject(InstructorApiService);
  private snack = inject(MatSnackBar);
  private ref   = inject(MatDialogRef<CurriculumBulkDialogComponent>);
  data: CurriculumBulkDialogData = inject(MAT_DIALOG_DATA);

  saving = signal(false);
  rows: BulkRow[] = Array.from({ length: 5 }, () => ({ section: '', title: '', description: '', est_minutes: null }));

  addRow(): void {
    this.rows.push({ section: '', title: '', description: '', est_minutes: null });
  }

  save(): void {
    const items = this.rows
      .filter(r => r.title.trim() !== '')
      .map(r => ({ section: r.section, title: r.title, description: r.description, est_minutes: r.est_minutes ?? 0 }));
    if (items.length === 0) {
      this.snack.open('Wpisz przynajmniej jeden temat.', 'OK', { duration: 3000 });
      return;
    }
    this.saving.set(true);
    this.api.saveCurriculumBulk(this.data.courseId, items).subscribe({
      next: res => {
        this.saving.set(false);
        this.snack.open(res.message || 'Dodano.', 'OK', { duration: 4000 });
        if (res.success) this.ref.close(true);
      },
      error: err => {
        this.saving.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się dodać tematów.', 'OK', { duration: 5000 });
      },
    });
  }
}
