import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MatTableModule } from '@angular/material/table';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatSelectModule } from '@angular/material/select';
import { MatChipsModule } from '@angular/material/chips';
import { MatTooltipModule } from '@angular/material/tooltip';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { PlannerService } from '../../core/services/planner.service';
import { Session, Room, Draft } from '../../core/models/planner.models';
import { SessionDialogComponent, SessionDialogData } from './session-dialog.component';

const BLOCK_COLORS: Record<string, string> = {
  theory: '#3B82F6', workshop: '#F59E0B', lab: '#10B981', code_review: '#8B5CF6', project: '#EF4444',
};
const BLOCK_LABELS: Record<string, string> = {
  theory: 'Teoria', workshop: 'Warsztat', lab: 'Lab', code_review: 'Code Review', project: 'Projekt',
};

@Component({
  selector: 'app-sessions',
  standalone: true,
  imports: [
    CommonModule, FormsModule, MatTableModule, MatButtonModule, MatIconModule,
    MatInputModule, MatFormFieldModule, MatSelectModule, MatChipsModule,
    MatTooltipModule, MatProgressSpinnerModule, MatSnackBarModule, MatDialogModule,
  ],
  template: `
    <div class="page-header">
      <div>
        <h2 class="page-title">Sesje</h2>
        <p class="page-sub">Lista zajęć z filtrowaniem</p>
      </div>
      <button mat-flat-button color="primary" (click)="openDialog()">
        <mat-icon>add</mat-icon> Nowa sesja
      </button>
    </div>

    <div class="filter-bar">
      <mat-form-field appearance="outline" class="filter-field">
        <mat-label>Data od</mat-label>
        <input matInput type="date" [(ngModel)]="filterFrom" (ngModelChange)="load()"/>
      </mat-form-field>
      <mat-form-field appearance="outline" class="filter-field">
        <mat-label>Data do</mat-label>
        <input matInput type="date" [(ngModel)]="filterTo" (ngModelChange)="load()"/>
      </mat-form-field>
      <mat-form-field appearance="outline" class="filter-field">
        <mat-label>Typ bloku</mat-label>
        <mat-select [(ngModel)]="filterBlock" (ngModelChange)="applyFilter()">
          <mat-option value="">Wszystkie</mat-option>
          @for (bt of blockTypes; track bt.key) { <mat-option [value]="bt.key">{{ bt.label }}</mat-option> }
        </mat-select>
      </mat-form-field>
      <mat-form-field appearance="outline" class="filter-field">
        <mat-label>Tryb</mat-label>
        <mat-select [(ngModel)]="filterMode" (ngModelChange)="applyFilter()">
          <mat-option value="">Wszystkie</mat-option>
          <mat-option value="onsite">Stacjonarne</mat-option>
          <mat-option value="remote">Zdalne</mat-option>
          <mat-option value="hybrid">Hybrydowe</mat-option>
        </mat-select>
      </mat-form-field>
    </div>

    @if (loading()) {
      <div class="loading-wrap"><mat-spinner [diameter]="36"/></div>
    } @else {
      <div class="table-wrap">
        <table mat-table [dataSource]="filtered()" class="planner-table">
          <ng-container matColumnDef="date">
            <th mat-header-cell *matHeaderCellDef>Data</th>
            <td mat-cell *matCellDef="let s">{{ s.lesson_date }}</td>
          </ng-container>
          <ng-container matColumnDef="time">
            <th mat-header-cell *matHeaderCellDef>Czas</th>
            <td mat-cell *matCellDef="let s">{{ s.time_from }}–{{ s.time_to }}</td>
          </ng-container>
          <ng-container matColumnDef="block">
            <th mat-header-cell *matHeaderCellDef>Typ</th>
            <td mat-cell *matCellDef="let s">
              <span class="type-chip" [style.background]="blockColor(s.block_type)">{{ blockLabel(s.block_type) }}</span>
            </td>
          </ng-container>
          <ng-container matColumnDef="mode">
            <th mat-header-cell *matHeaderCellDef>Tryb</th>
            <td mat-cell *matCellDef="let s">
              <mat-icon [matTooltip]="s.mode" style="font-size:18px">{{ modeIcon(s.mode) }}</mat-icon>
            </td>
          </ng-container>
          <ng-container matColumnDef="topic">
            <th mat-header-cell *matHeaderCellDef>Temat</th>
            <td mat-cell *matCellDef="let s">{{ s.topic || '—' }}</td>
          </ng-container>
          <ng-container matColumnDef="room">
            <th mat-header-cell *matHeaderCellDef>Sala</th>
            <td mat-cell *matCellDef="let s">{{ roomName(s.room_id) }}</td>
          </ng-container>
          <ng-container matColumnDef="draft">
            <th mat-header-cell *matHeaderCellDef>Status</th>
            <td mat-cell *matCellDef="let s">
              @if (s.draft_id) { <span class="draft-chip">DRAFT</span> } @else { <span class="pub-chip">PUB</span> }
            </td>
          </ng-container>
          <ng-container matColumnDef="actions">
            <th mat-header-cell *matHeaderCellDef></th>
            <td mat-cell *matCellDef="let s">
              <button mat-icon-button (click)="openDialog(s)" matTooltip="Edytuj"><mat-icon>edit</mat-icon></button>
            </td>
          </ng-container>
          <tr mat-header-row *matHeaderRowDef="cols"></tr>
          <tr mat-row *matRowDef="let row; columns: cols" class="clickable-row" (click)="openDialog(row)"></tr>
        </table>
        @if (!filtered().length) {
          <div class="empty-state"><mat-icon>event_busy</mat-icon><p>Brak sesji w tym zakresie</p></div>
        }
      </div>
    }
  `,
  styles: [`
    .page-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; }
    .page-title { font-size: 1.4rem; font-weight: 800; margin: 0; }
    .page-sub { color: var(--t2); margin: 4px 0 0; font-size: 13px; }
    .filter-bar { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
    .filter-field { min-width: 160px; }
    .loading-wrap { display: flex; justify-content: center; padding: 60px; }
    .table-wrap { overflow-x: auto; border: 1px solid var(--bor); border-radius: 6px; }
    .planner-table { width: 100%; }
    .type-chip { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; color: #fff; }
    .draft-chip { padding: 2px 6px; border-radius: 3px; background: rgba(251,191,36,.15); color: #FBBF24; font-size: 10.5px; font-weight: 700; }
    .pub-chip   { padding: 2px 6px; border-radius: 3px; background: rgba(45,213,138,.12); color: #2DD58A; font-size: 10.5px; font-weight: 700; }
    .clickable-row { cursor: pointer; }
    .clickable-row:hover td { background: var(--sur-hi); }
    .empty-state { display: flex; flex-direction: column; align-items: center; padding: 48px; color: var(--t3); gap: 8px; }
    .empty-state mat-icon { font-size: 40px; width: 40px; height: 40px; }
  `],
})
export class SessionsComponent implements OnInit {
  loading = signal(false);
  sessions = signal<Session[]>([]);
  filtered = signal<Session[]>([]);
  rooms = signal<Room[]>([]);
  drafts = signal<Draft[]>([]);

  cols = ['date', 'time', 'block', 'mode', 'topic', 'room', 'draft', 'actions'];
  blockTypes = [
    { key: 'theory', label: 'Teoria' }, { key: 'workshop', label: 'Warsztat' },
    { key: 'lab', label: 'Lab' }, { key: 'code_review', label: 'Code Review' }, { key: 'project', label: 'Projekt' },
  ];

  filterFrom = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
  filterTo   = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
  filterBlock = '';
  filterMode  = '';

  constructor(private planner: PlannerService, private dialog: MatDialog, private snack: MatSnackBar) {}

  ngOnInit(): void {
    this.planner.getRooms().subscribe(r => this.rooms.set(r));
    this.planner.getDrafts().subscribe(d => this.drafts.set(d));
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.planner.getSessions({ date_from: this.filterFrom, date_to: this.filterTo, limit: '500' }).subscribe({
      next: r => { this.sessions.set(r.data); this.applyFilter(); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }

  applyFilter(): void {
    let list = this.sessions();
    if (this.filterBlock) list = list.filter(s => s.block_type === this.filterBlock);
    if (this.filterMode)  list = list.filter(s => s.mode === this.filterMode);
    this.filtered.set(list);
  }

  openDialog(session?: Session): void {
    const data: SessionDialogData = { session: session ?? null, prefill: {}, rooms: this.rooms(), drafts: this.drafts() };
    this.dialog.open(SessionDialogComponent, { width: '580px', data, panelClass: 'planner-dialog' })
      .afterClosed().subscribe((s: Session | null) => {
        if (!s) return;
        this.sessions.update(all => {
          const idx = all.findIndex(x => x.id === s.id);
          return idx >= 0 ? all.map(x => x.id === s.id ? s : x) : [...all, s];
        });
        this.applyFilter();
        this.snack.open('Sesja zapisana.', '', { duration: 2000 });
      });
  }

  blockColor(bt: string): string { return BLOCK_COLORS[bt] ?? '#6B7280'; }
  blockLabel(bt: string): string { return BLOCK_LABELS[bt] ?? bt; }
  modeIcon(m: string): string    { return m === 'remote' ? 'videocam' : m === 'hybrid' ? 'devices' : 'location_on'; }
  roomName(id: number | null): string { return id ? (this.rooms().find(r => r.id === id)?.name ?? `#${id}`) : '—'; }
}
