import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { OnlineState } from '../../core/models/kursant.models';

@Component({
  selector: 'app-online',
  standalone: true,
  imports: [CommonModule, MatButtonModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie danych online… }</div>

    <div class="page-header">
      <h1>Szkolenia online</h1>
      <p class="subtitle">Dostęp do platform e-learningowych i spotkań online</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (state(); as s) {
      <!-- Active lesson banner -->
      @if (s.active_lesson_url) {
        <div class="k-alert success" role="status" aria-live="polite">
          <span class="material-symbols-outlined" aria-hidden="true">videocam</span>
          <div>
            <strong>Aktywna lekcja online!</strong>
            <a [href]="s.active_lesson_url"
               target="_blank" rel="noopener noreferrer"
               mat-flat-button
               style="margin-left:1rem"
               aria-label="Dołącz do bieżącej lekcji online — nowa karta">
              Dołącz teraz
            </a>
          </div>
        </div>
      }

      <div class="online-grid">
        <!-- MS 365 -->
        <section class="k-card" aria-labelledby="ms365-heading">
          <h2 class="k-card-title" id="ms365-heading">
            <span class="material-symbols-outlined" aria-hidden="true">video_call</span>
            Microsoft Teams / MS 365
          </h2>
          @if (s.ms_provisioned) {
            <div class="access-info">
              <div class="info-row">
                <span class="info-label">Login (UPN)</span>
                <code class="info-value">{{ s.ms_upn }}</code>
              </div>
              @if (s.ms_temp_password) {
                <div class="info-row">
                  <span class="info-label">Hasło tymczasowe</span>
                  <code class="info-value">{{ s.ms_temp_password }}</code>
                </div>
                <p class="k-alert warning text-sm" style="margin-top:.75rem">
                  Zmień hasło tymczasowe przy pierwszym logowaniu do Teams.
                </p>
              }
              @if (s.teams_link) {
                <a [href]="s.teams_link"
                   target="_blank" rel="noopener noreferrer"
                   mat-stroked-button
                   style="margin-top:.75rem"
                   aria-label="Dołącz do Teams — nowa karta">
                  <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
                  Otwórz Teams
                </a>
              }
            </div>
          } @else {
            <div class="k-alert info">
              <span class="material-symbols-outlined" aria-hidden="true">info</span>
              Konto MS 365 nie zostało jeszcze aktywowane. Skontaktuj się z prowadzącym.
            </div>
          }
        </section>

        <!-- Moodle -->
        <section class="k-card" aria-labelledby="moodle-heading">
          <h2 class="k-card-title" id="moodle-heading">
            <span class="material-symbols-outlined" aria-hidden="true">school</span>
            Moodle
          </h2>
          @if (s.moodle_provisioned) {
            <div class="access-info">
              <div class="info-row">
                <span class="info-label">Nazwa użytkownika</span>
                <code class="info-value">{{ s.moodle_username }}</code>
              </div>
              @if (s.moodle_url) {
                <a [href]="s.moodle_url"
                   target="_blank" rel="noopener noreferrer"
                   mat-stroked-button
                   style="margin-top:.75rem"
                   aria-label="Przejdź do Moodle — nowa karta">
                  <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
                  Otwórz Moodle
                </a>
              }
            </div>
          } @else {
            <div class="k-alert info">
              <span class="material-symbols-outlined" aria-hidden="true">info</span>
              Konto Moodle nie zostało jeszcze aktywowane.
            </div>
          }
        </section>

        <!-- Zoom -->
        @if (s.zoom_link) {
          <section class="k-card" aria-labelledby="zoom-heading">
            <h2 class="k-card-title" id="zoom-heading">
              <span class="material-symbols-outlined" aria-hidden="true">videocam</span>
              Zoom
            </h2>
            <a [href]="s.zoom_link"
               target="_blank" rel="noopener noreferrer"
               mat-stroked-button
               aria-label="Dołącz do spotkania Zoom — nowa karta">
              <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
              Link do Zoom
            </a>
          </section>
        }
      </div>
    }
  `,
  styles: [`
    .online-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      gap: 1rem;
    }

    .access-info { display: flex; flex-direction: column; gap: .5rem; }

    .info-row {
      display: flex;
      align-items: center;
      gap: .75rem;
      flex-wrap: wrap;
    }

    .info-label {
      font-size: .8rem;
      color: #6b7280;
      min-width: 130px;
      text-transform: uppercase;
      letter-spacing: .05em;
    }

    .info-value {
      background: #f8fafc;
      border: 1px solid #e5e7eb;
      padding: .25rem .625rem;
      border-radius: .35rem;
      font-size: .875rem;
      color: #111827;
      word-break: break-all;
    }
  `],
})
export class OnlineComponent implements OnInit {
  private api = inject(KursantApiService);

  loading = signal(true);
  state   = signal<OnlineState | null>(null);

  ngOnInit(): void {
    this.api.getOnline().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.state.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }
}
