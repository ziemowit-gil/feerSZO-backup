import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { VlabServer } from '../../core/models/kursant.models';

@Component({
  selector: 'app-vlab',
  standalone: true,
  imports: [CommonModule, DatePipe, MatButtonModule, MatSnackBarModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie VLab… }</div>

    <div class="page-header">
      <h1>VLab</h1>
      <p class="subtitle">Wirtualne środowiska laboratoryjne (Linux via SSH / terminal WWW)</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      <div class="vlab-grid">
        @for (srv of servers(); track srv.id) {
          <section class="k-card server-card" [attr.aria-label]="'Serwer VLab: ' + srv.hostname">
            <div class="server-header">
              <div>
                <h2 class="server-name">
                  <span class="material-symbols-outlined" aria-hidden="true">dns</span>
                  {{ srv.hostname }}
                </h2>
                <p class="text-muted text-sm">{{ srv.type === 'dedicated' ? 'Dedykowany' : 'Współdzielony' }}</p>
              </div>
              <span class="status-badge" [class]="srvStatusClass(srv.status)">
                {{ srvStatusLabel(srv.status) }}
              </span>
            </div>

            <div class="server-meta">
              <div class="meta-row">
                <span class="meta-label">Użytkownik</span>
                <code class="info-value">{{ srv.username }}</code>
              </div>
              <div class="meta-row">
                <span class="meta-label">Port SSH</span>
                <code class="info-value">{{ srv.port }}</code>
              </div>
              @if (srv.expires_at) {
                <div class="meta-row">
                  <span class="meta-label">Ważny do</span>
                  <span>{{ srv.expires_at | date:'d MMM yyyy':'':\'pl\' }}</span>
                </div>
              }
            </div>

            <div class="server-actions">
              @if (srv.web_terminal_url && srv.status === 'running') {
                <button mat-flat-button
                        (click)="openTerminal(srv)"
                        aria-label="Otwórz terminal WWW dla {{ srv.hostname }}">
                  <span class="material-symbols-outlined" aria-hidden="true">terminal</span>
                  Terminal WWW
                </button>
              }
              @if (srv.type === 'dedicated') {
                <button mat-stroked-button
                        class="btn-danger"
                        (click)="cancelServer(srv)"
                        [attr.aria-label]="'Anuluj serwer dedykowany: ' + srv.hostname">
                  Anuluj serwer
                </button>
              }
            </div>
          </section>
        }

        @if (servers().length === 0) {
          <div class="k-card">
            <div class="empty-state">
              <span class="material-symbols-outlined empty-icon" aria-hidden="true">terminal</span>
              <p>Brak przypisanych serwerów VLab.</p>
            </div>
          </div>
        }
      </div>

      <!-- Web terminal iframe -->
      @if (activeTermUrl()) {
        <section class="k-card terminal-section" aria-labelledby="terminal-heading">
          <div class="terminal-header">
            <h2 class="k-card-title mb-0" id="terminal-heading">
              <span class="material-symbols-outlined" aria-hidden="true">terminal</span>
              Terminal WWW
            </h2>
            <button mat-icon-button aria-label="Zamknij terminal" (click)="activeTermUrl.set(null)">
              <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
          </div>
          <iframe [src]="activeTermUrl()!"
                  class="terminal-iframe"
                  title="Terminal SSH VLab"
                  sandbox="allow-scripts allow-same-origin allow-forms">
          </iframe>
        </section>
      }

      <!-- Order dedicated server -->
      <section class="k-card" aria-labelledby="order-heading">
        <h2 class="k-card-title" id="order-heading">
          <span class="material-symbols-outlined" aria-hidden="true">add_circle</span>
          Zamów serwer dedykowany
        </h2>
        <p class="text-muted text-sm" style="margin:0 0 1rem">
          Serwer VPS przeznaczony wyłącznie dla Ciebie z pełnymi uprawnieniami root.
        </p>
        <button mat-flat-button
                (click)="orderServer()"
                [disabled]="ordering()"
                aria-label="Zamów dedykowany serwer VLab">
          <span class="material-symbols-outlined" aria-hidden="true">dns</span>
          Zamów serwer dedykowany
        </button>
      </section>
    }
  `,
  styles: [`
    .vlab-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
      gap: 1rem;
      margin-bottom: 1rem;
    }

    .server-card { display: flex; flex-direction: column; gap: .75rem; }

    .server-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: .5rem;
    }

    .server-name {
      display: flex;
      align-items: center;
      gap: .4rem;
      font-size: 1rem;
      font-weight: 600;
      margin: 0;
      .material-symbols-outlined { font-size: 1.1rem; }
    }

    .server-meta { display: flex; flex-direction: column; gap: .4rem; }

    .meta-row {
      display: flex;
      align-items: center;
      gap: .75rem;
      font-size: .875rem;
    }

    .meta-label {
      color: rgba(255,255,255,.45);
      font-size: .8rem;
      min-width: 100px;
    }

    .info-value {
      background: rgba(255,255,255,.08);
      padding: .2rem .5rem;
      border-radius: .3rem;
      font-size: .85rem;
    }

    .server-actions { display: flex; gap: .75rem; flex-wrap: wrap; }

    .btn-danger { color: #fca5a5 !important; border-color: rgba(239,68,68,.3) !important; }

    .terminal-section { margin-top: 1rem; }
    .mb-0 { margin-bottom: 0 !important; }

    .terminal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1rem;
    }

    .terminal-iframe {
      width: 100%;
      height: 500px;
      border: none;
      border-radius: .5rem;
      background: #0a0a14;
    }
  `],
})
export class VlabComponent implements OnInit {
  private api       = inject(KursantApiService);
  private snack     = inject(MatSnackBar);
  private sanitizer = inject(DomSanitizer);

  loading        = signal(true);
  servers        = signal<VlabServer[]>([]);
  ordering       = signal(false);
  activeTermUrl  = signal<SafeResourceUrl | null>(null);

  ngOnInit(): void {
    this.api.getVlab().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.servers.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  srvStatusClass(status: string): string {
    const map: Record<string, string> = {
      running: 'held', stopped: 'cancelled', provisioning: 'planned',
    };
    return map[status] ?? 'planned';
  }

  srvStatusLabel(status: string): string {
    const map: Record<string, string> = {
      running: 'Uruchomiony', stopped: 'Zatrzymany', provisioning: 'Uruchamianie…',
    };
    return map[status] ?? status;
  }

  openTerminal(srv: VlabServer): void {
    if (!srv.web_terminal_url) return;
    this.activeTermUrl.set(this.sanitizer.bypassSecurityTrustResourceUrl(srv.web_terminal_url));
    document.getElementById('terminal-heading')?.focus();
  }

  orderServer(): void {
    this.ordering.set(true);
    this.api.orderVlabServer().subscribe({
      next: res => {
        this.ordering.set(false);
        if (res.success) {
          this.snack.open('Zlecono zamówienie serwera dedykowanego. Proszę czekać.', 'OK', { duration: 5000 });
          this.api.getVlab().subscribe(r => { if (r.data) this.servers.set(r.data); });
        }
      },
      error: () => {
        this.ordering.set(false);
        this.snack.open('Błąd zamawiania serwera.', 'OK', { duration: 4000 });
      },
    });
  }

  cancelServer(srv: VlabServer): void {
    if (!confirm(`Anulować serwer ${srv.hostname}? Nie można cofnąć tej akcji.`)) return;
    this.api.cancelVlabServer(srv.id).subscribe({
      next: res => {
        if (res.success) {
          this.servers.update(list => list.filter(s => s.id !== srv.id));
          this.snack.open('Serwer anulowany.', 'OK', { duration: 4000 });
        }
      },
    });
  }
}
