import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { MatTableModule } from '@angular/material/table';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatTooltipModule } from '@angular/material/tooltip';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { MatDialog, MatDialogModule, MatDialogRef, MAT_DIALOG_DATA } from '@angular/material/dialog';
import { Inject } from '@angular/core';
import { PlannerService } from '../../core/services/planner.service';
import { TechPath } from '../../core/models/planner.models';

const LEVEL_COLORS: Record<string, string> = {
  beginner: '#10B981', intermediate: '#3B82F6', advanced: '#8B5CF6', expert: '#EF4444',
};
const LEVEL_LABELS: Record<string, string> = {
  beginner: 'Podstawowy', intermediate: 'Średniozaawansowany', advanced: 'Zaawansowany', expert: 'Ekspert',
};

@Component({
  selector: 'app-tech-path-dialog',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, MatButtonModule],
  template: `
    <h2 mat-dialog-title>{{ data ? 'Edytuj ścieżkę' : 'Nowa ścieżka technologiczna' }}</h2>
    <mat-dialog-content>
      <form [formGroup]="form" style="display:flex;flex-direction:column;gap:8px;min-width:420px;margin-top:8px">
        <mat-form-field appearance="outline">
          <mat-label>Nazwa</mat-label>
          <input matInput formControlName="name" placeholder="np. Python Backend, Frontend React"/>
        </mat-form-field>
        <mat-form-field appearance="outline">
          <mat-label>Slug (bez spacji)</mat-label>
          <input matInput formControlName="slug" placeholder="python-backend"/>
        </mat-form-field>
        <mat-form-field appearance="outline">
          <mat-label>Opis</mat-label>
          <textarea matInput formControlName="description" rows="3"></textarea>
        </mat-form-field>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <mat-form-field appearance="outline">
            <mat-label>Domyślny poziom</mat-label>
            <mat-select formControlName="default_level">
              <mat-option value="beginner">Podstawowy</mat-option>
              <mat-option value="intermediate">Średniozaawansowany</mat-option>
              <mat-option value="advanced">Zaawansowany</mat-option>
              <mat-option value="expert">Ekspert</mat-option>
            </mat-select>
          </mat-form-field>
          <mat-form-field appearance="outline">
            <mat-label>Czas trwania (godziny)</mat-label>
            <input matInput type="number" formControlName="duration_hours"/>
          </mat-form-field>
        </div>
        <mat-form-field appearance="outline">
          <mat-label>Kolor (hex)</mat-label>
          <input matInput formControlName="color" placeholder="#3B82F6"/>
        </mat-form-field>
      </form>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button mat-dialog-close>Anuluj</button>
      <button mat-flat-button color="primary" (click)="save()" [disabled]="form.invalid || saving()">
        @if (saving()) { Zapisywanie… } @else { Zapisz }
      </button>
    </mat-dialog-actions>
  `,
})
export class TechPathDialogComponent {
  form: FormGroup;
  saving = signal(false);
  constructor(
    @Inject(MAT_DIALOG_DATA) public data: TechPath | null,
    private ref: MatDialogRef<TechPathDialogComponent>,
    private fb: FormBuilder,
    private planner: PlannerService,
  ) {
    this.form = this.fb.group({
      name:          [data?.name ?? '', Validators.required],
      slug:          [data?.slug ?? '', Validators.required],
      description:   [data?.description ?? ''],
      default_level: [data?.default_level ?? 'beginner'],
      duration_hours:[data?.duration_hours ?? null],
      color:         [data?.color ?? '#3B82F6'],
    });
  }
  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const obs = this.data ? this.planner.updateTechPath(this.data.id, this.form.value) : this.planner.createTechPath(this.form.value);
    obs.subscribe({ next: p => { this.saving.set(false); this.ref.close(p); }, error: () => this.saving.set(false) });
  }
}

@Component({
  selector: 'app-tech-paths',
  standalone: true,
  imports: [
    CommonModule, MatTableModule, MatButtonModule, MatIconModule,
    MatTooltipModule, MatProgressSpinnerModule, MatSnackBarModule, MatDialogModule,
  ],
  template: `
    <div class="page-header">
      <div><h2 class="page-title">Ścieżki technologiczne</h2><p class="page-sub">Tematyczne kierunki nauki i poziomy zaawansowania</p></div>
      <button mat-flat-button color="primary" (click)="openDialog()"><mat-icon>add</mat-icon> Nowa ścieżka</button>
    </div>

    @if (loading()) { <div class="loading-wrap"><mat-spinner [diameter]="36"/></div> }
    @else {
      <div class="paths-grid">
        @for (p of paths(); track p.id) {
          <div class="path-card" [style.border-left-color]="p.color || '#3B82F6'">
            <div class="path-top">
              <div class="path-color" [style.background]="p.color || '#3B82F6'"></div>
              <div class="path-name">{{ p.name }}</div>
              <button mat-icon-button (click)="openDialog(p)" matTooltip="Edytuj" class="edit-btn"><mat-icon>edit</mat-icon></button>
            </div>
            <div class="path-slug">{{ p.slug }}</div>
            @if (p.description) { <div class="path-desc">{{ p.description }}</div> }
            <div class="path-meta">
              <span class="level-chip" [style.background]="levelBg(p.default_level ?? '')" [style.color]="levelColor(p.default_level ?? '')">
                {{ levelLabel(p.default_level ?? '') }}
              </span>
              @if (p.duration_hours) { <span class="hours-chip">{{ p.duration_hours }}h</span> }
            </div>
          </div>
        } @empty {
          <div class="empty-state"><mat-icon>fork_right</mat-icon><p>Brak ścieżek. Dodaj pierwszą.</p></div>
        }
      </div>
    }
  `,
  styles: [`
    .page-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; }
    .page-title { font-size: 1.4rem; font-weight: 800; margin: 0; }
    .page-sub { color: var(--t2); margin: 4px 0 0; font-size: 13px; }
    .loading-wrap { display: flex; justify-content: center; padding: 60px; }
    .paths-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
    .path-card { background: var(--sur); border: 1px solid var(--bor); border-left: 4px solid; border-radius: 6px; padding: 16px; display: flex; flex-direction: column; gap: 8px; }
    .path-top { display: flex; align-items: center; gap: 10px; }
    .path-color { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
    .path-name { font-weight: 700; font-size: 15px; flex: 1; }
    .edit-btn { margin-left: auto; }
    .path-slug { font-size: 11px; font-family: monospace; color: var(--t3); }
    .path-desc { font-size: 12.5px; color: var(--t2); line-height: 1.5; }
    .path-meta { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .level-chip { padding: 2px 8px; border-radius: 20px; font-size: 10.5px; font-weight: 700; }
    .hours-chip { padding: 2px 8px; border-radius: 20px; font-size: 10.5px; background: var(--sur-hi); color: var(--t2); }
    .empty-state { display: flex; flex-direction: column; align-items: center; padding: 60px; color: var(--t3); gap: 8px; grid-column: 1/-1; }
    .empty-state mat-icon { font-size: 40px; width: 40px; height: 40px; }
  `],
})
export class TechPathsComponent implements OnInit {
  loading = signal(false);
  paths   = signal<TechPath[]>([]);

  constructor(private planner: PlannerService, private dialog: MatDialog, private snack: MatSnackBar) {}

  ngOnInit(): void { this.load(); }

  load(): void {
    this.loading.set(true);
    this.planner.getTechPaths().subscribe({ next: p => { this.paths.set(p); this.loading.set(false); }, error: () => this.loading.set(false) });
  }

  openDialog(p?: TechPath): void {
    this.dialog.open(TechPathDialogComponent, { width: '500px', data: p ?? null, panelClass: 'planner-dialog' })
      .afterClosed().subscribe((np: TechPath | null) => {
        if (!np) return;
        this.paths.update(all => { const i = all.findIndex(x => x.id === np.id); return i >= 0 ? all.map(x => x.id === np.id ? np : x) : [...all, np]; });
        this.snack.open('Ścieżka zapisana.', '', { duration: 2000 });
      });
  }

  levelLabel(l: string): string { return LEVEL_LABELS[l] ?? l; }
  levelColor(l: string): string { return LEVEL_COLORS[l] ?? '#6B7280'; }
  levelBg(l: string): string    { return (LEVEL_COLORS[l] ?? '#6B7280') + '22'; }
}
