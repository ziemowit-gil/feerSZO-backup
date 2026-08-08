import { Component, OnInit, signal, computed } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatSelectModule } from '@angular/material/select';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatTooltipModule } from '@angular/material/tooltip';
import { MatChipsModule } from '@angular/material/chips';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { CdkDragDrop, CdkDrag, CdkDropList, CdkDropListGroup, transferArrayItem } from '@angular/cdk/drag-drop';
import { PlannerService } from '../../core/services/planner.service';
import { Session, Draft, Room, ConflictResult } from '../../core/models/planner.models';
import { SessionDialogComponent, SessionDialogData } from '../sessions/session-dialog.component';

const HOUR_PX = 64; // px per hour in the grid
const DAY_START = 8; // 8:00

const BLOCK_COLORS: Record<string, string> = {
  theory:      '#3B82F6',
  workshop:    '#F59E0B',
  lab:         '#10B981',
  code_review: '#8B5CF6',
  project:     '#EF4444',
};

@Component({
  selector: 'app-timetable',
  standalone: true,
  imports: [
    CommonModule, FormsModule,
    MatButtonModule, MatIconModule, MatSelectModule,
    MatProgressSpinnerModule, MatTooltipModule, MatChipsModule,
    MatSnackBarModule, MatDialogModule,
    CdkDrag, CdkDropList, CdkDropListGroup,
  ],
  providers: [DatePipe],
  template: `
    <div class="tt-page">
      <!-- Toolbar -->
      <div class="tt-toolbar">
        <button mat-icon-button (click)="prevWeek()"><mat-icon>chevron_left</mat-icon></button>
        <span class="week-label">{{ weekLabel() }}</span>
        <button mat-icon-button (click)="nextWeek()"><mat-icon>chevron_right</mat-icon></button>
        <button mat-button (click)="goToday()">Dziś</button>

        <div class="spacer"></div>

        <mat-select [(ngModel)]="selectedDraftId" (ngModelChange)="loadSessions()" placeholder="Plan (opublikowany)" class="draft-select">
          <mat-option [value]="null">Opublikowany plan</mat-option>
          @for (d of drafts(); track d.id) {
            <mat-option [value]="d.id">
              [{{ d.status.toUpperCase() }}] {{ d.title }}
            </mat-option>
          }
        </mat-select>

        <button mat-flat-button color="primary" (click)="openSessionDialog()">
          <mat-icon>add</mat-icon> Nowa sesja
        </button>
      </div>

      <!-- Legend -->
      <div class="tt-legend">
        @for (bt of blockTypes; track bt.key) {
          <span class="legend-chip" [style.border-color]="bt.color">
            <span class="legend-dot" [style.background]="bt.color"></span>{{ bt.label }}
          </span>
        }
      </div>

      @if (loading()) {
        <div class="loading-wrap"><mat-spinner [diameter]="40"/></div>
      } @else {
        <!-- Calendar grid -->
        <div class="tt-grid-wrap">
          <div class="tt-grid" cdkDropListGroup>
            <!-- Time axis -->
            <div class="time-col">
              <div class="day-header"></div>
              @for (h of hours; track h) {
                <div class="time-slot" [style.height.px]="HOUR_PX">{{ h }}</div>
              }
            </div>

            <!-- Day columns -->
            @for (day of weekDays(); track day.iso) {
              <div class="day-col">
                <div class="day-header" [class.today]="day.isToday">
                  <div class="day-name">{{ day.name }}</div>
                  <div class="day-date">{{ day.date }}</div>
                </div>

                <div class="day-body"
                     cdkDropList
                     [cdkDropListData]="sessionsFor(day.iso)"
                     [id]="day.iso"
                     [style.height.px]="HOUR_PX * 12"
                     (cdkDropListDropped)="onDrop($event, day.iso)"
                     (click)="onDayClick($event, day.iso)">

                  @for (s of sessionsFor(day.iso); track s.id) {
                    <div class="session-block"
                         cdkDrag
                         [cdkDragData]="s"
                         [style.top.px]="timeToY(s.time_from)"
                         [style.height.px]="durationToH(s.time_from, s.time_to)"
                         [style.background]="blockColor(s.block_type)"
                         [class.conflict]="hasConflict(s)"
                         [class.draft]="s.draft_id !== null"
                         [matTooltip]="sessionTooltip(s)"
                         (click)="$event.stopPropagation(); openSessionDialog(s)">
                      <div class="sb-time">{{ s.time_from }}–{{ s.time_to }}</div>
                      <div class="sb-title">{{ s.topic || ('Sesja #' + s.id) }}</div>
                      <div class="sb-meta">
                        <mat-icon inline>{{ modeIcon(s.mode) }}</mat-icon>
                        {{ blockLabel(s.block_type) }}
                        @if (s.draft_id) { <span class="draft-badge">DRAFT</span> }
                      </div>
                      <div cdkDragHandle class="drag-handle"><mat-icon inline>drag_indicator</mat-icon></div>
                    </div>
                  }
                </div>
              </div>
            }
          </div>
        </div>
      }
    </div>
  `,
  styles: [`
    .tt-page { display: flex; flex-direction: column; gap: 12px; height: calc(100vh - 90px); overflow: hidden; }

    .tt-toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .week-label { font-weight: 700; font-size: .95rem; min-width: 180px; text-align: center; }
    .spacer { flex: 1; }
    .draft-select { width: 240px; }

    .tt-legend { display: flex; gap: 8px; flex-wrap: wrap; }
    .legend-chip { display: flex; align-items: center; gap: 5px; font-size: 11.5px; padding: 3px 9px; border-radius: 12px; border: 1px solid; background: transparent; }
    .legend-dot { width: 8px; height: 8px; border-radius: 50%; }

    .loading-wrap { display: flex; justify-content: center; padding: 60px; }

    .tt-grid-wrap { overflow: auto; flex: 1; }
    .tt-grid { display: grid; grid-template-columns: 52px repeat(7, 1fr); min-width: 780px; }

    .time-col { display: flex; flex-direction: column; }
    .time-slot { display: flex; align-items: flex-start; justify-content: flex-end; padding-right: 8px; font-size: 10.5px; color: var(--t3); border-top: 1px solid var(--bor); box-sizing: border-box; }

    .day-col { display: flex; flex-direction: column; border-left: 1px solid var(--bor); }
    .day-header { height: 48px; display: flex; flex-direction: column; align-items: center; justify-content: center; border-bottom: 2px solid var(--bor); background: var(--sur); font-size: 11px; gap: 2px; }
    .day-header.today { background: rgba(232,148,26,.08); border-bottom-color: var(--acc); }
    .day-name { font-weight: 700; font-size: 10px; letter-spacing: .08em; text-transform: uppercase; color: var(--t2); }
    .day-date { font-size: 1rem; font-weight: 800; color: var(--t1); }

    .day-body { position: relative; background: repeating-linear-gradient(to bottom, transparent, transparent calc(var(--hpx, 64px) - 1px), var(--bor) calc(var(--hpx, 64px) - 1px), var(--bor) var(--hpx, 64px)); cursor: pointer; }

    .session-block {
      position: absolute; left: 3px; right: 3px;
      border-radius: 5px; padding: 4px 6px;
      font-size: 11px; color: #fff;
      box-shadow: 0 1px 4px rgba(0,0,0,.3);
      overflow: hidden; cursor: pointer; user-select: none;
      transition: box-shadow .15s, opacity .15s;
    }
    .session-block:hover { box-shadow: 0 2px 10px rgba(0,0,0,.4); opacity: .95; }
    .session-block.conflict { outline: 2px solid #F87171; }
    .session-block.draft { opacity: .75; border: 1px dashed rgba(255,255,255,.5); }
    .sb-time  { font-size: 10px; opacity: .85; }
    .sb-title { font-weight: 700; line-height: 1.2; margin: 2px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sb-meta  { font-size: 10px; opacity: .8; display: flex; gap: 4px; align-items: center; }
    .draft-badge { background: rgba(255,255,255,.25); padding: 0 4px; border-radius: 3px; }
    .drag-handle { position: absolute; right: 4px; top: 4px; cursor: grab; opacity: .6; }
    .drag-handle:active { cursor: grabbing; }
    .cdk-drag-preview { border-radius: 5px; box-shadow: 0 4px 20px rgba(0,0,0,.5); opacity: .9; }
    .cdk-drag-placeholder { opacity: 0; }
  `],
})
export class TimetableComponent implements OnInit {
  readonly HOUR_PX = HOUR_PX;

  loading  = signal(false);
  sessions = signal<Session[]>([]);
  drafts   = signal<Draft[]>([]);
  rooms    = signal<Room[]>([]);
  conflictIds = signal<Set<number>>(new Set());

  selectedDraftId: number | null = null;
  private weekStart = this.getMonday(new Date());

  hours = Array.from({ length: 12 }, (_, i) => `${(DAY_START + i).toString().padStart(2, '0')}:00`);

  blockTypes = [
    { key: 'theory',      label: 'Teoria',      color: BLOCK_COLORS['theory'] },
    { key: 'workshop',    label: 'Warsztat',     color: BLOCK_COLORS['workshop'] },
    { key: 'lab',         label: 'Lab',          color: BLOCK_COLORS['lab'] },
    { key: 'code_review', label: 'Code Review',  color: BLOCK_COLORS['code_review'] },
    { key: 'project',     label: 'Projekt',      color: BLOCK_COLORS['project'] },
  ];

  weekDays = computed(() => {
    return Array.from({ length: 7 }, (_, i) => {
      const d = new Date(this.weekStart);
      d.setDate(d.getDate() + i);
      const today = new Date();
      return {
        iso: d.toISOString().slice(0, 10),
        name: d.toLocaleDateString('pl-PL', { weekday: 'short' }),
        date: d.getDate().toString(),
        isToday: d.toDateString() === today.toDateString(),
      };
    });
  });

  weekLabel = computed(() => {
    const end = new Date(this.weekStart);
    end.setDate(end.getDate() + 6);
    const from = this.weekStart.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' });
    const to   = end.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short', year: 'numeric' });
    return `${from} – ${to}`;
  });

  constructor(
    private planner: PlannerService,
    private dialog: MatDialog,
    private snack: MatSnackBar,
  ) {}

  ngOnInit(): void {
    this.planner.getDrafts().subscribe(d => this.drafts.set(d));
    this.planner.getRooms().subscribe(r => this.rooms.set(r));
    this.loadSessions();
  }

  loadSessions(): void {
    this.loading.set(true);
    const from = this.weekStart.toISOString().slice(0, 10);
    const end  = new Date(this.weekStart); end.setDate(end.getDate() + 6);
    const to   = end.toISOString().slice(0, 10);
    this.planner.getSessionsForWeek(from, to, this.selectedDraftId).subscribe({
      next: s => { this.sessions.set(s); this.loading.set(false); },
      error: () => { this.loading.set(false); },
    });
  }

  prevWeek(): void { this.weekStart.setDate(this.weekStart.getDate() - 7); this.loadSessions(); }
  nextWeek(): void { this.weekStart.setDate(this.weekStart.getDate() + 7); this.loadSessions(); }
  goToday():  void { this.weekStart = this.getMonday(new Date()); this.loadSessions(); }

  sessionsFor(iso: string): Session[] {
    return this.sessions().filter(s => s.lesson_date === iso);
  }

  timeToY(time: string): number {
    const [h, m] = time.split(':').map(Number);
    return ((h - DAY_START) + m / 60) * HOUR_PX;
  }

  durationToH(from: string, to: string): number {
    const [fh, fm] = from.split(':').map(Number);
    const [th, tm] = to.split(':').map(Number);
    return ((th - fh) + (tm - fm) / 60) * HOUR_PX;
  }

  blockColor(bt: string): string { return BLOCK_COLORS[bt] ?? '#6B7280'; }
  blockLabel(bt: string): string { return this.blockTypes.find(b => b.key === bt)?.label ?? bt; }
  modeIcon(mode: string): string { return mode === 'remote' ? 'videocam' : mode === 'hybrid' ? 'devices' : 'location_on'; }
  hasConflict(s: Session): boolean { return this.conflictIds().has(s.id); }
  sessionTooltip(s: Session): string {
    const room = this.rooms().find(r => r.id === s.room_id);
    return [s.topic, room ? `Sala: ${room.name}` : '', s.notes].filter(Boolean).join('\n');
  }

  onDayClick(event: MouseEvent, iso: string): void {
    const el = event.currentTarget as HTMLElement;
    const y   = event.offsetY;
    const min = Math.round((y / HOUR_PX) * 60 / 15) * 15; // snap to 15 min
    const h   = DAY_START + Math.floor(min / 60);
    const m   = min % 60;
    const from = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
    const toH  = h + 1;
    const to   = `${String(toH).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
    this.openSessionDialog(undefined, { lesson_date: iso, time_from: from, time_to: to });
  }

  onDrop(event: CdkDragDrop<Session[]>, targetDay: string): void {
    const s = event.item.data as Session;
    if (!s) return;
    if (event.previousContainer !== event.container) {
      // Zmiana dnia — zaktualizuj datę sesji
      const updated = { ...s, lesson_date: targetDay };
      this.planner.checkConflicts(updated).subscribe(cr => {
        if (cr.hard.length) {
          this.snack.open(`Konflikt: ${cr.hard[0].msg}`, 'OK', { duration: 4000, panelClass: 'snack-error' });
          return;
        }
        this.planner.updateSession(s.id, { lesson_date: targetDay }).subscribe({
          next: updated => {
            this.sessions.update(all => all.map(x => x.id === updated.id ? updated : x));
            if (cr.soft.length) this.snack.open(`Ostrzeżenie: ${cr.soft[0].msg}`, '', { duration: 3000 });
          },
          error: e => this.snack.open(`Błąd: ${e.message}`, 'OK', { duration: 3000 }),
        });
      });
    }
  }

  openSessionDialog(session?: Session, prefill?: Partial<Session>): void {
    const data: SessionDialogData = {
      session: session ?? null,
      prefill: prefill ?? {},
      rooms: this.rooms(),
      drafts: this.drafts(),
    };
    const ref = this.dialog.open(SessionDialogComponent, {
      width: '560px',
      data,
      panelClass: 'planner-dialog',
    });
    ref.afterClosed().subscribe((result: Session | null) => {
      if (!result) return;
      this.sessions.update(all => {
        const idx = all.findIndex(s => s.id === result.id);
        return idx >= 0 ? all.map(s => s.id === result.id ? result : s) : [...all, result];
      });
    });
  }

  private getMonday(d: Date): Date {
    const day = d.getDay();
    const diff = d.getDate() - day + (day === 0 ? -6 : 1);
    return new Date(d.setDate(diff));
  }
}
