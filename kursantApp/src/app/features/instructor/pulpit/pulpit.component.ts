import { Component, signal, computed, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { ChartConfiguration } from 'chart.js';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';
import { InstructorDashboard, InstructorAttendanceTrendPoint } from '../../../core/models/kursant.models';
import { StatusLabelPipe } from '../../../shared/pipes/status-label.pipe';
import { ChartCanvasComponent } from '../../../shared/components/chart-canvas.component';

const MONTHS_PL_SHORT = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];

@Component({
  selector: 'app-instructor-pulpit',
  standalone: true,
  imports: [CommonModule, DatePipe, StatusLabelPipe, ChartCanvasComponent],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie pulpitu… }</div>

    <div class="page-header">
      <h1>Pulpit</h1>
      <p class="subtitle">Dzisiejsze i najbliższe zajęcia, do zrobienia</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie pulpitu">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (data(); as d) {
      <div class="summary-grid">
        <div class="k-card stat-card">
          <p class="stat-label">Grupy</p>
          <p class="stat-value">{{ d.courses_count }}</p>
        </div>
        <div class="k-card stat-card">
          <p class="stat-label">Dziś</p>
          <p class="stat-value">{{ d.today.length }}</p>
        </div>
        <div class="k-card stat-card">
          <p class="stat-label">Najbliższe 7 dni</p>
          <p class="stat-value">{{ d.upcoming.length }}</p>
        </div>
        <div class="k-card stat-card">
          <p class="stat-label">Prośby o odwołanie</p>
          <p class="stat-value" [class.warn]="d.pending_cancel > 0">{{ d.pending_cancel }}</p>
        </div>
      </div>

      @if (d.notices_unread > 0 || d.msg_unread_total > 0) {
        <div class="k-alert info">
          <span class="material-symbols-outlined" aria-hidden="true">info</span>
          <span>
            @if (d.notices_unread > 0) { {{ d.notices_unread }} nieprzeczytanych komunikatów. }
            @if (d.msg_unread_total > 0) { {{ d.msg_unread_total }} nieprzeczytanych wiadomości. }
          </span>
        </div>
      }

      <section class="k-card" aria-labelledby="today-heading">
        <h2 class="k-card-title" id="today-heading">
          <span class="material-symbols-outlined" aria-hidden="true">today</span>
          Dziś
        </h2>
        @if (d.today.length === 0) {
          <p class="text-muted text-sm mb-0">Brak zaplanowanych zajęć na dziś.</p>
        } @else {
          <div class="k-table-wrap">
            <table class="k-table" aria-label="Dzisiejsze zajęcia">
              <thead><tr><th scope="col">Godziny</th><th scope="col">Grupa</th><th scope="col">Temat</th><th scope="col">Status</th></tr></thead>
              <tbody>
                @for (s of d.today; track s.id) {
                  <tr>
                    <td class="text-nowrap">{{ s.time_from | slice:0:5 }}@if (s.time_to) {–{{ s.time_to | slice:0:5 }}}</td>
                    <td>{{ s.course_name }}</td>
                    <td>{{ s.topic || '—' }}</td>
                    <td><span class="status-badge">{{ s.status | statusLabel }}</span></td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      </section>

      <section class="k-card" aria-labelledby="upcoming-heading">
        <h2 class="k-card-title" id="upcoming-heading">
          <span class="material-symbols-outlined" aria-hidden="true">event_upcoming</span>
          Najbliższe 7 dni
        </h2>
        @if (d.upcoming.length === 0) {
          <p class="text-muted text-sm mb-0">Brak zajęć w najbliższych 7 dniach.</p>
        } @else {
          <div class="k-table-wrap">
            <table class="k-table" aria-label="Zajęcia w najbliższych 7 dniach">
              <thead><tr><th scope="col">Data</th><th scope="col">Godziny</th><th scope="col">Grupa</th><th scope="col">Temat</th></tr></thead>
              <tbody>
                @for (s of d.upcoming; track s.id) {
                  <tr>
                    <td class="text-nowrap">{{ s.lesson_date | date:'d.MM.yyyy' }}</td>
                    <td class="text-nowrap">{{ s.time_from | slice:0:5 }}@if (s.time_to) {–{{ s.time_to | slice:0:5 }}}</td>
                    <td>{{ s.course_name }}</td>
                    <td>{{ s.topic || '—' }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      </section>

      @if (d.attendance_month.length > 0) {
        <section class="k-card" aria-labelledby="attendance-heading">
          <h2 class="k-card-title" id="attendance-heading">
            <span class="material-symbols-outlined" aria-hidden="true">query_stats</span>
            Frekwencja — bieżący miesiąc
          </h2>
          <div class="chart-wrap">
            <app-chart-canvas [config]="attendanceMonthChart()!" ariaLabel="Obecni i nieobecni w bieżącym miesiącu, per grupa" />
          </div>
          <div class="k-table-wrap">
            <table class="k-table" aria-label="Frekwencja w bieżącym miesiącu per grupa">
              <thead><tr><th scope="col">Grupa</th><th scope="col">Zajęć</th><th scope="col">Obecni</th><th scope="col">Nieobecni</th></tr></thead>
              <tbody>
                @for (r of d.attendance_month; track r.course_id) {
                  <tr>
                    <td>{{ r.course_name }}</td>
                    <td>{{ r.lessons }}</td>
                    <td>{{ r.present }}</td>
                    <td [class.warn]="r.absent > 0">{{ r.absent }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        </section>
      }

      <section class="k-card" aria-labelledby="trend-heading">
        <h2 class="k-card-title" id="trend-heading">
          <span class="material-symbols-outlined" aria-hidden="true">show_chart</span>
          Trend frekwencji — ostatnie 6 miesięcy
        </h2>
        @if (trendLoading()) {
          <p class="text-muted text-sm mb-0">Ładowanie…</p>
        } @else {
          @if (trendChart(); as cfg) {
            <div class="chart-wrap">
              <app-chart-canvas [config]="cfg" ariaLabel="Frekwencja procentowa w ostatnich 6 miesiącach" />
            </div>
          } @else {
            <p class="text-muted text-sm mb-0">Brak danych o frekwencji z ostatnich miesięcy.</p>
          }
        }
      </section>
    }
  `,
  styles: [`
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
    .stat-card { text-align: center; padding: 1.25rem; margin-bottom: 0; }
    .stat-label { font-size: .8rem; text-transform: uppercase; letter-spacing: .07em; color: #6b7280; margin: 0 0 .5rem; }
    .stat-value { font-size: 1.75rem; font-weight: 700; margin: 0; &.warn { color: #b91c1c; } }
    .warn { color: #b91c1c; font-weight: 600; }
    .chart-wrap { position: relative; height: 220px; margin-bottom: 1rem; }
  `],
})
export class InstructorPulpitComponent implements OnInit {
  private api = inject(InstructorApiService);
  private courseCtx = inject(InstructorCourseContextService);

  loading = signal(true);
  data    = signal<InstructorDashboard | null>(null);

  trendLoading = signal(true);
  trendData    = signal<InstructorAttendanceTrendPoint[]>([]);

  attendanceMonthChart = computed<ChartConfiguration | null>(() => {
    const rows = this.data()?.attendance_month ?? [];
    if (rows.length === 0) return null;
    return {
      type: 'bar',
      data: {
        labels: rows.map(r => r.course_name),
        datasets: [
          { label: 'Obecni', data: rows.map(r => r.present), backgroundColor: '#15803d' },
          { label: 'Nieobecni', data: rows.map(r => r.absent), backgroundColor: '#b91c1c' },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
        plugins: { legend: { position: 'bottom' } },
      },
    };
  });

  trendChart = computed<ChartConfiguration | null>(() => {
    const rows = this.trendData();
    if (rows.length === 0) return null;
    return {
      type: 'line',
      data: {
        labels: rows.map(r => this.monthLabel(r.year_month)),
        datasets: [{
          label: 'Frekwencja %', data: rows.map(r => r.pct),
          borderColor: '#4f46e5', backgroundColor: 'rgba(79,70,229,.15)', fill: true, tension: .3, spanGaps: true,
        }],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } } },
        plugins: { legend: { display: false } },
      },
    };
  });

  private monthLabel(ym: string): string {
    const [, m] = ym.split('-').map(Number);
    return MONTHS_PL_SHORT[m - 1] ?? ym;
  }

  ngOnInit(): void {
    this.api.getDashboard().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.data.set(res.data);
      },
      error: () => this.loading.set(false),
    });
    this.trendLoading.set(true);
    this.api.getAttendanceTrend(6, this.courseCtx.selectedId()).subscribe({
      next: res => {
        this.trendLoading.set(false);
        if (res.success && res.data) this.trendData.set(res.data);
      },
      error: () => this.trendLoading.set(false),
    });
  }
}
