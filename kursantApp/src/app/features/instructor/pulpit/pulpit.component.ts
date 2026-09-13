import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorDashboard } from '../../../core/models/kursant.models';
import { StatusLabelPipe } from '../../../shared/pipes/status-label.pipe';

@Component({
  selector: 'app-instructor-pulpit',
  standalone: true,
  imports: [CommonModule, DatePipe, StatusLabelPipe],
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
    }
  `,
  styles: [`
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
    .stat-card { text-align: center; padding: 1.25rem; margin-bottom: 0; }
    .stat-label { font-size: .8rem; text-transform: uppercase; letter-spacing: .07em; color: #6b7280; margin: 0 0 .5rem; }
    .stat-value { font-size: 1.75rem; font-weight: 700; margin: 0; &.warn { color: #b91c1c; } }
    .warn { color: #b91c1c; font-weight: 600; }
  `],
})
export class InstructorPulpitComponent implements OnInit {
  private api = inject(InstructorApiService);

  loading = signal(true);
  data    = signal<InstructorDashboard | null>(null);

  ngOnInit(): void {
    this.api.getDashboard().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.data.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }
}
