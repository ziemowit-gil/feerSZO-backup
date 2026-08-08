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
import { Inject } from '@angular/core';
import { PlannerService } from '../../core/services/planner.service';
import { Room } from '../../core/models/planner.models';

@Component({
  selector: 'app-room-dialog',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, MatCheckboxModule, MatButtonModule, MatIconModule, MatProgressSpinnerModule],
  template: `
    <h2 mat-dialog-title>{{ data ? 'Edytuj salę' : 'Nowa sala' }}</h2>
    <mat-dialog-content>
      <form [formGroup]="form" class="room-form">
        <mat-form-field appearance="outline" class="full">
          <mat-label>Nazwa</mat-label>
          <input matInput formControlName="name"/>
        </mat-form-field>
        <div class="row2">
          <mat-form-field appearance="outline">
            <mat-label>Pojemność</mat-label>
            <input matInput type="number" formControlName="capacity"/>
          </mat-form-field>
          <mat-form-field appearance="outline">
            <mat-label>Typ</mat-label>
            <mat-select formControlName="type">
              <mat-option value="classroom">Klasa</mat-option>
              <mat-option value="lab">Lab</mat-option>
              <mat-option value="meeting">Sala konf.</mat-option>
              <mat-option value="online">Wirtualna</mat-option>
            </mat-select>
          </mat-form-field>
        </div>
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
        <mat-form-field appearance="outline" class="full">
          <mat-label>Udogodnienia (przecinek)</mat-label>
          <input matInput formControlName="amenities" placeholder="projektor,tablica,AC"/>
        </mat-form-field>
        <mat-checkbox formControlName="is_active">Aktywna</mat-checkbox>
      </form>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button mat-dialog-close>Anuluj</button>
      <button mat-flat-button color="primary" (click)="save()" [disabled]="form.invalid || saving()">
        @if (saving()) { Zapisywanie… } @else { Zapisz }
      </button>
    </mat-dialog-actions>
  `,
  styles: [`.room-form{display:flex;flex-direction:column;gap:6px;min-width:420px} .row2{display:grid;grid-template-columns:1fr 1fr;gap:8px} .full{width:100%}`],
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
      name:      [data?.name ?? '', Validators.required],
      capacity:  [data?.capacity ?? 20, [Validators.required, Validators.min(1)]],
      type:      [data?.type ?? 'classroom'],
      building:  [data?.building ?? ''],
      floor:     [data?.floor ?? ''],
      amenities: [(data?.amenities ?? []).join(',')],
      is_active: [data?.is_active ?? true],
    });
  }

  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const v = { ...this.form.value, amenities: this.form.value.amenities.split(',').map((s: string) => s.trim()).filter(Boolean) };
    const obs = this.data ? this.planner.updateRoom(this.data.id, v) : this.planner.createRoom(v);
    obs.subscribe({ next: r => { this.saving.set(false); this.ref.close(r); }, error: () => this.saving.set(false) });
  }
}

@Component({
  selector: 'app-rooms',
  standalone: true,
  imports: [
    CommonModule, MatTableModule, MatButtonModule, MatIconModule,
    MatTooltipModule, MatProgressSpinnerModule, MatSnackBarModule, MatDialogModule,
  ],
  template: `
    <div class="page-header">
      <div><h2 class="page-title">Sale</h2><p class="page-sub">Zarządzanie salami i zasobami</p></div>
      <button mat-flat-button color="primary" (click)="openDialog()"><mat-icon>add</mat-icon> Nowa sala</button>
    </div>

    @if (loading()) { <div class="loading-wrap"><mat-spinner [diameter]="36"/></div> }
    @else {
      <div class="table-wrap">
        <table mat-table [dataSource]="rooms()" class="planner-table">
          <ng-container matColumnDef="name">
            <th mat-header-cell *matHeaderCellDef>Nazwa</th>
            <td mat-cell *matCellDef="let r">{{ r.name }}</td>
          </ng-container>
          <ng-container matColumnDef="type">
            <th mat-header-cell *matHeaderCellDef>Typ</th>
            <td mat-cell *matCellDef="let r">
              <span class="type-pill">{{ typeLabel(r.type) }}</span>
            </td>
          </ng-container>
          <ng-container matColumnDef="capacity">
            <th mat-header-cell *matHeaderCellDef>Pojemność</th>
            <td mat-cell *matCellDef="let r">{{ r.capacity }} os.</td>
          </ng-container>
          <ng-container matColumnDef="location">
            <th mat-header-cell *matHeaderCellDef>Lokalizacja</th>
            <td mat-cell *matCellDef="let r">{{ r.building || '—' }} {{ r.floor ? '/ p.' + r.floor : '' }}</td>
          </ng-container>
          <ng-container matColumnDef="amenities">
            <th mat-header-cell *matHeaderCellDef>Udogodnienia</th>
            <td mat-cell *matCellDef="let r">
              @for (a of r.amenities; track a) { <span class="amenity-chip">{{ a }}</span> }
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
    }
  `,
  styles: [`
    .page-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; }
    .page-title { font-size: 1.4rem; font-weight: 800; margin: 0; }
    .page-sub { color: var(--t2); margin: 4px 0 0; font-size: 13px; }
    .loading-wrap { display: flex; justify-content: center; padding: 60px; }
    .table-wrap { overflow-x: auto; border: 1px solid var(--bor); border-radius: 6px; }
    .planner-table { width: 100%; }
    .type-pill { padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; background: var(--sur-hi); }
    .amenity-chip { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 10.5px; background: var(--sur-hi); margin: 1px; }
    .active-dot, .inactive-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: 5px; }
    .active-dot { background: #2DD58A; }
    .inactive-dot { background: #6B7280; }
    .empty-state { display: flex; flex-direction: column; align-items: center; padding: 48px; color: var(--t3); gap: 8px; }
    .empty-state mat-icon { font-size: 40px; width: 40px; height: 40px; }
  `],
})
export class RoomsComponent implements OnInit {
  loading = signal(false);
  rooms   = signal<Room[]>([]);
  cols = ['name', 'type', 'capacity', 'location', 'amenities', 'status', 'actions'];

  constructor(private planner: PlannerService, private dialog: MatDialog, private snack: MatSnackBar) {}

  ngOnInit(): void { this.load(); }

  load(): void {
    this.loading.set(true);
    this.planner.getRooms().subscribe({ next: r => { this.rooms.set(r); this.loading.set(false); }, error: () => this.loading.set(false) });
  }

  openDialog(room?: Room): void {
    this.dialog.open(RoomDialogComponent, { width: '500px', data: room ?? null, panelClass: 'planner-dialog' })
      .afterClosed().subscribe((r: Room | null) => {
        if (!r) return;
        this.rooms.update(all => {
          const idx = all.findIndex(x => x.id === r.id);
          return idx >= 0 ? all.map(x => x.id === r.id ? r : x) : [...all, r];
        });
        this.snack.open('Sala zapisana.', '', { duration: 2000 });
      });
  }

  typeLabel(t: string): string {
    return { classroom: 'Klasa', lab: 'Lab', meeting: 'Sala konf.', online: 'Online' }[t] ?? t;
  }
}
