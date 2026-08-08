import { Component, signal } from '@angular/core';
import { Router } from '@angular/router';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { ApiService } from '../../core/services/api.service';
import { PlannerService } from '../../core/services/planner.service';

@Component({
  selector: 'app-auth',
  standalone: true,
  imports: [ReactiveFormsModule, MatCardModule, MatFormFieldModule, MatInputModule, MatButtonModule, MatIconModule, MatSnackBarModule],
  template: `
    <div class="auth-wrap">
      <mat-card class="auth-card">
        <mat-card-header>
          <mat-icon mat-card-avatar style="font-size:32px;width:32px;height:32px;color:var(--acc)">key</mat-icon>
          <mat-card-title>Konfiguracja API</mat-card-title>
          <mat-card-subtitle>Wprowadź dane dostępowe do backendu SZO Planner</mat-card-subtitle>
        </mat-card-header>

        <mat-card-content>
          <form [formGroup]="form" (ngSubmit)="save()">
            <mat-form-field appearance="outline" class="full-width">
              <mat-label>URL API (np. https://szo.feer.org.pl/api/v1/planner.php)</mat-label>
              <input matInput formControlName="baseUrl" placeholder="https://..."/>
              <mat-icon matSuffix>link</mat-icon>
            </mat-form-field>

            <mat-form-field appearance="outline" class="full-width">
              <mat-label>Bearer Token (klucz API)</mat-label>
              <input matInput formControlName="apiKey" [type]="showKey() ? 'text' : 'password'"/>
              <button mat-icon-button matSuffix type="button" (click)="showKey.set(!showKey())">
                <mat-icon>{{ showKey() ? 'visibility_off' : 'visibility' }}</mat-icon>
              </button>
            </mat-form-field>

            <div class="btn-row">
              <button mat-flat-button color="primary" type="submit" [disabled]="form.invalid || testing()">
                @if (testing()) { Testuje… } @else { Zapisz i połącz }
              </button>
              @if (apiService.isConfigured()) {
                <button mat-button type="button" (click)="router.navigate(['/timetable'])">Anuluj</button>
              }
            </div>
          </form>
        </mat-card-content>

        @if (status()) {
          <mat-card-footer [class]="statusOk() ? 'status-ok' : 'status-err'">
            <mat-icon>{{ statusOk() ? 'check_circle' : 'error' }}</mat-icon>
            {{ status() }}
          </mat-card-footer>
        }
      </mat-card>
    </div>
  `,
  styles: [`
    .auth-wrap { display: flex; align-items: center; justify-content: center; min-height: 70vh; }
    .auth-card { width: 100%; max-width: 520px; }
    .full-width { width: 100%; margin-top: 12px; }
    .btn-row { display: flex; gap: 12px; margin-top: 8px; }
    mat-card-footer { display: flex; align-items: center; gap: 8px; padding: 12px 16px; font-size: 13px; }
    .status-ok  { background: rgba(45,213,138,.12); color: #2DD58A; }
    .status-err { background: rgba(248,113,113,.12); color: #F87171; }
  `],
})
export class AuthComponent {
  showKey = signal(false);
  testing = signal(false);
  status  = signal('');
  statusOk = signal(false);

  form: FormGroup;

  constructor(
    public apiService: ApiService,
    private planner: PlannerService,
    private fb: FormBuilder,
    public router: Router,
    private snack: MatSnackBar,
  ) {
    const cfg = apiService.getConfig();
    this.form = this.fb.group({
      baseUrl: [cfg.baseUrl || '', [Validators.required, Validators.pattern(/^https?:\/\/.+/)]],
      apiKey:  [cfg.apiKey  || '', Validators.required],
    });
  }

  save(): void {
    if (this.form.invalid) return;
    this.testing.set(true);
    this.status.set('');
    this.apiService.saveConfig(this.form.value);

    // Test połączenia: pobierz listę sal
    this.planner.getRooms().subscribe({
      next: () => {
        this.testing.set(false);
        this.statusOk.set(true);
        this.status.set('Połączono pomyślnie!');
        setTimeout(() => this.router.navigate(['/timetable']), 800);
      },
      error: (e) => {
        this.testing.set(false);
        this.statusOk.set(false);
        this.status.set(`Błąd ${e.status}: ${e.message}`);
      },
    });
  }
}
