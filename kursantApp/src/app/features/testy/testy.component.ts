import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { TestItem } from '../../core/models/kursant.models';

const TEST_STATUS_LABELS: Record<string, string> = {
  available: 'Dostępny',
  completed: 'Ukończony',
  expired:   'Wygasły',
  locked:    'Zablokowany',
};

@Component({
  selector: 'app-testy',
  standalone: true,
  imports: [CommonModule, DatePipe, MatButtonModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie testów… }</div>

    <div class="page-header">
      <h1>Testy i quizy</h1>
      <p class="subtitle">Sprawdź swoją wiedzę</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie testów">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading() && tests().length === 0) {
      <div class="k-card">
        <div class="empty-state">
          <span class="material-symbols-outlined empty-icon" aria-hidden="true">quiz</span>
          <p>Brak testów do wyświetlenia.</p>
        </div>
      </div>
    }

    <div class="tests-grid">
      @for (test of tests(); track test.id) {
        <article class="k-card test-card" [class]="'test-' + test.status">
          <div class="test-header">
            <div>
              <h2 class="test-title">{{ test.title }}</h2>
              <p class="text-muted text-sm">{{ test.course_name }}</p>
            </div>
            <span class="status-badge" [class]="test.status">
              {{ statusLabel(test.status) }}
            </span>
          </div>

          @if (test.description) {
            <p class="text-sm text-muted" style="margin:.5rem 0">{{ test.description }}</p>
          }

          <div class="test-meta">
            @if (test.time_limit_min) {
              <span class="meta-item">
                <span class="material-symbols-outlined" aria-hidden="true">timer</span>
                {{ test.time_limit_min }} min
              </span>
            }
            <span class="meta-item">
              <span class="material-symbols-outlined" aria-hidden="true">repeat</span>
              {{ test.attempts_used }}/{{ test.max_attempts }} podejść
            </span>
            @if (test.available_from) {
              <span class="meta-item">
                <span class="material-symbols-outlined" aria-hidden="true">event</span>
                od {{ test.available_from | date:'d MMM':'':\'pl\' }}
              </span>
            }
            @if (test.available_to) {
              <span class="meta-item">
                <span class="material-symbols-outlined" aria-hidden="true">event_busy</span>
                do {{ test.available_to | date:'d MMM':'':\'pl\' }}
              </span>
            }
          </div>

          @if (test.last_score !== null) {
            <div class="test-score"
                 [attr.aria-label]="'Wynik: ' + test.last_score + ' na ' + test.max_score + ' punktów'">
              <span class="score-val">{{ test.last_score }}</span>
              <span class="score-max">/{{ test.max_score }}</span>
              <span class="score-pct text-muted text-sm">
                ({{ calcPct(test.last_score, test.max_score) }}%)
              </span>
            </div>
          }

          @if (test.status === 'available' && test.attempts_used < test.max_attempts) {
            <a [href]="testUrl(test.id)"
               mat-flat-button
               class="test-start-btn"
               [attr.aria-label]="'Rozpocznij test: ' + test.title">
              <span class="material-symbols-outlined" aria-hidden="true">play_arrow</span>
              Rozpocznij test
            </a>
          }
        </article>
      }
    </div>
  `,
  styles: [`
    .tests-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      gap: 1rem;
    }

    .test-card { display: flex; flex-direction: column; gap: .75rem; }

    .test-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: .5rem;
    }

    .test-title { font-size: 1rem; font-weight: 600; margin: 0; }

    .test-meta {
      display: flex;
      flex-wrap: wrap;
      gap: .75rem;
    }

    .meta-item {
      display: flex;
      align-items: center;
      gap: .25rem;
      font-size: .8rem;
      color: #6b7280;

      .material-symbols-outlined { font-size: .95rem; }
    }

    .test-score {
      display: flex;
      align-items: baseline;
      gap: .25rem;
    }

    .score-val { font-size: 1.75rem; font-weight: 700; color: #15803d; }
    .score-max { font-size: 1.1rem; color: #6b7280; }

    .test-start-btn { margin-top: .5rem !important; }

    .test-completed { border-color: #bfdbfe; }
    .test-expired,
    .test-locked    { background: #f9fafb; }
    .test-expired .test-title,
    .test-locked .test-title { color: #374151; }
  `],
})
export class TestyComponent implements OnInit {
  private api = inject(KursantApiService);

  loading = signal(true);
  tests   = signal<TestItem[]>([]);

  ngOnInit(): void {
    this.api.getTests().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.tests.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  statusLabel(s: string): string { return TEST_STATUS_LABELS[s] ?? s; }
  calcPct(score: number, max: number): number { return max > 0 ? Math.round((score / max) * 100) : 0; }

  testUrl(id: number): string {
    return `/karty30/ti/kursant/test.php?id=${id}`;
  }
}
