import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { MatTableModule } from '@angular/material/table';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatChipsModule } from '@angular/material/chips';
import { MatTooltipModule } from '@angular/material/tooltip';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { MatDialog, MatDialogModule, MatDialogRef, MAT_DIALOG_DATA } from '@angular/material/dialog';
import { MatMenuModule } from '@angular/material/menu';
import { Inject } from '@angular/core';
import { PlannerService } from '../../core/services/planner.service';
import { Draft } from '../../core/models/planner.models';

@Component({
  selector: 'app-draft-dialog',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatButtonModule],
  template: `
    <h2 mat-dialog-title>Nowy szkic planu</h2>
    <mat-dialog-content>
      <form [formGroup]="form" style="display:flex;flex-direction:column;gap:8px;min-width:380px;margin-top:8px">
        <mat-form-field appearance="outline">
          <mat-label>Tytuł</mat-label>
          <input matInput formControlName="title" placeholder="np. Plan wrzesień 2026"/>
        </mat-form-field>
        <mat-form-field appearance="outline">
          <mat-label>Opis (opcjonalnie)</mat-label>
          <textarea matInput formControlName="description" rows="3"></textarea>
        </mat-form-field>
      </form>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button mat-dialog-close>Anuluj</button>
      <button mat-flat-button color="primary" (click)="save()" [disabled]="form.invalid || saving()">
        @if (saving()) { Tworzenie… } @else { Utwórz }
      </button>
    </mat-dialog-actions>
  `,
})
export class DraftCreateDialogComponent {
  form: FormGroup;
  saving = signal(false);
  constructor(
    @Inject(MAT_DIALOG_DATA) public data: { forkFrom?: number } | null,
    private ref: MatDialogRef<DraftCreateDialogComponent>,
    private fb: FormBuilder,
    private planner: PlannerService,
  ) {
    this.form = this.fb.group({ title: ['', Validators.required], description: [''] });
  }
  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const v = this.form.value;
    const obs = this.data?.forkFrom
      ? this.planner.forkDraft(this.data.forkFrom, { title: v.title })
      : this.planner.createDraft(v);
    obs.subscribe({ next: d => { this.saving.set(false); this.ref.close(d); }, error: () => this.saving.set(false) });
  }
}

const STATUS_COLORS: Record<string, string> = {
  draft: '#6B7280', review: '#F59E0B', approved: '#3B82F6', published: '#2DD58A', archived: '#374151',
};
const STATUS_LABELS: Record<string, string> = {
  draft: 'Szkic', review: 'Recenzja', approved: 'Zatwierdzony', published: 'Opublikowany', archived: 'Archiwalny',
};

@Component({
  selector: 'app-drafts',
  standalone: true,
  imports: [
    CommonModule, MatTableModule, MatButtonModule, MatIconModule, MatChipsModule,
    MatTooltipModule, MatProgressSpinnerModule, MatSnackBarModule, MatDialogModule, MatMenuModule,
  ],
  template: `
    <div class="page-header">
      <div><h2 class="page-title">Szkice planów</h2><p class="page-sub">Zarządzanie wersjami roboczymi planu zajęć</p></div>
      <button mat-flat-button color="primary" (click)="openCreate()"><mat-icon>add</mat-icon> Nowy szkic</button>
    </div>

    @if (loading()) { <div class="loading-wrap"><mat-spinner [diameter]="36"/></div> }
    @else {
      <div class="table-wrap">
        <table mat-table [dataSource]="drafts()" class="planner-table">
          <ng-container matColumnDef="title">
            <th mat-header-cell *matHeaderCellDef>Tytuł</th>
            <td mat-cell *matCellDef="let d"><strong>{{ d.title }}</strong></td>
          </ng-container>
          <ng-container matColumnDef="status">
            <th mat-header-cell *matHeaderCellDef>Status</th>
            <td mat-cell *matCellDef="let d">
              <span class="status-chip" [style.background]="statusBg(d.status)" [style.color]="statusColor(d.status)">
                {{ statusLabel(d.status) }}
              </span>
            </td>
          </ng-container>
          <ng-container matColumnDef="desc">
            <th mat-header-cell *matHeaderCellDef>Opis</th>
            <td mat-cell *matCellDef="let d" class="desc-cell">{{ d.description || '—' }}</td>
          </ng-container>
          <ng-container matColumnDef="created">
            <th mat-header-cell *matHeaderCellDef>Utworzony</th>
            <td mat-cell *matCellDef="let d">{{ d.created_at | date:'dd.MM.yyyy' }}</td>
          </ng-container>
          <ng-container matColumnDef="updated">
            <th mat-header-cell *matHeaderCellDef>Zmieniony</th>
            <td mat-cell *matCellDef="let d">{{ d.updated_at | date:'dd.MM.yyyy' }}</td>
          </ng-container>
          <ng-container matColumnDef="actions">
            <th mat-header-cell *matHeaderCellDef></th>
            <td mat-cell *matCellDef="let d">
              <button mat-icon-button [matMenuTriggerFor]="menu" [matMenuTriggerData]="{d: d}" (click)="$event.stopPropagation()">
                <mat-icon>more_vert</mat-icon>
              </button>
            </td>
          </ng-container>
          <tr mat-header-row *matHeaderRowDef="cols"></tr>
          <tr mat-row *matRowDef="let row; columns: cols"></tr>
        </table>
        @if (!drafts().length) {
          <div class="empty-state"><mat-icon>content_copy</mat-icon><p>Brak szkiców. Utwórz pierwszy.</p></div>
        }
      </div>
    }

    <mat-menu #menu="matMenu">
      <ng-template matMenuContent let-d="d">
        <button mat-menu-item (click)="fork(d)"><mat-icon>fork_right</mat-icon> Rozgałęź</button>
        @if (d.status === 'draft') {
          <button mat-menu-item (click)="changeStatus(d, 'review')"><mat-icon>rate_review</mat-icon> Wyślij do recenzji</button>
        }
        @if (d.status === 'review') {
          <button mat-menu-item (click)="changeStatus(d, 'approved')"><mat-icon>check</mat-icon> Zatwierdź</button>
        }
        @if (d.status === 'approved') {
          <button mat-menu-item (click)="publishDraft(d)"><mat-icon>publish</mat-icon> Opublikuj</button>
        }
        @if (d.status !== 'published' && d.status !== 'archived') {
          <button mat-menu-item (click)="changeStatus(d, 'archived')" class="danger-item">
            <mat-icon>archive</mat-icon> Archiwizuj
          </button>
        }
      </ng-template>
    </mat-menu>
  `,
  styles: [`
    .page-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; }
    .page-title { font-size: 1.4rem; font-weight: 800; margin: 0; }
    .page-sub { color: var(--t2); margin: 4px 0 0; font-size: 13px; }
    .loading-wrap { display: flex; justify-content: center; padding: 60px; }
    .table-wrap { overflow-x: auto; border: 1px solid var(--bor); border-radius: 6px; }
    .planner-table { width: 100%; }
    .status-chip { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
    .desc-cell { max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--t2); }
    .empty-state { display: flex; flex-direction: column; align-items: center; padding: 48px; color: var(--t3); gap: 8px; }
    .empty-state mat-icon { font-size: 40px; width: 40px; height: 40px; }
    .danger-item { color: #F87171 !important; }
  `],
})
export class DraftsComponent implements OnInit {
  loading = signal(false);
  drafts  = signal<Draft[]>([]);
  cols = ['title', 'status', 'desc', 'created', 'updated', 'actions'];

  constructor(private planner: PlannerService, private dialog: MatDialog, private snack: MatSnackBar) {}

  ngOnInit(): void { this.load(); }

  load(): void {
    this.loading.set(true);
    this.planner.getDrafts().subscribe({ next: d => { this.drafts.set(d); this.loading.set(false); }, error: () => this.loading.set(false) });
  }

  openCreate(): void {
    this.dialog.open(DraftCreateDialogComponent, { width: '440px', data: null, panelClass: 'planner-dialog' })
      .afterClosed().subscribe((d: Draft | null) => { if (d) { this.drafts.update(all => [d, ...all]); this.snack.open('Szkic utworzony.', '', { duration: 2000 }); } });
  }

  fork(d: Draft): void {
    this.dialog.open(DraftCreateDialogComponent, { width: '440px', data: { forkFrom: d.id }, panelClass: 'planner-dialog' })
      .afterClosed().subscribe((nd: Draft | null) => { if (nd) { this.drafts.update(all => [nd, ...all]); this.snack.open('Rozgałęziono.', '', { duration: 2000 }); } });
  }

  changeStatus(d: Draft, status: string): void {
    this.planner.updateDraft(d.id, { status: status as Draft['status'] }).subscribe({
      next: nd => { this.drafts.update(all => all.map(x => x.id === nd.id ? nd : x)); this.snack.open(`Status → ${this.statusLabel(status)}`, '', { duration: 2000 }); },
      error: () => this.snack.open('Błąd zmiany statusu.', '', { duration: 3000 }),
    });
  }

  publishDraft(d: Draft): void {
    this.planner.publishDraft(d.id).subscribe({
      next: nd => { this.drafts.update(all => all.map(x => x.id === nd.id ? nd : x)); this.snack.open('Opublikowano plan!', '', { duration: 3000 }); },
      error: () => this.snack.open('Błąd publikacji.', '', { duration: 3000 }),
    });
  }

  statusLabel(s: string): string { return STATUS_LABELS[s] ?? s; }
  statusColor(s: string): string { return STATUS_COLORS[s] ?? '#6B7280'; }
  statusBg(s: string): string    { return (STATUS_COLORS[s] ?? '#6B7280') + '22'; }
}
