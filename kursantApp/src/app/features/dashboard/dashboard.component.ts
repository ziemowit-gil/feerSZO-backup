import { Component, inject } from '@angular/core';
import { CommonModule, DatePipe, PercentPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { AppDataService } from '../../core/services/app-data.service';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule, RouterLink, DatePipe, PercentPipe, MatButtonModule, MatProgressBarModule],
  template: `
    <div aria-live="polite" aria-atomic="false" class="sr-only">
      @if (loading()) { Ładowanie danych… }
      @if (error()) { Błąd: {{ error() }} }
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie danych kursanta">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (error()) {
      <div class="k-alert danger" role="alert">
        <span class="material-symbols-outlined" aria-hidden="true">error</span>
        {{ error() }}
      </div>
    }

    @if (data(); as d) {
      <section aria-labelledby="dashboard-heading">
        <div class="page-header">
          <h1 id="dashboard-heading">
            Witaj, {{ d.client.first_name }} {{ d.client.last_name }}
          </h1>
          <p class="subtitle">Panel kursanta FEER SZO</p>
        </div>

        <!-- Summary cards row -->
        <div class="summary-grid" role="list" aria-label="Podsumowanie">

          @if (d.next_lesson) {
            <article class="k-card summary-card" role="listitem">
              <p class="summary-card-label">
                <span class="material-symbols-outlined" aria-hidden="true">event</span>
                Następna lekcja
              </p>
              <p class="summary-card-value">
                {{ d.next_lesson.date | date:'d MMM':'':\'pl\' }}
                {{ d.next_lesson.time_from }}
              </p>
              <p class="summary-card-sub">{{ d.next_lesson.course_name }}</p>
              <p class="summary-card-sub text-muted">{{ d.next_lesson.instructor_name }}</p>
              @if (d.next_lesson.meeting_url) {
                <a [href]="d.next_lesson.meeting_url"
                   target="_blank"
                   rel="noopener noreferrer"
                   mat-stroked-button
                   class="mt-2"
                   aria-label="Dołącz do lekcji online — nowa karta">
                  <span class="material-symbols-outlined" aria-hidden="true">video_call</span>
                  Dołącz online
                </a>
              }
            </article>
          }

          <article class="k-card summary-card" role="listitem">
            <p class="summary-card-label">
              <span class="material-symbols-outlined" aria-hidden="true">local_fire_department</span>
              Seria dni nauki
            </p>
            <p class="summary-card-value streak">{{ d.streak_days }}</p>
            <p class="summary-card-sub">{{ d.streak_days === 1 ? 'dzień z rzędu' : 'dni z rzędu' }}</p>
          </article>

          @if (d.terms_pending > 0) {
            <article class="k-card summary-card warning-card" role="listitem" aria-label="Oczekujące regulaminy">
              <p class="summary-card-label">
                <span class="material-symbols-outlined" aria-hidden="true">gavel</span>
                Regulaminy
              </p>
              <p class="summary-card-value">{{ d.terms_pending }}</p>
              <p class="summary-card-sub">oczekujących do akceptacji</p>
              <a routerLink="/regulaminy" mat-stroked-button class="mt-2">Przejrzyj →</a>
            </article>
          }

          <article class="k-card summary-card" role="listitem">
            <p class="summary-card-label">
              <span class="material-symbols-outlined" aria-hidden="true">mail</span>
              Wiadomości
            </p>
            <p class="summary-card-value">{{ d.msg_unread }}</p>
            <p class="summary-card-sub">nieprzeczytanych</p>
            @if (d.msg_unread > 0) {
              <a routerLink="/wiadomosci" mat-stroked-button class="mt-2">Czytaj →</a>
            }
          </article>
        </div>

        <!-- Active courses -->
        @if (d.active_courses.length > 0) {
          <section aria-labelledby="courses-heading" class="k-card">
            <h2 class="k-card-title" id="courses-heading">
              <span class="material-symbols-outlined" aria-hidden="true">school</span>
              Aktywne kursy
            </h2>

            <ul role="list" style="margin:0;padding:0;list-style:none;" class="courses-list">
              @for (course of d.active_courses; track course.id) {
                <li role="listitem" class="course-item">
                  <div class="course-info">
                    <strong class="course-name">{{ course.name }}</strong>
                    <span class="text-muted text-sm">Prowadzący: {{ course.instructor_name }}</span>
                  </div>
                  <div class="course-progress" [attr.aria-label]="'Postęp: ' + course.progress_pct + '%'">
                    <div class="progress-wrap" role="progressbar"
                         [attr.aria-valuenow]="course.progress_pct"
                         aria-valuemin="0"
                         aria-valuemax="100"
                         [attr.aria-label]="course.name + ' – postęp ' + course.progress_pct + '%'">
                      <div class="progress-fill" [style.width.%]="course.progress_pct"></div>
                    </div>
                    <span class="progress-label text-sm text-muted">
                      {{ course.completed_count }}/{{ course.lesson_count }} lekcji
                    </span>
                  </div>
                </li>
              }
            </ul>
          </section>
        }

        <!-- Calendar links -->
        <section aria-labelledby="cal-heading" class="k-card">
          <h2 class="k-card-title" id="cal-heading">
            <span class="material-symbols-outlined" aria-hidden="true">calendar_today</span>
            Kalendarz lekcji
          </h2>
          <p class="text-muted text-sm" style="margin:0 0 1rem;">
            Subskrybuj swój plan lekcji w wybranej aplikacji kalendarza.
          </p>
          <div class="cal-links">
            <a [href]="d.cal_ical" class="cal-link">
              <span class="material-symbols-outlined" aria-hidden="true">download</span>
              iCal / Apple Calendar
            </a>
            <a [href]="d.cal_gcal" target="_blank" rel="noopener noreferrer" class="cal-link">
              <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
              Google Calendar
            </a>
          </div>
        </section>
      </section>
    }
  `,
  styles: [`
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 1rem;
      margin-bottom: 1.25rem;
    }

    .summary-card {
      display: flex;
      flex-direction: column;
      gap: .25rem;
      padding: 1.25rem;
    }

    .warning-card { border-color: rgba(234,179,8,.3); }

    .summary-card-label {
      display: flex;
      align-items: center;
      gap: .35rem;
      font-size: .8rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #6b7280;
      margin: 0;

      .material-symbols-outlined { font-size: 1rem; }
    }

    .summary-card-value {
      font-size: 2rem;
      font-weight: 700;
      margin: .25rem 0 0;
      color: #111827;

      &.streak { color: #2563eb; }
    }

    .summary-card-sub { margin: 0; font-size: .85rem; color: #6b7280; }

    .mt-2 { margin-top: .75rem; }

    .courses-list { display: flex; flex-direction: column; gap: 1rem; }

    .course-item {
      display: flex;
      flex-direction: column;
      gap: .5rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid #e5e7eb;

      &:last-child { border-bottom: none; padding-bottom: 0; }
    }

    .course-info { display: flex; flex-direction: column; gap: .2rem; }
    .course-name { font-size: .95rem; }

    .course-progress { display: flex; flex-direction: column; gap: .35rem; }
    .progress-label  { text-align: right; }

    .cal-links {
      display: flex;
      flex-wrap: wrap;
      gap: .75rem;
    }

    .cal-link {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      padding: .5rem 1rem;
      border: 1px solid #d1d5db;
      border-radius: .5rem;
      color: #374151;
      text-decoration: none;
      font-size: .875rem;
      transition: border-color .15s, color .15s;

      .material-symbols-outlined { font-size: 1rem; }

      &:hover, &:focus-visible { border-color: #2563eb; color: #2563eb; }
    }
  `],
})
export class DashboardComponent {
  private appData = inject(AppDataService);

  // Dane pobiera raz ShellComponent.ngOnInit (patrz AppDataService) — ten
  // widok tylko czyta współdzielony magazyn, więc powrót na tę zakładkę po
  // wcześniejszym wejściu jest natychmiastowy (bez ponownego zapytania).
  loading = this.appData.loading;
  error   = this.appData.error;
  data    = this.appData.dashboard;
}
