import { Component, signal, computed, inject, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ChartConfiguration } from 'chart.js';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { KursantStats } from '../../core/models/kursant.models';
import { ChartCanvasComponent } from '../../shared/components/chart-canvas.component';

const MONTHS_SHORT = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];
const MONTHS_PL    = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
const PALETTE      = ['#2563eb', '#16a34a', '#d97706', '#9333ea', '#dc2626', '#0891b2'];

/**
 * Statystyki kursanta — widok podsumowujący za ostatnie 12 miesięcy:
 * godziny rozliczone (każda rozpoczęta godzina = pełna, jak w rozliczeniu),
 * frekwencja, nieobecności i odwołania; wykres godzin per miesiąc (w podziale
 * na grupy) i trend frekwencji + tabela miesiąc × grupa. Bez kwot — widok
 * dostępny także dla małoletnich (API action=stats).
 */
@Component({
  selector: 'app-statystyki',
  standalone: true,
  imports: [CommonModule, ChartCanvasComponent],
  template: `
    <div class="page-header">
      <h1>Statystyki</h1>
      <p class="subtitle">Twoje zajęcia w ostatnich 12 miesiącach</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status"><span>Ładowanie…</span></div>
    }
    @if (!loading() && stats(); as s) {
      <div class="stats-row" role="list">
        <div class="stat" role="listitem"><span class="stat-num">{{ s.totals.lessons }}</span><span class="stat-lbl">lekcje odbyte</span></div>
        <div class="stat" role="listitem"><span class="stat-num">{{ s.totals.hours }} h</span><span class="stat-lbl">godziny rozliczone</span></div>
        <div class="stat" role="listitem">
          <span class="stat-num">{{ s.totals.attendance_pct !== null ? s.totals.attendance_pct + '%' : '—' }}</span><span class="stat-lbl">frekwencja</span>
        </div>
        <div class="stat" role="listitem"><span class="stat-num">{{ s.totals.absent + s.totals.no_show }}</span><span class="stat-lbl">nieobecności</span></div>
        <div class="stat" role="listitem"><span class="stat-num">{{ s.totals.cancelled }}</span><span class="stat-lbl">odwołane</span></div>
      </div>
      <p class="text-muted text-sm note">Godziny: każda rozpoczęta godzina lekcji liczona jako pełna (45 min = 1 h, 90 min = 2 h) — tak jak w rozliczeniu.</p>

      @if (s.rows.length === 0) {
        <div class="k-card"><div class="empty-state"><p>Brak zajęć w tym okresie.</p></div></div>
      } @else {
        <div class="charts">
          <div class="k-card chart-card">
            <h2 class="card-title">Godziny rozliczone per miesiąc</h2>
            <app-chart-canvas [config]="hoursChart()" ariaLabel="Wykres słupkowy godzin rozliczonych w kolejnych miesiącach, w podziale na grupy" />
          </div>
          <div class="k-card chart-card">
            <h2 class="card-title">Frekwencja per miesiąc</h2>
            <app-chart-canvas [config]="attendanceChart()" ariaLabel="Wykres liniowy frekwencji procentowej w kolejnych miesiącach" />
          </div>
        </div>

        <div class="k-card table-card">
          <table class="k-table">
            <caption class="visually-hidden">Zajęcia w podziale na miesiące i grupy</caption>
            <thead><tr>
              <th scope="col">Miesiąc</th><th scope="col">Grupa</th>
              <th scope="col" class="r">Lekcje</th><th scope="col" class="r">Obecności</th>
              <th scope="col" class="r">Nieobecności</th><th scope="col" class="r">Odwołane</th>
              <th scope="col" class="r">Godziny</th>
            </tr></thead>
            <tbody>
              @for (r of rowsDesc(); track r.year_month + r.course_id) {
                <tr>
                  <td>{{ monthLabel(r.year_month) }}</td>
                  <td>{{ r.course_name }}</td>
                  <td class="r">{{ r.lessons }}</td>
                  <td class="r">{{ r.present }}</td>
                  <td class="r">{{ r.absent + r.no_show }}@if (r.no_show) { <span class="text-muted text-sm">({{ r.no_show }} płatne)</span> }</td>
                  <td class="r">{{ r.cancelled }}</td>
                  <td class="r fw">{{ r.hours }}</td>
                </tr>
              }
            </tbody>
            <tfoot><tr>
              <td colspan="2">Razem</td>
              <td class="r">{{ s.totals.lessons }}</td><td class="r">{{ s.totals.present }}</td>
              <td class="r">{{ s.totals.absent + s.totals.no_show }}</td><td class="r">{{ s.totals.cancelled }}</td>
              <td class="r">{{ s.totals.hours }}</td>
            </tr></tfoot>
          </table>
        </div>
      }
    }
    @if (!loading() && !stats()) {
      <div class="k-card"><div class="empty-state"><p>Nie udało się wczytać statystyk.</p></div></div>
    }
  `,
  styles: [`
    .stats-row { display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: .5rem; }
    .stat { background: var(--c-surface, #fff); border: 1px solid var(--c-border, #e5e7eb); border-radius: 10px;
      padding: .6rem .9rem; min-width: 120px; display: flex; flex-direction: column; }
    .stat-num { font-size: 1.4rem; font-weight: 700; line-height: 1.1; }
    .stat-lbl { font-size: .8rem; color: var(--c-text-muted); }
    .note { margin: 0 0 1rem; }
    .charts { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
    .chart-card { min-height: 280px; }
    .card-title { font-size: 1rem; margin: 0 0 .75rem; }
    .table-card { padding: 0; overflow-x: auto; }
    .k-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .k-table th, .k-table td { padding: .55rem .75rem; border-bottom: 1px solid var(--c-border, #e5e7eb); text-align: left; }
    .k-table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; color: var(--c-text-muted); font-weight: 600; }
    .k-table tfoot td { font-weight: 700; border-bottom: none; }
    .r { text-align: right !important; }
    .fw { font-weight: 600; }
  `],
})
export class StatystykiComponent implements OnInit {
  private api = inject(KursantApiService);

  loading = signal(true);
  stats   = signal<KursantStats | null>(null);

  /** Kolejne miesiące od początku zakresu do bieżącego (oś X wykresów). */
  private monthKeys = computed(() => {
    const s = this.stats();
    if (!s) return [] as string[];
    const out: string[] = [];
    const d = new Date(s.from + 'T00:00:00');
    const now = new Date();
    while (d.getFullYear() < now.getFullYear() || (d.getFullYear() === now.getFullYear() && d.getMonth() <= now.getMonth())) {
      out.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
      d.setMonth(d.getMonth() + 1);
    }
    return out;
  });

  rowsDesc = computed(() => [...(this.stats()?.rows ?? [])]
    .sort((a, b) => b.year_month.localeCompare(a.year_month) || a.course_name.localeCompare(b.course_name)));

  hoursChart = computed<ChartConfiguration>(() => {
    const keys = this.monthKeys();
    const rows = this.stats()?.rows ?? [];
    const courses = [...new Map(rows.map(r => [r.course_id, r.course_name])).entries()];
    return {
      type: 'bar',
      data: {
        labels: keys.map(k => this.monthShort(k)),
        datasets: courses.map(([cid, name], i) => ({
          label: name,
          data: keys.map(k => rows.filter(r => r.course_id === cid && r.year_month === k).reduce((a, r) => a + r.hours, 0)),
          backgroundColor: PALETTE[i % PALETTE.length],
          stack: 'h',
        })),
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'godziny' } } },
        plugins: { legend: { position: 'bottom' } },
      },
    };
  });

  attendanceChart = computed<ChartConfiguration>(() => {
    const keys = this.monthKeys();
    const rows = this.stats()?.rows ?? [];
    const pct = keys.map(k => {
      const m = rows.filter(r => r.year_month === k);
      const den = m.reduce((a, r) => a + r.present + r.absent + r.no_show, 0);
      return den > 0 ? Math.round(m.reduce((a, r) => a + r.present, 0) * 100 / den) : null;
    });
    return {
      type: 'line',
      data: {
        labels: keys.map(k => this.monthShort(k)),
        datasets: [{ label: 'Frekwencja %', data: pct, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.15)', fill: true, spanGaps: true, tension: .3 }],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        scales: { y: { min: 0, max: 100, ticks: { callback: v => v + '%' } } },
        plugins: { legend: { display: false } },
      },
    };
  });

  ngOnInit(): void {
    this.api.getStats(12).subscribe({
      next: res => { this.loading.set(false); if (res.success && res.data) this.stats.set(res.data); },
      error: () => this.loading.set(false),
    });
  }

  monthLabel(ym: string): string {
    const [y, m] = ym.split('-').map(Number);
    return `${MONTHS_PL[m - 1]} ${y}`;
  }

  private monthShort(ym: string): string {
    const [y, m] = ym.split('-').map(Number);
    return `${MONTHS_SHORT[m - 1]} ${String(y).slice(2)}`;
  }
}
