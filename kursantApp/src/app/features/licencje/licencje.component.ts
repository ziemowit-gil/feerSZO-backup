import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { License } from '../../core/models/kursant.models';

@Component({
  selector: 'app-licencje',
  standalone: true,
  imports: [CommonModule, DatePipe],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie licencji… }</div>

    <div class="page-header">
      <h1>Licencje</h1>
      <p class="subtitle">Oprogramowanie przypisane do Twojego konta</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading() && licenses().length === 0) {
      <div class="k-card">
        <div class="empty-state">
          <span class="material-symbols-outlined empty-icon" aria-hidden="true">key</span>
          <p>Brak przypisanych licencji.</p>
        </div>
      </div>
    }

    @if (!loading() && licenses().length > 0) {
      <ul role="list" style="list-style:none;padding:0;margin:0;">
        @for (lic of licenses(); track lic.id) {
          <li role="listitem">
            <article class="k-card lic-card">
              <div class="lic-header">
                <div>
                  <h2 class="lic-name">{{ lic.software_name }}</h2>
                  <p class="text-muted text-sm">
                    Przypisano: {{ lic.assigned_at | date:'d MMM yyyy':'':\'pl\' }}
                    @if (lic.expires_at) {
                      · Ważna do: {{ lic.expires_at | date:'d MMM yyyy':'':\'pl\' }}
                    }
                  </p>
                </div>
                @if (lic.download_url) {
                  <a [href]="lic.download_url"
                     target="_blank" rel="noopener noreferrer"
                     class="download-link"
                     [attr.aria-label]="'Pobierz ' + lic.software_name + ' — nowa karta'">
                    <span class="material-symbols-outlined" aria-hidden="true">download</span>
                    Pobierz
                  </a>
                }
              </div>

              @if (lic.license_key) {
                <div class="lic-key-row">
                  <span class="meta-label">Klucz licencji</span>
                  <div class="key-wrap">
                    <code class="lic-key" [attr.aria-label]="'Klucz: ' + lic.license_key">
                      {{ lic.license_key }}
                    </code>
                    <button class="copy-btn"
                            type="button"
                            [attr.aria-label]="'Kopiuj klucz licencji dla ' + lic.software_name"
                            (click)="copyKey(lic.license_key)">
                      <span class="material-symbols-outlined" aria-hidden="true">content_copy</span>
                    </button>
                  </div>
                </div>
              }

              @if (lic.notes) {
                <p class="lic-notes text-sm text-muted">{{ lic.notes }}</p>
              }
            </article>
          </li>
        }
      </ul>
    }
  `,
  styles: [`
    .lic-card { display: flex; flex-direction: column; gap: .75rem; }

    .lic-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: .75rem;
    }

    .lic-name { font-size: 1rem; font-weight: 600; margin: 0 0 .2rem; }

    .download-link {
      display: inline-flex;
      align-items: center;
      gap: .3rem;
      color: #93c5fd;
      text-decoration: none;
      font-size: .875rem;
      flex-shrink: 0;
      padding: .25rem .5rem;
      border-radius: .4rem;

      &:hover { background: rgba(147,197,253,.1); }
      .material-symbols-outlined { font-size: 1rem; }
    }

    .lic-key-row {
      display: flex;
      align-items: center;
      gap: .75rem;
      flex-wrap: wrap;
    }

    .meta-label { color: rgba(255,255,255,.4); font-size: .8rem; min-width: 100px; }

    .key-wrap { display: flex; align-items: center; gap: .4rem; }

    .lic-key {
      background: rgba(255,255,255,.07);
      padding: .25rem .625rem;
      border-radius: .35rem;
      font-size: .875rem;
      letter-spacing: .05em;
      word-break: break-all;
    }

    .copy-btn {
      background: none;
      border: 1px solid rgba(255,255,255,.15);
      border-radius: .35rem;
      color: rgba(255,255,255,.5);
      cursor: pointer;
      padding: .2rem .4rem;
      display: flex;
      align-items: center;
      transition: color .15s, border-color .15s;

      .material-symbols-outlined { font-size: 1rem; }
      &:hover { color: #fff; border-color: rgba(255,255,255,.3); }
      &:focus-visible { outline: 2px solid #e05a1e; outline-offset: 2px; }
    }

    .lic-notes { margin: 0; }
  `],
})
export class LicencjeComponent implements OnInit {
  private api = inject(KursantApiService);

  loading  = signal(true);
  licenses = signal<License[]>([]);

  ngOnInit(): void {
    this.api.getLicenses().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.licenses.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  copyKey(key: string): void {
    navigator.clipboard.writeText(key).then(() => {
      // Brief accessible announcement via screen reader
    });
  }
}
