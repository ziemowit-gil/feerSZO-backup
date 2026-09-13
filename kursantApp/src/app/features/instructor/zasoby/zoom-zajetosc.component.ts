import { Component, signal, computed, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorZoomBusySlot } from '../../../core/models/kursant.models';
import { MonthCalendarComponent, CalendarChip } from '../../../shared/components/month-calendar.component';

const MONTHS_PL = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

/**
 * Zajętość Zoom (prowadzący) — odpowiednik karty30/ti/dydaktyk/_tab_zoom.php:
 * pokazuje zajęte terminy Zoom w miesiącu (dlaczego pewne godziny są
 * niedostępne przy planowaniu zajęć zdalnych). Dwa widoki tej samej danej:
 * lista dni (domyślna) i kalendarz miesięczny jako alternatywa.
 */
@Component({
  selector: 'app-instructor-zoom-zajetosc',
  standalone: true,
  imports: [CommonModule, DatePipe, MatButtonModule, MonthCalendarComponent],
  template: `
    <div class="page-header">
      <h1>Zajętość Zoom</h1>
      <p class="subtitle">Zajęte terminy Zoom w miesiącu</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      @if (!enabled()) {
        <div class="k-card"><p class="mb-0 text-muted">Integracja Zoom nie jest włączona, więc terminy zajęć nie są ograniczane zajętością Zooma.</p></div>
      } @else {
        <div class="view-toggle">
          <button mat-stroked-button type="button" [class.active]="view() === 'list'" (click)="view.set('list')">
            <span class="material-symbols-outlined" aria-hidden="true">view_list</span>
            Lista dni
          </button>
          <button mat-stroked-button type="button" [class.active]="view() === 'calendar'" (click)="view.set('calendar')">
            <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span>
            Kalendarz
          </button>
        </div>

        @if (view() === 'calendar') {
          <div class="k-card">
            <app-month-calendar [initialMonth]="month()" [eventsByDay]="calendarEvents()" (monthChange)="onMonthChange($event)" />
          </div>
        } @else {
          <div class="k-card">
            <div class="month-nav">
              <button mat-icon-button type="button" (click)="shiftMonth(-1)" aria-label="Poprzedni miesiąc">
                <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
              </button>
              <span class="month-label">{{ monthLabel() }}</span>
              <button mat-icon-button type="button" (click)="shiftMonth(1)" aria-label="Następny miesiąc">
                <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
              </button>
              <button mat-stroked-button type="button" class="btn-small" (click)="goToday()">Dziś</button>
              <span class="text-muted text-sm total-badge">{{ totalSlots() }} zajętych terminów</span>
            </div>

            @if (dayEntries().length === 0) {
              <p class="text-muted mb-0">Brak zajętych terminów w tym miesiącu.</p>
            } @else {
              <ul class="day-list">
                @for (d of dayEntries(); track d.date) {
                  <li class="day-row">
                    <span class="day-date">{{ d.date | date:'EEEE, d MMMM' }}</span>
                    <ul class="slot-list">
                      @for (s of d.slots; track s.start) {
                        <li>{{ timeOf(s.start) }}–{{ timeOf(s.end) }} — {{ s.title }}</li>
                      }
                    </ul>
                  </li>
                }
              </ul>
            }
          </div>
        }
      }
    }
  `,
  styles: [`
    .view-toggle { display: flex; gap: .4rem; margin-bottom: 1rem;
      button.active { background: var(--c-surface-2); font-weight: 600; }
    }
    .month-nav { display: flex; align-items: center; gap: .5rem; margin-bottom: 1rem; flex-wrap: wrap; }
    .month-label { font-weight: 600; min-width: 10rem; text-align: center; }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; }
    .total-badge { margin-left: auto; }
    .day-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .75rem; }
    .day-row { padding: .6rem .75rem; border: 1px solid var(--c-border); border-radius: .6rem; }
    .day-date { font-weight: 600; font-size: .9rem; text-transform: capitalize; }
    .slot-list { list-style: none; margin: .4rem 0 0; padding: 0; font-size: .85rem; color: var(--c-text-muted); display: flex; flex-direction: column; gap: .15rem; }
  `],
})
export class InstructorZoomZajetoscComponent implements OnInit {
  private api = inject(InstructorApiService);

  loading = signal(true);
  enabled = signal(true);
  days    = signal<Record<string, InstructorZoomBusySlot[]>>({});
  month   = signal(new Date().toISOString().slice(0, 7));
  view    = signal<'list' | 'calendar'>('list');

  dayEntries = computed(() => {
    const d = this.days();
    return Object.keys(d).sort().map(date => ({ date, slots: d[date] }));
  });

  totalSlots = computed(() => Object.values(this.days()).reduce((sum, s) => sum + s.length, 0));

  calendarEvents = computed<Record<string, CalendarChip[]>>(() => {
    const out: Record<string, CalendarChip[]> = {};
    for (const [date, slots] of Object.entries(this.days())) {
      out[date] = slots.map(s => ({ label: `${this.timeOf(s.start)} ${s.title}` }));
    }
    return out;
  });

  monthLabel = computed(() => {
    const [y, m] = this.month().split('-').map(Number);
    return `${MONTHS_PL[m - 1]} ${y}`;
  });

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.getZoomBusy(this.month()).subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) {
          this.enabled.set(res.data.enabled);
          this.days.set(res.data.days);
        }
      },
      error: () => this.loading.set(false),
    });
  }

  shiftMonth(delta: number): void {
    const [y, m] = this.month().split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    this.month.set(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
    this.load();
  }

  goToday(): void {
    this.month.set(new Date().toISOString().slice(0, 7));
    this.load();
  }

  onMonthChange(m: string): void {
    this.month.set(m);
    this.load();
  }

  /** "YYYY-MM-DD HH:mm:ss" lub "YYYY-MM-DDTHH:mm:ss" → "HH:mm", bez parsowania Date (niespójne między przeglądarkami dla formatu ze spacją). */
  timeOf(v: string): string {
    const i = v.indexOf(' ') >= 0 ? v.indexOf(' ') : v.indexOf('T');
    return i >= 0 ? v.slice(i + 1, i + 6) : v.slice(0, 5);
  }
}
