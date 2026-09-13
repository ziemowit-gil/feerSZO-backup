import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, CurrencyPipe, DatePipe } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { WalletData } from '../../core/models/kursant.models';

const GATEWAY_LABELS: Record<string, string> = {
  stripe: 'Karta / BLIK / Przelewy — Stripe',
  payu:   'BLIK / szybki przelew — PayU',
  p24:    'BLIK / szybki przelew — Przelewy24',
};

const QUICK_AMOUNTS = [50, 100, 200, 500];

/**
 * Portfel — przedpłata: doładowanie (online lub przelewem) trafia na konto
 * ogólne i pokrywa kolejne miesięczne rozliczenia automatycznie (FIFO).
 * Odpowiednik karty30/ti/kursant/_portfel_view.php, bez programu poleceń
 * (świadomie pominięty w tej rundzie — zob. project_kursant_angular.md).
 */
@Component({
  selector: 'app-portfel',
  standalone: true,
  imports: [CommonModule, CurrencyPipe, DatePipe, FormsModule, RouterLink, MatButtonModule, MatSnackBarModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie portfela… }</div>

    <div class="page-header">
      <h1>Portfel</h1>
      <p class="subtitle">Dostępne środki, doładowanie i historia operacji</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie portfela">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (data(); as d) {
      <div class="summary-grid">
        <div class="k-card balance-card">
          <p class="balance-label">Dostępne środki</p>
          <p class="balance-value positive">{{ d.balance.credit | currency:'PLN':'symbol':'1.2-2':'pl' }}</p>
        </div>
        <div class="k-card balance-card">
          <p class="balance-label">Do zapłaty</p>
          <p class="balance-value" [class.negative]="d.balance.debt > 0.005">
            {{ d.balance.debt | currency:'PLN':'symbol':'1.2-2':'pl' }}
          </p>
        </div>
        <div class="k-card balance-card">
          <p class="balance-label">Suma wszystkich wpłat</p>
          <p class="balance-value">{{ d.balance.payments | currency:'PLN':'symbol':'1.2-2':'pl' }}</p>
        </div>
      </div>

      <div class="k-alert info">
        <span class="material-symbols-outlined" aria-hidden="true">info</span>
        <span>
          Portfel działa jak przedpłata: wpłacasz dowolną kwotę, a opłaty za kolejne zajęcia są
          <strong>pobierane z niego automatycznie</strong> (od najstarszej należności). Szczegóły należności
          znajdziesz w zakładce <a routerLink="/rozliczenia">Rozliczenia</a>.
        </span>
      </div>

      @if (d.pending.length > 0) {
        <section class="k-card" aria-labelledby="pending-heading">
          <h2 class="k-card-title" id="pending-heading">
            <span class="material-symbols-outlined" aria-hidden="true">hourglass_top</span>
            Nieukończone doładowania
          </h2>
          <ul class="pending-list">
            @for (p of d.pending; track p.url) {
              <li class="pending-item">
                <span>
                  <strong>{{ p.amount | currency:'PLN':'symbol':'1.2-2':'pl' }}</strong>
                  <span class="text-muted text-sm"> · {{ p.label }} · rozpoczęto {{ p.created_at | date:'d.MM.yyyy HH:mm' }}</span>
                </span>
                <a [href]="p.url" mat-stroked-button rel="noopener">Dokończ płatność</a>
              </li>
            }
          </ul>
        </section>
      }

      <div class="topup-grid">
        <section class="k-card" aria-labelledby="topup-heading">
          <h2 class="k-card-title" id="topup-heading">
            <span class="material-symbols-outlined" aria-hidden="true">add_circle</span>
            Doładuj portfel online
          </h2>
          @if (hasOnlineGateway(d)) {
            <label for="pwAmount" class="field-label">Kwota doładowania</label>
            <div class="amount-input">
              <input type="number" id="pwAmount" min="1" max="20000" step="0.01" [(ngModel)]="topupAmount">
              <span>zł</span>
            </div>
            <div class="quick-amounts">
              @for (q of quickAmounts; track q) {
                <button type="button" mat-stroked-button class="btn-small" (click)="topupAmount = q">{{ q }} zł</button>
              }
              @if (d.balance.debt > 0.005) {
                <button type="button" mat-stroked-button color="warn" class="btn-small" (click)="topupAmount = d.balance.debt">
                  Spłać zaległość ({{ d.balance.debt | currency:'PLN':'symbol':'1.2-2':'pl' }})
                </button>
              }
            </div>

            <fieldset class="gateway-fieldset">
              <legend>Metoda płatności</legend>
              @for (gw of enabledGateways(d); track gw) {
                <label class="radio-row">
                  <input type="radio" name="topupProvider" [value]="gw" [(ngModel)]="topupProvider">
                  {{ gatewayLabel(gw) }}
                </label>
              }
            </fieldset>

            <button mat-flat-button [disabled]="topupSubmitting()" (click)="submitTopup()">
              <span class="material-symbols-outlined" aria-hidden="true">lock</span>
              Przejdź do płatności
            </button>
            <p class="text-muted text-sm" style="margin-top:.75rem">
              Po opłaceniu wrócisz do panelu, a środki trafią do portfela automatycznie.
            </p>
          } @else {
            <p class="text-muted text-sm mb-0">
              Płatności online nie są w tej chwili dostępne — portfel możesz doładować przelewem tradycyjnym (obok).
            </p>
          }
        </section>

        <section class="k-card" aria-labelledby="declare-heading">
          <h2 class="k-card-title" id="declare-heading">
            <span class="material-symbols-outlined" aria-hidden="true">account_balance</span>
            Doładowanie przelewem tradycyjnym
          </h2>
          @if (d.pay_account || d.pay_title) {
            <dl class="pay-info-list">
              @if (d.pay_account) {
                <div class="pay-info-row"><dt>Nr konta</dt><dd class="pay-account">{{ d.pay_account }}</dd></div>
              }
              @if (d.pay_title) {
                <div class="pay-info-row"><dt>Tytuł wpłaty</dt><dd>{{ d.pay_title }}</dd></div>
              }
            </dl>
            <p class="text-muted text-sm">
              <span class="material-symbols-outlined" aria-hidden="true" style="font-size:.9rem;vertical-align:middle">schedule</span>
              Przelew tradycyjny księgujemy ręcznie — środki pojawią się w portfelu w ciągu 1–2 dni roboczych.
              Możesz od razu zgłosić, że przelew został wykonany.
            </p>
          } @else {
            <p class="text-muted text-sm">Dane do wpłaty nie zostały jeszcze ustawione — skontaktuj się z placówką.</p>
          }

          <div class="declare-form">
            <div>
              <label for="pwDeclAmt" class="field-label">Kwota przelewu</label>
              <div class="amount-input"><input type="number" id="pwDeclAmt" min="1" max="20000" step="0.01" [(ngModel)]="declareAmount"><span>zł</span></div>
            </div>
            <div>
              <label for="pwDeclNote" class="field-label">Tytuł / referencja (opcjonalnie)</label>
              <input type="text" id="pwDeclNote" maxlength="500" class="text-input" [(ngModel)]="declareNote">
            </div>
            <button mat-stroked-button [disabled]="declareSubmitting()" (click)="submitDeclare()">
              <span class="material-symbols-outlined" aria-hidden="true">send</span>
              Zgłoś wykonany przelew
            </button>
          </div>
        </section>
      </div>

      <section class="k-card" aria-labelledby="ops-heading">
        <h2 class="k-card-title" id="ops-heading">
          <span class="material-symbols-outlined" aria-hidden="true">history</span>
          Historia operacji
        </h2>
        @if (d.ops.length === 0) {
          <div class="empty-state">
            <span class="material-symbols-outlined empty-icon" aria-hidden="true">receipt_long</span>
            <p>Brak operacji — doładuj portfel powyżej.</p>
          </div>
        } @else {
          <div class="k-table-wrap">
            <table class="k-table" aria-label="Historia operacji portfela">
              <thead>
                <tr><th scope="col">Data</th><th scope="col">Operacja</th><th scope="col">Kwota</th><th scope="col">Status</th></tr>
              </thead>
              <tbody>
                @for (op of d.ops; track op.date + op.label) {
                  <tr>
                    <td class="text-nowrap">{{ op.date | date:'d.MM.yyyy' }}</td>
                    <td>
                      <span class="material-symbols-outlined op-icon" [class]="op.kind" aria-hidden="true">
                        {{ op.kind === 'declared' ? 'hourglass_top' : (op.kind === 'in' ? 'south' : 'north') }}
                      </span>
                      {{ op.label }}
                      @if (op.note) { <div class="text-muted text-sm">{{ op.note }}</div> }
                    </td>
                    <td class="fw-semibold text-nowrap" [class.positive]="op.kind === 'in'">
                      {{ op.kind === 'out' ? '−' : '+' }}{{ op.amount | currency:'PLN':'symbol':'1.2-2':'pl' }}
                    </td>
                    <td>
                      @if (op.kind === 'declared') {
                        <span class="status-badge draft">oczekuje na zatwierdzenie</span>
                      } @else if (op.kind === 'in') {
                        <span class="status-badge paid">zaksięgowana</span>
                      } @else if (op.status === 'paid') {
                        <span class="status-badge paid">pokryta z portfela</span>
                      } @else {
                        <span class="status-badge issued">{{ op.covered > 0.005 ? ('częściowo pokryta (' + (op.covered | currency:'PLN':'symbol':'1.2-2':'pl') + ')') : 'do pokrycia' }}</span>
                      }
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      </section>
    }
  `,
  styles: [`
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
    .balance-card { text-align: center; padding: 1.5rem; margin-bottom: 0; }
    .balance-label { font-size: .8rem; text-transform: uppercase; letter-spacing: .07em; color: #6b7280; margin: 0 0 .5rem; }
    .balance-value { font-size: 1.75rem; font-weight: 700; margin: 0; &.positive { color: #15803d; } &.negative { color: #b91c1c; } }
    .positive { color: #15803d; }

    .pending-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .5rem; }
    .pending-item { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; padding: .5rem 0; border-bottom: 1px solid #f3f4f6; &:last-child { border-bottom: none; } }

    .topup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; align-items: start; }
    @media (max-width: 900px) { .topup-grid { grid-template-columns: 1fr; } }

    .field-label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: .35rem; }
    .amount-input { display: flex; align-items: center; gap: .5rem; max-width: 220px; margin-bottom: 1rem;
      input { flex: 1; padding: .5rem .75rem; border: 1px solid var(--c-border); border-radius: .5rem; }
    }
    .text-input { width: 100%; padding: .5rem .75rem; border: 1px solid var(--c-border); border-radius: .5rem; margin-bottom: 1rem; }

    .quick-amounts { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; }

    .gateway-fieldset { border: none; padding: 0; margin: 0 0 1rem; legend { font-size: .85rem; font-weight: 600; margin-bottom: .5rem; } }
    .radio-row { display: flex; align-items: center; gap: .5rem; margin-bottom: .4rem; font-size: .9rem; }

    .pay-info-list { margin: 0 0 1rem; }
    .pay-info-row { display: flex; gap: 1rem; padding: .3rem 0; font-size: .9rem;
      dt { flex: 0 0 7rem; color: #6b7280; font-weight: normal; } dd { margin: 0; }
    }
    .pay-account { font-family: monospace; font-weight: 600; }

    .declare-form { border-top: 1px solid var(--c-border); padding-top: 1rem; margin-top: .5rem; }

    .op-icon { font-size: 1rem; vertical-align: middle; margin-right: .35rem;
      &.in { color: #15803d; } &.out { color: #6b7280; } &.declared { color: #b45309; }
    }
    .fw-semibold { font-weight: 600; }
  `],
})
export class PortfelComponent implements OnInit {
  private api   = inject(KursantApiService);
  private route = inject(ActivatedRoute);
  private snack = inject(MatSnackBar);

  loading = signal(true);
  data    = signal<WalletData | null>(null);

  quickAmounts = QUICK_AMOUNTS;
  topupAmount     = 0;
  topupProvider   = '';
  topupSubmitting = signal(false);

  declareAmount     = 0;
  declareNote       = '';
  declareSubmitting = signal(false);

  gatewayLabel(gw: string): string { return GATEWAY_LABELS[gw] ?? gw; }
  enabledGateways(d: WalletData): string[] {
    return (['stripe', 'payu', 'p24'] as const).filter(g => d.gateways[g]);
  }
  hasOnlineGateway(d: WalletData): boolean { return this.enabledGateways(d).length > 0; }

  ngOnInit(): void {
    this.api.getWallet().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) {
          this.data.set(res.data);
          this.topupProvider = this.enabledGateways(res.data)[0] ?? '';
        }
      },
      error: () => this.loading.set(false),
    });

    const params = this.route.snapshot.queryParamMap;
    const wpay = params.get('wpay');
    if (wpay) {
      this.snack.open('Płatność jest przetwarzana. Środki pojawią się w portfelu po potwierdzeniu.', 'OK', { duration: 6000 });
    } else if (params.get('wcancel')) {
      this.snack.open('Płatność została przerwana. Możesz spróbować ponownie.', 'OK', { duration: 4000 });
    }
  }

  submitTopup(): void {
    if (!this.topupProvider || this.topupAmount < 1) {
      this.snack.open('Podaj kwotę i wybierz metodę płatności.', 'OK', { duration: 3000 });
      return;
    }
    this.topupSubmitting.set(true);
    this.api.walletTopup(this.topupAmount, this.topupProvider).subscribe({
      next: res => {
        this.topupSubmitting.set(false);
        if (res.success && res.data?.url) window.location.href = res.data.url;
        else this.snack.open(res.error || 'Nie udało się rozpocząć płatności.', 'OK', { duration: 5000 });
      },
      error: err => {
        this.topupSubmitting.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się rozpocząć płatności.', 'OK', { duration: 5000 });
      },
    });
  }

  submitDeclare(): void {
    if (this.declareAmount < 1) {
      this.snack.open('Podaj kwotę przelewu.', 'OK', { duration: 3000 });
      return;
    }
    this.declareSubmitting.set(true);
    this.api.walletDeclare(this.declareAmount, this.declareNote).subscribe({
      next: res => {
        this.declareSubmitting.set(false);
        this.snack.open(res.message || 'Zgłoszenie przyjęte.', 'OK', { duration: 6000 });
        if (res.success) { this.declareAmount = 0; this.declareNote = ''; }
      },
      error: err => {
        this.declareSubmitting.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się wysłać zgłoszenia.', 'OK', { duration: 5000 });
      },
    });
  }
}
