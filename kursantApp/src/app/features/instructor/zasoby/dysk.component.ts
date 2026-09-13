import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorOwnCloudAccount, InstructorOwnCloudReveal } from '../../../core/models/kursant.models';

/**
 * Mój dysk (prowadzący) — odpowiednik karty30/ti/dydaktyk/_tab_dysk.php:
 * samoobsługowe konto ownCloud (utwórz / resetuj hasło / usuń i załóż od
 * nowa). W klasycznym panelu hasło pokazuje się raz przez sesję; tu API
 * zwraca je wprost w odpowiedzi create/reset/recreate — pokazywane tylko
 * raz, dopóki użytkownik nie odejdzie ze strony (nigdzie nie zapisywane).
 */
@Component({
  selector: 'app-instructor-dysk',
  standalone: true,
  imports: [CommonModule, DatePipe, MatButtonModule, MatSnackBarModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie… }</div>

    <div class="page-header">
      <h1>Mój dysk</h1>
      <p class="subtitle">Własne miejsce na pliki w chmurze ownCloud</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      @if (reveal(); as r) {
        <div class="k-card reveal-card">
          <h2><span class="material-symbols-outlined" aria-hidden="true">key</span>Zapisz dane logowania — pokażemy je tylko raz</h2>
          <dl class="reveal-list">
            <div><dt>Adres</dt><dd><a [href]="r.url" target="_blank" rel="noopener">{{ r.url }}</a></dd></div>
            <div><dt>Login</dt><dd class="mono">{{ r.username }}</dd></div>
            <div><dt>Hasło</dt><dd class="mono">{{ r.password }}</dd></div>
            <div><dt>Limit</dt><dd>{{ r.quota_mb }} MB</dd></div>
          </dl>
          <p class="text-muted text-sm mb-0">Po opuszczeniu tej strony hasła nie pokażemy ponownie — w razie potrzeby zresetuj je przyciskiem poniżej.</p>
        </div>
      }

      @if (!enabled()) {
        <div class="k-card">
          <p class="mb-0 text-muted">Konta „Mój dysk” wymagają konta administratora ownCloud, którego administrator systemu jeszcze nie skonfigurował. Spróbuj później.</p>
        </div>
      } @else if (!account()) {
        <div class="k-card">
          <h2 class="mb-2">Nie masz jeszcze konta</h2>
          <p class="text-muted text-sm">Utworzymy konto ownCloud. Login i hasło zobaczysz od razu po utworzeniu — zapisz je w bezpiecznym miejscu.</p>
          <button mat-flat-button type="button" [disabled]="working()" (click)="create()">
            <span class="material-symbols-outlined" aria-hidden="true">add_circle</span>
            Utwórz konto
          </button>
        </div>
      } @else {
        <div class="k-card">
          <h2><span class="material-symbols-outlined" aria-hidden="true">check_circle</span>Twoje konto</h2>
          <dl class="reveal-list">
            <div><dt>Login</dt><dd class="mono">{{ account()!.owncloud_username }}</dd></div>
            <div><dt>Limit</dt><dd>{{ account()!.owncloud_quota_mb }} MB</dd></div>
            <div><dt>Założone</dt><dd>{{ account()!.owncloud_created_at | date:'d.MM.yyyy' }}</dd></div>
          </dl>
          <div class="actions-row">
            <a mat-stroked-button [href]="baseUrl()" target="_blank" rel="noopener">
              <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
              Otwórz ownCloud
            </a>
            <button mat-stroked-button type="button" [disabled]="working()" (click)="reset()">
              <span class="material-symbols-outlined" aria-hidden="true">key</span>
              Resetuj hasło
            </button>
            <button mat-stroked-button type="button" class="btn-danger" [disabled]="working()" (click)="recreate()">
              <span class="material-symbols-outlined" aria-hidden="true">restart_alt</span>
              Utwórz konto od nowa
            </button>
          </div>
        </div>
      }
    }
  `,
  styles: [`
    .reveal-card { border: 1px solid var(--c-warning, #d97706); background: var(--c-warning-bg, #fffbeb); }
    .reveal-card h2, .k-card h2 { display: flex; align-items: center; gap: .5rem; font-size: 1.05rem; margin: 0 0 .85rem;
      .material-symbols-outlined { color: var(--c-primary, #4f46e5); }
    }
    .reveal-list { display: grid; grid-template-columns: auto 1fr; gap: .4rem 1rem; margin: 0 0 .85rem; font-size: .9rem;
      dt { color: var(--c-text-muted); }
      dd { margin: 0; }
      .mono { font-family: monospace; }
    }
    .actions-row { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
    .btn-danger { color: #b91c1c !important; border-color: #b91c1c !important; }
  `],
})
export class InstructorDyskComponent implements OnInit {
  private api   = inject(InstructorApiService);
  private snack = inject(MatSnackBar);

  loading = signal(true);
  working = signal(false);
  enabled = signal(true);
  account = signal<InstructorOwnCloudAccount | null>(null);
  reveal  = signal<InstructorOwnCloudReveal | null>(null);
  baseUrl = signal('');

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.getOwnCloudStatus().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) {
          this.enabled.set(res.data.enabled);
          this.account.set(res.data.account);
          this.baseUrl.set(res.data.url);
        }
      },
      error: () => this.loading.set(false),
    });
  }

  create(): void {
    this.working.set(true);
    this.api.ownCloudCreate().subscribe({
      next: res => {
        this.working.set(false);
        this.snack.open(res.message || 'Konto utworzone.', 'OK', { duration: 4000 });
        if (res.success && res.data) { this.reveal.set(res.data); this.load(); }
      },
      error: err => { this.working.set(false); this.snack.open(err?.error?.error || 'Nie udało się utworzyć konta.', 'OK', { duration: 5000 }); },
    });
  }

  reset(): void {
    if (!confirm('Zresetować hasło? Stare hasło stanie się nieprawidłowe.')) return;
    this.working.set(true);
    this.api.ownCloudReset().subscribe({
      next: res => {
        this.working.set(false);
        this.snack.open(res.message || 'Hasło zresetowane.', 'OK', { duration: 4000 });
        if (res.success && res.data) this.reveal.set(res.data);
      },
      error: err => { this.working.set(false); this.snack.open(err?.error?.error || 'Nie udało się zresetować hasła.', 'OK', { duration: 5000 }); },
    });
  }

  recreate(): void {
    if (!confirm('UWAGA: to usunie Twoje obecne konto ownCloud WRAZ ZE WSZYSTKIMI plikami — nieodwracalnie. Zostanie od razu założone nowe, puste konto z nowym loginem i hasłem. Czy na pewno chcesz kontynuować?')) return;
    this.working.set(true);
    this.api.ownCloudRecreate().subscribe({
      next: res => {
        this.working.set(false);
        this.snack.open(res.message || 'Konto odtworzone.', 'OK', { duration: 4000 });
        if (res.success && res.data) { this.reveal.set(res.data); this.load(); }
      },
      error: err => { this.working.set(false); this.snack.open(err?.error?.error || 'Nie udało się odtworzyć konta.', 'OK', { duration: 5000 }); },
    });
  }
}
