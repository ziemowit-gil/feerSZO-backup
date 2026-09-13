import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';

export interface PromptDialogData {
  title?: string;
  message?: string;
  label?: string;
  placeholder?: string;
  initialValue?: string;
  required?: boolean;
  confirmLabel?: string;
}

/**
 * Generyczne okno modalne z jednym polem tekstowym — zamiennik natywnego
 * `prompt()`, żeby wpisywanie krótkiej wartości (np. powodu) też odbywało
 * się w spójnym oknie MatDialog. `afterClosed()` zwraca wpisany tekst (string,
 * może być pusty gdy pole opcjonalne) albo `undefined` po anulowaniu.
 */
@Component({
  selector: 'app-prompt-dialog',
  standalone: true,
  imports: [CommonModule, FormsModule, MatDialogModule, MatButtonModule, MatFormFieldModule, MatInputModule],
  template: `
    <h2 mat-dialog-title>{{ data.title || 'Podaj wartość' }}</h2>
    <mat-dialog-content>
      @if (data.message) { <p class="message">{{ data.message }}</p> }
      <mat-form-field appearance="fill" class="full">
        <mat-label>{{ data.label || 'Wartość' }}</mat-label>
        <input matInput [(ngModel)]="value" [placeholder]="data.placeholder || ''" (keydown.enter)="submit()">
      </mat-form-field>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-stroked-button type="button" mat-dialog-close>Anuluj</button>
      <button mat-flat-button type="button" [disabled]="data.required && !value.trim()" (click)="submit()">{{ data.confirmLabel || 'OK' }}</button>
    </mat-dialog-actions>
  `,
  styles: [`
    .message { color: var(--c-text-muted); }
    .full { width: 100%; min-width: 320px; }
  `],
})
export class PromptDialogComponent {
  ref = inject(MatDialogRef<PromptDialogComponent>);
  data: PromptDialogData = inject(MAT_DIALOG_DATA);

  value = this.data.initialValue ?? '';

  submit(): void {
    if (this.data.required && !this.value.trim()) return;
    this.ref.close(this.value);
  }
}
