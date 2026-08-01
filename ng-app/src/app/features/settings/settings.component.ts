import { Component, inject, OnInit } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatCardModule } from '@angular/material/card';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { MatDividerModule } from '@angular/material/divider';
import { PageHeaderComponent } from '../../shared/components/page-header/page-header.component';
import { AppConfigService, AppConfig } from '../../core/services/app-config.service';

const LS_KEY = 'feer_app_config_override';

@Component({
  selector: 'app-settings',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule,
    MatFormFieldModule, MatInputModule, MatButtonModule,
    MatIconModule, MatCardModule, MatSnackBarModule,
    MatDividerModule, PageHeaderComponent,
  ],
  templateUrl: './settings.component.html',
  styleUrl: './settings.component.scss',
})
export class SettingsComponent implements OnInit {
  readonly cfg  = inject(AppConfigService);
  private fb    = inject(FormBuilder);
  private snack = inject(MatSnackBar);

  form = this.fb.group({
    apiUrl:   [''],
    appTitle: [''],
    orgName:  [''],
    baseHref: [''],
  });

  savedOverride: Partial<AppConfig> | null = null;

  ngOnInit(): void {
    this.form.patchValue(this.cfg.getAll());
    const raw = localStorage.getItem(LS_KEY);
    if (raw) { try { this.savedOverride = JSON.parse(raw); } catch {} }
  }

  save(): void {
    const vals = this.form.getRawValue() as AppConfig;
    localStorage.setItem(LS_KEY, JSON.stringify(vals));
    this.savedOverride = vals;
    this.snack.open('Ustawienia zapisane. Przeładuj stronę, aby zastosować.', 'OK', { duration: 4000 });
  }

  reset(): void {
    localStorage.removeItem(LS_KEY);
    this.savedOverride = null;
    this.snack.open('Przywrócono domyślne ustawienia z app.config.json', 'OK', { duration: 3000 });
    location.reload();
  }

  downloadConfig(): void {
    const vals = this.form.getRawValue();
    const blob = new Blob([JSON.stringify(vals, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'app.config.json';
    a.click();
  }
}
