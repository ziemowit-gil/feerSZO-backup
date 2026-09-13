import { Component, Input, Output, EventEmitter, computed, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';

export interface CalendarChip {
  label: string;
  colorClass?: string;
}

const WEEKDAY_LABELS = ['Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'So', 'Nd'];
const MONTHS_PL = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

/**
 * Widok kalendarza miesięcznego — generyczny komponent, żeby nie budować
 * osobnej siatki dla Lekcji i Zajętości Zoom osobno. Dni tygodnia Pn–Nd,
 * dni spoza miesiąca wyszarzone. `days` to mapa "YYYY-MM-DD" → chipy do
 * pokazania w tym dniu (etykieta + klasa koloru) — treść/znaczenie chipów
 * ustala wywołujący; kliknięcie chipa emituje (date, index w tablicy dnia).
 */
@Component({
  selector: 'app-month-calendar',
  standalone: true,
  imports: [CommonModule, MatButtonModule],
  template: `
    <div class="cal-nav">
      <button mat-icon-button type="button" (click)="shift(-1)" aria-label="Poprzedni miesiąc">
        <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
      </button>
      <span class="cal-month-label">{{ monthLabel() }}</span>
      <button mat-icon-button type="button" (click)="shift(1)" aria-label="Następny miesiąc">
        <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
      </button>
      <button mat-stroked-button type="button" class="btn-small" (click)="goToday()">Dziś</button>
    </div>

    <div class="cal-grid">
      @for (w of weekdayLabels; track w) { <div class="cal-weekday">{{ w }}</div> }
      @for (cell of cells(); track cell.date) {
        <div class="cal-cell" [class.cal-other-month]="!cell.inMonth" [class.cal-today]="cell.isToday">
          <span class="cal-daynum">{{ cell.dayNum }}</span>
          <div class="cal-chips">
            @for (chip of (eventsByDay[cell.date] ?? []); track $index; let i = $index) {
              <button type="button" class="cal-chip" [ngClass]="chip.colorClass" (click)="onChipClick(cell.date, i)">{{ chip.label }}</button>
            }
          </div>
        </div>
      }
    </div>
  `,
  styles: [`
    .cal-nav { display: flex; align-items: center; gap: .5rem; margin-bottom: .85rem; }
    .cal-month-label { font-weight: 600; min-width: 9rem; text-align: center; text-transform: capitalize; }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; }
    .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: var(--c-border); border: 1px solid var(--c-border); border-radius: .5rem; overflow: hidden; }
    .cal-weekday { background: var(--c-surface-2); text-align: center; font-size: .75rem; font-weight: 600; color: var(--c-text-muted); padding: .4rem 0; }
    .cal-cell { background: var(--c-surface, #fff); min-height: 84px; padding: .3rem; display: flex; flex-direction: column; gap: .2rem; }
    .cal-cell.cal-other-month { background: var(--c-surface-2); opacity: .5; }
    .cal-cell.cal-today { box-shadow: inset 0 0 0 2px var(--c-primary, #4f46e5); }
    .cal-daynum { font-size: .75rem; color: var(--c-text-muted); }
    .cal-chips { display: flex; flex-direction: column; gap: 2px; overflow: hidden; }
    .cal-chip {
      border: none; border-radius: .3rem; padding: .1rem .3rem; font-size: .68rem; text-align: left; cursor: pointer;
      background: var(--c-surface-2); color: var(--c-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      &:hover { filter: brightness(0.95); }
      &.success { background: #dcfce7; color: #15803d; }
      &.danger { background: #fee2e2; color: #b91c1c; }
      &.warn { background: #fef3c7; color: #92400e; }
      &.info { background: #dbeafe; color: #1e40af; }
    }
    @media (max-width: 640px) { .cal-daynum { font-size: .68rem; } .cal-cell { min-height: 60px; } .cal-chip { font-size: .62rem; } }
  `],
})
export class MonthCalendarComponent {
  @Input() eventsByDay: Record<string, CalendarChip[]> = {};
  @Output() chipClick = new EventEmitter<{ date: string; index: number }>();
  @Output() monthChange = new EventEmitter<string>();

  readonly weekdayLabels = WEEKDAY_LABELS;

  month = signal(new Date().toISOString().slice(0, 7));
  private today = new Date().toISOString().slice(0, 10);

  @Input() set initialMonth(m: string | undefined) {
    if (m) this.month.set(m);
  }

  monthLabel = computed(() => {
    const [y, m] = this.month().split('-').map(Number);
    return `${MONTHS_PL[m - 1]} ${y}`;
  });

  cells = computed(() => {
    const [y, m] = this.month().split('-').map(Number);
    const first = new Date(y, m - 1, 1);
    const startOffset = (first.getDay() + 6) % 7; // Poniedziałek = 0
    const gridStart = new Date(y, m - 1, 1 - startOffset);
    const out: { date: string; dayNum: number; inMonth: boolean; isToday: boolean }[] = [];
    for (let i = 0; i < 42; i++) {
      const d = new Date(gridStart);
      d.setDate(gridStart.getDate() + i);
      const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      out.push({ date: iso, dayNum: d.getDate(), inMonth: d.getMonth() === m - 1, isToday: iso === this.today });
    }
    return out;
  });

  shift(delta: number): void {
    const [y, m] = this.month().split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    const next = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    this.month.set(next);
    this.monthChange.emit(next);
  }

  goToday(): void {
    const next = new Date().toISOString().slice(0, 7);
    this.month.set(next);
    this.monthChange.emit(next);
  }

  onChipClick(date: string, index: number): void {
    this.chipClick.emit({ date, index });
  }
}
