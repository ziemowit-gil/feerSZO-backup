import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { MatTableModule } from '@angular/material/table';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatTooltipModule } from '@angular/material/tooltip';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { MatDialog, MatDialogModule, MatDialogRef, MAT_DIALOG_DATA } from '@angular/material/dialog';
import { MatChipsModule } from '@angular/material/chips';
import { Inject } from '@angular/core';
import { PlannerService } from '../../core/services/planner.service';
import { Room, RoomModeSupport } from '../../core/models/planner.models';

const MODE_LABELS: Record<string, string> = {
  onsite: 'Stacjonarna', remote: 'Online', hybrid: 'Hybrydowa', all: 'Dowolna',
};
const MODE_COLORS: Record<string, string> = {
  onsite: '#10B981', remote: '#3B82F6', hybrid: '#F59E0B', all: '#8B5CF6',
};

@Component({
  selector: 'app-room-dialog',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, MatCheckboxModule, MatButtonModule, MatProgressSpinnerModule],
  template: `
    <h2 mat-dialog-title>{{ data ? 'Edytuj salę' : 'Nowa sala' }}</h2>
    <mat-dialog-content>
      <form [formGroup]="form" class="room-form">
        <mat-form-field appearance="outline" class="full">
          <mat-label>Nazwa</mat-label>
          <input matInput formControlName="name" placeholder="np. Sala A1, Zoom Mentor Jan"/>
        </mat-form-field>
        <div class="row2">
          <mat-form-field appearance="outline">
            <mat-label>Pojemność (os.)</mat-label>
            <input matInput type="number" formControlName="capacity"/>
          </mat-form-field>
          <mat-form-field appearance="outline">
            <mat-label>Stanowiska</mat-label>
            <input matInput type="number" formControlName="workstations"/>
          </mat-form-field>
        </div>
        <mat-form-field appearance="outline" class="full">
          <mat-label>Tryb nauczania</mat-label>
          <mat-select formControlName="mode_support">
            <mat-option value="onsite">Stacjonarny — fizyczna sala</mat-option>
            <mat-option value="remote">Online — platforma zdalna (Zoom, Teams, Meet…)</mat-option>
            <mat-option value="hybrid">Hybrydowy — stacjonarne + zdalne</mat-option>
            <mat-option value="all">Dowolny</mat-option>
          </mat-select>
        </mat-form-field>
        <mat-form-field appearance="outline" class="full">
          <mat-label>Lokalizacja (adres / URL platformy)</mat-label>
          <input matInput formControlName="location" placeholder="np. ul. Parkowa 3, pok. 201 lub zoom.us/j/12345"/>
        </mat-form-field>
        <div class="row2">
          <mat-form-field appearance="outline">
            <mat-label>Budynek</mat-label>
            <input matInput formControlName="building"/>
          </mat-form-field>
          <mat-form-field appearance="outline">
            <mat-label>Piętro</mat-label>
            <input matInput formControlName="floor"/>
          </mat-form-field>
        </div>
        <div class="checks-row">
          <mat-checkbox formControlName="has_projector">Projektor</mat-checkbox>
          <mat-checkbox formControlName="has_dual_mon">Podwójny monitor</mat-checkbox>
          <mat-checkbox formControlName="is_active">Aktywna</mat-checkbox>
        </div>
        <mat-form-field appearance="outline" class="full">
          <mat-label>Notatki</mat-label>
          <textarea matInput formControlName="notes" rows="2"></textarea>
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
  styles: [`.room-form{display:flex;flex-direction:column;gap:6px;min-width:440px} .row2{display:grid;grid-template-columns:1fr 1fr;gap:8px} .full{width:100%} .checks-row{display:flex;gap:16px;flex-wrap:wrap;padding:4px 0}`],
})
export class RoomDialogComponent {
  form: FormGroup;
  saving = signal(false);

  constructor(
    @Inject(MAT_DIALOG_DATA) public data: Room | null,
    private ref: MatDialogRef<RoomDialogComponent>,
    private fb: FormBuilder,
    private planner: PlannerService,
  ) {
    this.form = this.fb.group({
      name:         [data?.name ?? '', Validators.required],
      capacity:     [data?.capacity ?? 20, [Validators.required, Validators.min(1)]],
      workstations: [data?.workstations ?? 0],
      mode_support: [data?.mode_support ?? 'onsite'],
      location:     [data?.location ?? ''],
      building:     [data?.building ?? ''],
      floor:        [data?.floor ?? ''],
      has_projector:[data?.has_projector ?? false],
      has_dual_mon: [data?.has_dual_mon ?? false],
      is_active:    [data?.is_active ?? true],
      notes:        [data?.notes ?? ''],
    });
  }

  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const obs = this.data ? this.planner.updateRoom(this.data.id, this.form.value) : this.planner.createRoom(this.form.value);
    obs.subscribe({ next: r => { this.saving.set(false); this.ref.close(r); }, error: () => this.saving.set(false) });
  }
}

@Component({
  selector: 'app-rooms',
  standalone: true,
  imports: [
    CommonModule, MatTableModule, MatButtonModule, MatIconModule, MatChipsModule,
    MatFormFieldModule, MatSelectModule, MatTooltipModule,
    MatProgressSpinnerModule, MatSnackBarModule, MatDialogModule,
  ],
  template: `
    <div class="page-header">
      <div><h2 class="page-title">Sale</h2><p class="page-sub">Sale stacjonarne i platformy nauczania zdalnego</p></div>
      <button mat-flat-button color="primary" (click)="openDialog()"><mat-icon>add</mat-icon> Nowa sala</button>
    </div>

    <div class="filter-bar">
      <span class="filter-label">Filtruj tryb:</span>
      <button mat-stroked-button [class.active-filter]="modeFilter() === ''" (click)="modeFilter.set(''); load()">Wszystkie</button>
      <button mat-stroked-button [class.active-filter]="modeFilter() === 'onsite'"  (click)="modeFilter.set('onsite'); load()">
        <mat-icon style="font-size:16px">location_on</mat-icon> Stacjonarne
      </button>
      <button mat-stroked-button [class.active-filter]="modeFilter() === 'remote'"  (click)="modeFilter.set('remote'); load()">
        <mat-icon style="font-size:16px">videocam</mat-icon> Online
      </button>
      <button mat-stroked-button [class.active-filter]="modeFilter() === 'hybrid'"  (click)="modeFilter.set('hybrid'); load()">
        <mat-icon style="font-size:16px">devices</mat-icon> Hybrydowe
      </button>
    </div>

    @if (loading()) { <div class="loading-wrap"><mat-spinner [diameter]="36"/></div> }
    @else {
      <div class="table-wrap">
        <table mat-table [dataSource]="rooms()" class="planner-table">
          <ng-container matColumnDef="name">
            <th mat-header-cell *matHeaderCellDef>Nazwa</th>
            <td mat-cell *matCellDef="let r">
              <span style="display:flex;align-items:center;gap:6px">
                <mat-icon style="font-size:16px;color:var(--t3)">{{ modeIcon(r.mode_support) }}</mat-icon>
                {{ r.name }}
              </span>
            </td>
          </ng-container>
          <ng-container matColumnDef="mode">
            <th mat-header-cell *matHeaderCellDef>Tryb nauczania</th>
            <td mat-cell *matCellDef="let r">
              <span class="mode-chip" [style.background]="modeColor(r.mode_support) + '22'" [style.color]="modeColor(r.mode_support)">
                {{ modeLabel(r.mode_support) }}
              </span>
            </td>
          </ng-container>
          <ng-container matColumnDef="capacity">
            <th mat-header-cell *matHeaderCellDef>Pojemność</th>
            <td mat-cell *matCellDef="let r">{{ r.capacity }} os.</td>
          </ng-container>
          <ng-container matColumnDef="location">
            <th mat-header-cell *matHeaderCellDef>Lokalizacja / URL</th>
            <td mat-cell *matCellDef="let r" class="loc-cell" [matTooltip]="r.location">{{ r.location || '—' }}</td>
          </ng-container>
          <ng-container matColumnDef="features">
            <th mat-header-cell *matHeaderCellDef>Udogodnienia</th>
            <td mat-cell *matCellDef="let r">
              @if (r.has_projector) { <span class="feat-chip">🖥 Proj.</span> }
              @if (r.has_dual_mon) { <span class="feat-chip">⎊ 2×Mon.</span> }
              @if (r.workstations) { <span class="feat-chip">{{ r.workstations }} st.</span> }
            </td>
          </ng-container>
          <ng-container matColumnDef="status">
            <th mat-header-cell *matHeaderCellDef>Status</th>
            <td mat-cell *matCellDef="let r">
              <span [class]="r.is_active ? 'active-dot' : 'inactive-dot'"></span>
              {{ r.is_active ? 'Aktywna' : 'Nieaktywna' }}
            </td>
          </ng-container>
          <ng-container matColumnDef="actions">
            <th mat-header-cell *matHeaderCellDef></th>
            <td mat-cell *matCellDef="let r">
              <button mat-icon-button (click)="openDialog(r)" matTooltip="Edytuj"><mat-icon>edit</mat-icon></button>
            </td>
          </ng-container>
          <tr mat-header-row *matHeaderRowDef="cols"></tr>
          <tr mat-row *matRowDef="let row; columns: cols"></tr>
        </table>
        @if (!rooms().length) {
          <div class="empty-state"><mat-icon>meeting_room</mat-icon><p>Brak sal. Dodaj pierwszą.</p></div>
        }
      </div>

      <!-- info kafelek o online -->
      @if (modeFilter() === 'remote' || modeFilter() === '') {
        <div class="online-info">
          <mat-icon style="color:var(--acc)">info</mat-icon>
          <span>Sale online to platformy zdalne (Zoom, Teams, Meet) — wpisz URL spotkania w polu Lokalizacja.</span>
        </div>
      }
    }
  `,
  styles: [`
    .page-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; }
    .page-title { font-size: 1.4rem; font-weight: 800; margin: 0; }
    .page-sub { color: var(--t2); margin: 4px 0 0; font-size: 13px; }
    .filter-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
    .filter-label { font-size: 12px; color: var(--t3); text-transform: uppercase; letter-spacing: .06em; }
    .active-filter { border-color: var(--acc) !important; color: var(--acc) !important; }
    .loading-wrap { display: flex; justify-content: center; padding: 60px; }
    .table-wrap { overflow-x: auto; border: 1px solid var(--bor); border-radius: 6px; }
    .planner-table { width: 100%; }
    .mode-chip { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
    .loc-cell { max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--t2); font-size: 12.5px; }
    .feat-chip { padding: 1px 6px; border-radius: 3px; font-size: 10.5px; background: var(--sur-hi); margin: 1px; }
    .active-dot, .inactive-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: 5px; }
    .active-dot { background: #2DD58A; }
    .inactive-dot { background: #6B7280; }
    .empty-state { display: flex; flex-direction: column; align-items: center; padding: 48px; color: var(--t3); gap: 8px; }
    .empty-state mat-icon { font-size: 40px; width: 40px; height: 40px; }
    .online-info { display: flex; align-items: center; gap: 8px; margin-top: 12px; font-size: 12.5px; color: var(--t2); padding: 10px 14px; border: 1px solid var(--bor); border-radius: 6px; background: var(--sur); }
  `],
})
export class RoomsComponent implements OnInit {
  loading    = signal(false);
  rooms      = signal<Room[]>([]);
  modeFilter = signal<string>('');
  cols = ['name', 'mode', 'capacity', 'location', 'features', 'status', 'actions'];

  constructor(private planner: PlannerService, private dialog: MatDialog, private snack: MatSnackBar) {}

  ngOnInit(): void { this.load(); }

  load(): void {
    this.loading.set(true);
    const params: Record<string, string> = {};
    if (this.modeFilter()) params['mode'] = this.modeFilter();
    this.planner.getRooms(params).subscribe({
      next: r => { this.rooms.set(r); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }

  openDialog(room?: Room): void {
    this.dialog.open(RoomDialogComponent, { width: '520px', data: room ?? null, panelClass: 'planner-dialog' })
      .afterClosed().subscribe((r: Room | null) => {
        if (!r) return;
        this.rooms.update(all => { const idx = all.findIndex(x => x.id === r.id); return idx >= 0 ? all.map(x => x.id === r.id ? r : x) : [...all, r]; });
        this.snack.open('Sala zapisana.', '', { duration: 2000 });
      });
  }

  modeLabel(m: string): string  { return MODE_LABELS[m] ?? m; }
  modeColor(m: string): string  { return MODE_COLORS[m] ?? '#6B7280'; }
  modeIcon(m: string): string   { return m === 'remote' ? 'videocam' : m === 'hybrid' ? 'devices' : m === 'all' ? 'hub' : 'location_on'; }
}
