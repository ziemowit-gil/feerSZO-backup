import { Component, signal, inject, OnInit, computed } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { OwnCloudState } from '../../core/models/kursant.models';

@Component({
  selector: 'app-dysk',
  standalone: true,
  imports: [CommonModule, DecimalPipe, MatButtonModule, MatSnackBarModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie dysku… }</div>

    <div class="page-header">
      <h1>Mój dysk</h1>
      <p class="subtitle">2 GB przestrzeni w chmurze (ownCloud)</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (state(); as s) {
      @if (!s.provisioned) {
        <div class="k-card text-center" style="padding:2.5rem">
          <span class="material-symbols-outlined" aria-hidden="true" style="font-size:4rem;color:rgba(255,255,255,.2)">cloud_upload</span>
          <h2>Aktywuj dysk w chmurze</h2>
          <p class="text-muted" style="margin-bottom:1.5rem">
            Uzyskaj dostęp do 2 GB przestrzeni do przechowywania plików.
          </p>
          <button mat-flat-button
                  (click)="provision()"
                  [disabled]="working()"
                  aria-label="Aktywuj dysk ownCloud">
            <span class="material-symbols-outlined" aria-hidden="true">cloud_done</span>
            Aktywuj dysk
          </button>
        </div>
      } @else {
        <!-- Quota -->
        <div class="k-card">
          <div class="quota-row">
            <div>
              <p class="quota-label">Zajęte miejsce</p>
              <p class="quota-value">
                {{ formatBytes(s.used_bytes) }}
                <span class="text-muted text-sm">/ {{ formatBytes(s.quota_bytes) }}</span>
              </p>
            </div>
            <div class="quota-pct" [attr.aria-label]="'Zajęto ' + usedPct() + '% przestrzeni'">
              {{ usedPct() }}%
            </div>
          </div>
          <div class="progress-wrap" style="margin-top:.75rem"
               role="progressbar"
               [attr.aria-valuenow]="usedPct()"
               aria-valuemin="0" aria-valuemax="100"
               [attr.aria-label]="'Dysk zajęty w ' + usedPct() + '%'">
            <div class="progress-fill" [style.width.%]="usedPct()"></div>
          </div>
        </div>

        <!-- Actions -->
        <div class="k-card actions-row">
          <div class="access-info">
            <div class="meta-row">
              <span class="meta-label">Login</span>
              <code class="info-code">{{ s.login }}</code>
            </div>
            @if (s.webdav_url) {
              <div class="meta-row">
                <span class="meta-label">WebDAV URL</span>
                <code class="info-code">{{ s.webdav_url }}</code>
              </div>
            }
          </div>

          <div class="action-btns">
            @if (s.files_app_url) {
              <a [href]="s.files_app_url"
                 target="_blank" rel="noopener noreferrer"
                 mat-flat-button
                 aria-label="Otwórz ownCloud w nowej karcie">
                <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
                Otwórz dysk
              </a>
            }
            <button mat-stroked-button
                    (click)="resetPwd()"
                    [disabled]="working()"
                    aria-label="Zresetuj hasło do dysku ownCloud">
              Resetuj hasło
            </button>
          </div>
        </div>
      }
    }
  `,
  styles: [`
    .quota-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .quota-label {
      font-size: .8rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #6b7280;
      margin: 0 0 .25rem;
    }

    .quota-value { font-size: 1.35rem; font-weight: 700; margin: 0; color: #111827; }

    .quota-pct {
      font-size: 1.75rem;
      font-weight: 700;
      color: #374151;
    }

    .actions-row { display: flex; flex-direction: column; gap: 1rem; }

    .access-info { display: flex; flex-direction: column; gap: .5rem; }

    .meta-row {
      display: flex;
      align-items: center;
      gap: .75rem;
      flex-wrap: wrap;
      font-size: .875rem;
    }

    .meta-label { color: #6b7280; min-width: 90px; font-size: .8rem; }

    .info-code {
      background: #f8fafc;
      border: 1px solid #e5e7eb;
      padding: .2rem .5rem;
      border-radius: .3rem;
      font-size: .85rem;
      color: #111827;
      word-break: break-all;
    }

    .action-btns { display: flex; gap: .75rem; flex-wrap: wrap; }
  `],
})
export class DyskComponent implements OnInit {
  private api   = inject(KursantApiService);
  private snack = inject(MatSnackBar);

  loading = signal(true);
  working = signal(false);
  state   = signal<OwnCloudState | null>(null);

  usedPct = computed(() => {
    const s = this.state();
    if (!s || !s.quota_bytes || s.quota_bytes === 0 || s.used_bytes == null) return 0;
    return Math.round((s.used_bytes / s.quota_bytes) * 100);
  });

  ngOnInit(): void {
    this.api.getOwnCloud().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.state.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  formatBytes(bytes: number | null): string {
    if (!bytes) return '0 B';
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${sizes[i]}`;
  }

  provision(): void {
    this.working.set(true);
    this.api.provisionOwnCloud().subscribe({
      next: res => {
        this.working.set(false);
        if (res.success) {
          this.snack.open('Dysk aktywowany! Odśwież stronę za chwilę.', 'OK', { duration: 5000 });
          this.api.getOwnCloud().subscribe(r => { if (r.data) this.state.set(r.data); });
        }
      },
      error: () => {
        this.working.set(false);
        this.snack.open('Błąd aktywacji dysku.', 'OK', { duration: 4000 });
      },
    });
  }

  resetPwd(): void {
    if (!confirm('Zresetować hasło do dysku ownCloud?')) return;
    this.working.set(true);
    this.api.resetOwnCloud().subscribe({
      next: res => {
        this.working.set(false);
        if (res.success) {
          this.snack.open('Hasło zresetowane. Sprawdź e-mail.', 'OK', { duration: 5000 });
        }
      },
      error: () => {
        this.working.set(false);
        this.snack.open('Błąd resetowania hasła.', 'OK', { duration: 4000 });
      },
    });
  }
}
