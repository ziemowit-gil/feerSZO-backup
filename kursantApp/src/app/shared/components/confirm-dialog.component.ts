import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';

export interface ConfirmDialogData {
  title?: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  danger?: boolean;
}

/**
 * Generyczne okno modalne potwierdzenia — zamiennik natywnego `confirm()`,
 * żeby każda akcja (nawet destrukcyjna) była spójnym oknem MatDialog, a nie
 * natywnym oknem przeglądarki. `afterClosed()` zwraca `true` po potwierdzeniu,
 * `undefined` po anulowaniu/zamknięciu.
 */
@Component({
  selector: 'app-confirm-dialog',
  standalone: true,
  imports: [CommonModule, MatDialogModule, MatButtonModule],
  template: `
    <h2 mat-dialog-title>{{ data.title || 'Potwierdź' }}</h2>
    <mat-dialog-content>
      <p class="message">{{ data.message }}</p>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-stroked-button type="button" mat-dialog-close>{{ data.cancelLabel || 'Anuluj' }}</button>
      <button mat-flat-button type="button" [class.danger-btn]="data.danger" (click)="ref.close(true)">{{ data.confirmLabel || 'OK' }}</button>
    </mat-dialog-actions>
  `,
  styles: [`
    .message { white-space: pre-line; min-width: 280px; max-width: 420px; }
    .danger-btn { background: #b91c1c !important; color: #fff !important; }
  `],
})
export class ConfirmDialogComponent {
  ref = inject(MatDialogRef<ConfirmDialogComponent>);
  data: ConfirmDialogData = inject(MAT_DIALOG_DATA);
}
