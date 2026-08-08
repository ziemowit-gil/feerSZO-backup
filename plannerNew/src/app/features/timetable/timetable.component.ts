import { Component, OnInit, AfterViewInit, signal, computed, ElementRef, ViewChild } from '@angular/core';
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
import { PlannerContextService } from '../../core/services/planner-context.service';
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
        <div class="week-nav">
          <button mat-icon-button class="nav-btn" (click)="prevWeek()" matTooltip="Poprzedni tydzień">
            <mat-icon>chevron_left</mat-icon>
          </button>
          <span class="week-label">{{ weekLabel() }}</span>
          <button mat-icon-button class="nav-btn" (click)="nextWeek()" matTooltip="Następny tydzień">
            <mat-icon>chevron_right</mat-icon>
          </button>
          <button mat-stroked-button class="today-btn" (click)="goToday()">Dziś</button>
        </div>

        <div class="spacer"></div>

        <mat-select [(ngModel)]="selectedDraftId" (ngModelChange)="loadSessions()"
                    placeholder="Plan (opublikowany)" class="draft-select">
          <mat-option [value]="null">
            <mat-icon style="font-size:16px;vertical-align:middle;margin-right:4px">public</mat-icon>
            Opublikowany plan
          </mat-option>
          @for (d of drafts(); track d.id) {
            <mat-option [value]="d.id">[{{ d.status }}] {{ d.title }}</mat-option>
          }
        </mat-select>

        <button mat-flat-button color="primary" (click)="openSessionDialog()" class="add-btn">
          <mat-icon>add</mat-icon> Nowa sesja
        </button>
      </div>

      <!-- Legend -->
      <div class="tt-legend">
        @for (bt of blockTypes; track bt.key) {
          <span class="legend-chip" [style.--c]="bt.color">
            <span class="legend-dot"></span>{{ bt.label }}
          </span>
        }
        <span class="legend-sep">|</span>
        <span class="sessions-count">{{ sessions().length }} sesji w tym tygodniu</span>
      </div>

      @if (loading()) {
        <div class="loading-wrap"><mat-spinner [diameter]="36"/></div>
      } @else {
        <!-- Calendar grid -->
        <div class="tt-grid-wrap" #gridWrap>
          <div class="tt-grid" cdkDropListGroup>
            <!-- Time axis -->
            <div class="time-col">
              <div class="day-header-placeholder"></div>
              @for (h of hours; track h) {
                <div class="time-slot" [style.height.px]="HOUR_PX">{{ h }}</div>
              }
            </div>

            <!-- Day columns -->
            @for (day of weekDays(); track day.iso) {
              <div class="day-col" [class.weekend]="day.isWeekend">
                <div class="day-header" [class.today]="day.isToday">
                  <span class="day-name">{{ day.name }}</span>
                  <span class="day-date" [class.today-num]="day.isToday">{{ day.date }}</span>
                  @if (day.isToday) { <span class="today-dot"></span> }
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
                         [style.--bc]="blockColor(s.block_type)"
                         [class.conflict]="hasConflict(s)"
                         [class.draft]="s.draft_id !== null"
                         [matTooltip]="sessionTooltip(s)"
                         matTooltipPosition="right"
                         (click)="$event.stopPropagation(); openSessionDialog(s)">
                      <div class="sb-time">{{ s.time_from }}–{{ s.time_to }}</div>
                      <div class="sb-title">{{ s.topic || ('Sesja #' + s.id) }}</div>
                      <div class="sb-meta">
                        <mat-icon class="sb-icon">{{ modeIcon(s.mode) }}</mat-icon>
                        <span>{{ blockLabel(s.block_type) }}</span>
                        @if (s.draft_id) { <span class="draft-badge">SZKIC</span> }
                      </div>
                      <div cdkDragHandle class="drag-handle">
                        <mat-icon class="drag-icon">drag_indicator</mat-icon>
                      </div>
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
    .tt-page { display: flex; flex-direction: column; gap: 10px; height: calc(100vh - 112px); overflow: hidden; }

    /* ── Toolbar ── */
    .tt-toolbar {
      display: flex; align-items: center; gap: 8px; flex-wrap: nowrap;
      background: var(--sur); border: 1px solid var(--bor);
      border-radius: 10px; padding: 6px 10px;
      overflow-x: auto;
    }
    .week-nav { display: flex; align-items: center; gap: 0; flex-shrink: 0; }
    .nav-btn { color: var(--t2) !important; }
    .week-label {
      font-weight: 700; font-size: .88rem; min-width: 148px; text-align: center;
      color: var(--t1); padding: 0 2px; white-space: nowrap;
    }
    .today-btn {
      font-size: 12px !important; padding: 0 10px !important; height: 32px !important;
      margin-left: 4px; border-color: var(--bor) !important; color: var(--t2) !important;
      flex-shrink: 0;
    }
    .spacer { flex: 1; min-width: 8px; }
    .draft-select { width: 190px; font-size: 13px; flex-shrink: 0; }
    .add-btn { height: 36px !important; font-size: 13px !important; flex-shrink: 0; white-space: nowrap; }

    /* ── Legend ── */
    .tt-legend { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 0 2px; }
    .legend-chip {
      display: flex; align-items: center; gap: 5px;
      font-size: 11.5px; color: var(--t2);
    }
    .legend-dot { width: 10px; height: 10px; border-radius: 3px; background: var(--c); flex-shrink: 0; }
    .legend-sep { color: var(--bor); font-size: 16px; line-height: 1; }
    .sessions-count { font-size: 11.5px; color: var(--t3); }

    .loading-wrap { display: flex; justify-content: center; padding: 60px; }

    /* ── Grid ── */
    .tt-grid-wrap { overflow: auto; flex: 1; border: 1px solid var(--bor); border-radius: 10px; background: var(--sur); }
    .tt-grid { display: grid; grid-template-columns: 48px repeat(7, 1fr); min-width: 700px; }

    .time-col { display: flex; flex-direction: column; }
    .day-header-placeholder { height: 52px; border-bottom: 1px solid var(--bor); background: var(--sur-hi); }
    .time-slot {
      display: flex; align-items: flex-start; justify-content: flex-end;
      padding: 4px 6px 0; font-size: 10px; color: var(--t3);
      border-top: 1px solid var(--bor); box-sizing: border-box;
      user-select: none;
    }

    .day-col { display: flex; flex-direction: column; border-left: 1px solid var(--bor); }
    .day-col.weekend { background: var(--sur-hi); }
    .day-header {
      height: 52px; display: flex; flex-direction: column; align-items: center;
      justify-content: center; border-bottom: 2px solid var(--bor);
      background: var(--sur-hi); gap: 1px; position: sticky; top: 0; z-index: 5;
    }
    .day-header.today { background: rgba(79,70,229,.06); border-bottom-color: var(--acc); }
    .day-name { font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--t3); }
    .day-date { font-size: 1.1rem; font-weight: 800; color: var(--t1); line-height: 1; }
    .day-date.today-num {
      background: var(--acc); color: #fff;
      width: 28px; height: 28px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: .9rem;
    }
    .today-dot { display: none; }

    .day-body {
      position: relative;
      background: repeating-linear-gradient(
        to bottom,
        transparent, transparent calc(var(--hpx, 64px) - 1px),
        var(--bor) calc(var(--hpx, 64px) - 1px),
        var(--bor) var(--hpx, 64px)
      );
      cursor: pointer;
    }
    .weekend .day-body { background-color: rgba(0,0,0,.018); }

    /* ── Session block ── */
    .session-block {
      position: absolute; left: 4px; right: 4px;
      border-radius: 6px; padding: 5px 7px 5px 9px;
      font-size: 11px; color: #fff;
      background: var(--bc);
      border-left: 3px solid rgba(255,255,255,.4);
      box-shadow: 0 1px 3px rgba(0,0,0,.25);
      overflow: hidden; cursor: pointer; user-select: none;
      transition: box-shadow .12s, transform .12s;
    }
    .session-block:hover { box-shadow: 0 3px 12px rgba(0,0,0,.3); transform: translateY(-1px); }
    .session-block.conflict { outline: 2px solid #F87171; }
    .session-block.draft { opacity: .78; border-left-style: dashed; }
    .sb-time  { font-size: 9.5px; opacity: .82; font-weight: 500; letter-spacing: .02em; }
    .sb-title { font-weight: 700; line-height: 1.25; margin: 2px 0 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sb-meta  { font-size: 10px; opacity: .82; display: flex; gap: 3px; align-items: center; }
    .sb-icon  { font-size: 11px !important; width: 11px !important; height: 11px !important; }
    .draft-badge { background: rgba(255,255,255,.25); padding: 0 4px; border-radius: 3px; font-size: 9px; font-weight: 700; }
    .drag-handle { position: absolute; right: 3px; top: 3px; cursor: grab; opacity: 0; transition: opacity .12s; }
    .session-block:hover .drag-handle { opacity: .7; }
    .drag-icon { font-size: 14px !important; width: 14px !important; height: 14px !important; }
    .drag-handle:active { cursor: grabbing; }
    .cdk-drag-preview { border-radius: 6px; box-shadow: 0 6px 24px rgba(0,0,0,.4); opacity: .92; z-index: 9999; }
    .cdk-drag-placeholder { opacity: 0; }
  `],
})
export class TimetableComponent implements OnInit, AfterViewInit {
  @ViewChild('gridWrap') gridWrap?: ElementRef<HTMLDivElement>;
  readonly HOUR_PX = HOUR_PX;

  loading  = signal(false);
  sessions = signal<Session[]>([]);
  drafts   = signal<Draft[]>([]);
  rooms    = signal<Room[]>([]);
  conflictIds = signal<Set<number>>(new Set());

  selectedDraftId: number | null = null;
  private weekStart = signal(this.getMonday(new Date()));

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
      const d = new Date(this.weekStart());
      d.setDate(d.getDate() + i);
      const today = new Date();
      const dow = d.getDay(); // 0=Sun, 6=Sat
      return {
        iso: d.toISOString().slice(0, 10),
        name: d.toLocaleDateString('pl-PL', { weekday: 'short' }),
        date: d.getDate().toString(),
        isToday: d.toDateString() === today.toDateString(),
        isWeekend: dow === 0 || dow === 6,
      };
    });
  });

  weekLabel = computed(() => {
    const end = new Date(this.weekStart());
    end.setDate(end.getDate() + 6);
    const from = this.weekStart().toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' });
    const to   = end.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short', year: 'numeric' });
    return `${from} – ${to}`;
  });

  constructor(
    private planner: PlannerService,
    private dialog: MatDialog,
    private snack: MatSnackBar,
    readonly ctx: PlannerContextService,
  ) {}

  ngOnInit(): void {
    this.planner.getDrafts().subscribe(d => this.drafts.set(d));
    this.planner.getRooms().subscribe(r => this.rooms.set(r));
    this.loadSessions();
  }

  ngAfterViewInit(): void { this.scrollToNow(); }

  scrollToNow(): void {
    setTimeout(() => {
      const el = this.gridWrap?.nativeElement;
      if (!el) return;
      const now = new Date();
      const targetH = now.getHours() - DAY_START - 1; // 1h before current time
      el.scrollTop = Math.max(0, targetH * HOUR_PX);
    }, 80);
  }

  loadSessions(): void {
    this.loading.set(true);
    const from = this.weekStart().toISOString().slice(0, 10);
    const end  = new Date(this.weekStart()); end.setDate(end.getDate() + 6);
    const to   = end.toISOString().slice(0, 10);
    this.planner.getSessionsForWeek(from, to, this.selectedDraftId, this.ctx.courseId()).subscribe({
      next: s => { this.sessions.set(s); this.loading.set(false); },
      error: () => { this.loading.set(false); },
    });
  }

  prevWeek(): void { const d = new Date(this.weekStart()); d.setDate(d.getDate() - 7); this.weekStart.set(d); this.loadSessions(); }
  nextWeek(): void { const d = new Date(this.weekStart()); d.setDate(d.getDate() + 7); this.weekStart.set(d); this.loadSessions(); }
  goToday():  void { this.weekStart.set(this.getMonday(new Date())); this.loadSessions(); }

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
