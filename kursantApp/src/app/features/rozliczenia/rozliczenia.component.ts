import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, CurrencyPipe, DatePipe } from '@angular/common';
import { ActivatedRoute } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { BillingData, YearEndOverpayInfo } from '../../core/models/kursant.models';

const GATEWAY_LABELS: Record<string, string> = {
  stripe: 'Karta / BLIK / Przelewy — Stripe',
  payu:   'BLIK / szybki przelew — PayU',
  p24:    'BLIK / szybki przelew — Przelewy24',
};

@Component({
  selector: 'app-rozliczenia',
  standalone: true,
  imports: [CommonModule, CurrencyPipe, DatePipe, FormsModule, MatButtonModule, MatSnackBarModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie rozliczeń… }</div>

    <div class="page-header">
      <h1>Rozliczenia</h1>
      <p class="subtitle">Historia płatności i saldo konta</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie rozliczeń">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (yearEnd(); as ye) {
      @if (ye.allowed) {
        <section class="k-card year-end-card" aria-labelledby="year-end-heading">
          <h2 class="k-card-title" id="year-end-heading">
            <span class="material-symbols-outlined" aria-hidden="true">event_available</span>
            Nadpłata do końca roku {{ ye.year }}
          </h2>

          <div class="k-alert info">
            <span class="material-symbols-outlined" aria-hidden="true">info</span>
            <span>
              Z powodów podatkowych/księgowych taką nadpłatę można opłacić tylko do
              <strong>31 grudnia {{ ye.year }}</strong> — faktura/potwierdzenie wpłaty za dany rok musi być
              wystawiona w tym samym roku podatkowym. Środki trafią do portfela jako nadpłata i automatycznie
              pokryją kolejne miesięczne rozliczenia.
            </span>
          </div>

          @if (ye.courses && ye.courses.length) {
            <div class="k-table-wrap" style="margin-bottom:1rem">
              <table class="k-table" aria-label="Szacunek pozostałych opłat do końca roku wg grupy">
                <thead>
                  <tr><th scope="col">Grupa</th><th scope="col">Model</th><th scope="col">Szacunek</th></tr>
                </thead>
                <tbody>
                  @for (c of ye.courses; track c.course_id) {
                    <tr>
                      <td>{{ c.course_name }}</td>
                      <td class="text-muted text-sm">{{ c.model_label }}</td>
                      <td>{{ c.amount | currency:'PLN':'symbol':'1.2-2':'pl' }}</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
            <p class="text-muted text-sm" style="margin:0 0 1rem">
              Suma szacunkowa: <strong>{{ ye.projected_total | currency:'PLN':'symbol':'1.2-2':'pl' }}</strong>
              @if ((ye.current_credit ?? 0) > 0.005) {
                — pomniejszona o obecną nadpłatę w portfelu ({{ ye.current_credit | currency:'PLN':'symbol':'1.2-2':'pl' }}).
              }
              To szacunek na podstawie zaplanowanych zajęć — kwotę możesz zmienić poniżej.
            </p>
          } @else {
            <p class="text-muted text-sm" style="margin:0 0 1rem">Nie udało się wyliczyć szacunku — podaj kwotę ręcznie.</p>
          }

          @if (ye.gateways && ye.gateways.length) {
            <div class="mat-mdc-form-field" style="max-width:220px;margin-bottom:1rem">
              <label for="yeAmount" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.35rem">Kwota nadpłaty</label>
              <input type="number" id="yeAmount" class="form-control" min="1" max="50000" step="0.01"
                     [(ngModel)]="yearEndAmount"
                     style="width:100%;padding:.5rem .75rem;border:1px solid var(--c-border);border-radius:.5rem">
            </div>

            <fieldset style="border:none;padding:0;margin:0 0 1rem">
              <legend style="font-size:.85rem;font-weight:600;margin-bottom:.5rem">Metoda płatności</legend>
              @for (gw of ye.gateways; track gw) {
                <label style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem;font-size:.9rem">
                  <input type="radio" name="yeProvider" [value]="gw" [(ngModel)]="yearEndProvider">
                  {{ gatewayLabel(gw) }}
                </label>
              }
            </fieldset>

            <button mat-flat-button [disabled]="yearEndSubmitting()" (click)="submitYearEnd()">
              <span class="material-symbols-outlined" aria-hidden="true" style="vertical-align:middle">lock</span>
              Przejdź do płatności
            </button>
            <p class="text-muted text-sm" style="margin-top:.75rem">
              Po zaksięgowaniu wpłaty placówka automatycznie dostanie prośbę o przygotowanie faktury za ten rok.
            </p>
          } @else {
            <p class="text-muted text-sm" style="margin:0">Płatności online nie są teraz dostępne dla tego konta — skontaktuj się z placówką.</p>
          }

          @if (ye.transfer_allowed) {
            <div style="border-top:1px solid var(--c-border);margin-top:1.25rem;padding-top:1rem">
              <p class="text-muted text-sm" style="margin:0 0 .5rem">
                Możesz też wpłacić nadpłatę przelewem tradycyjnym
                @if (ye.transfer_account) { na konto <strong>{{ ye.transfer_account }}</strong> }
                @if (ye.transfer_title) { (tytuł: {{ ye.transfer_title }}) }
                i zgłosić to od razu — placówka zaksięguje wpłatę i automatycznie przygotuje fakturę.
              </p>
              <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
                <div>
                  <label for="yeDeclAmt" style="display:block;font-size:.8rem;margin-bottom:.25rem">Kwota przelewu</label>
                  <input type="number" id="yeDeclAmt" min="1" max="50000" step="0.01"
                         [(ngModel)]="yearEndDeclareAmount"
                         style="padding:.4rem .6rem;border:1px solid var(--c-border);border-radius:.5rem;width:140px">
                </div>
                <div style="flex:1;min-width:180px">
                  <label for="yeDeclNote" style="display:block;font-size:.8rem;margin-bottom:.25rem">Tytuł / referencja (opcjonalnie)</label>
                  <input type="text" id="yeDeclNote" maxlength="500"
                         [(ngModel)]="yearEndDeclareNote"
                         style="padding:.4rem .6rem;border:1px solid var(--c-border);border-radius:.5rem;width:100%">
                </div>
                <button mat-stroked-button [disabled]="yearEndDeclareSubmitting()" (click)="submitYearEndDeclare()">
                  Zgłoś przelew
                </button>
              </div>
            </div>
          }
        </section>
      }
    }

    @if (data(); as d) {
      <!-- Balance card -->
      <div class="k-card balance-card">
        <p class="balance-label">
          <span class="material-symbols-outlined" aria-hidden="true">account_balance_wallet</span>
          Saldo konta
        </p>
        <p class="balance-value"
           [class.positive]="d.balance >= 0"
           [class.negative]="d.balance < 0"
           [attr.aria-label]="'Saldo: ' + (d.balance | currency:d.currency:'symbol':'1.2-2':'pl')">
          {{ d.balance | currency:d.currency:'symbol':'1.2-2':'pl' }}
        </p>
        @if (d.balance < 0) {
          <div class="k-alert warning" role="alert" style="margin-top:1rem">
            <span class="material-symbols-outlined" aria-hidden="true">warning</span>
            Masz zaległość. Skontaktuj się z organizacją.
          </div>
        }
      </div>

      <!-- Dane do wpłaty -->
      @if (d.pay_account || d.pay_title || d.pay_codes?.length || d.pay_refs?.length) {
        <section class="k-card" aria-labelledby="pay-info-heading">
          <h2 class="k-card-title" id="pay-info-heading">
            <span class="material-symbols-outlined" aria-hidden="true">account_balance</span>
            Dane do wpłaty
          </h2>
          <dl class="pay-info-list">
            @if (d.pay_account) {
              <div class="pay-info-row">
                <dt>Nr konta</dt>
                <dd class="pay-account">{{ d.pay_account }}</dd>
              </div>
            }
            @if (d.pay_title) {
              <div class="pay-info-row">
                <dt>Tytuł wpłaty</dt>
                <dd>{{ d.pay_title }}</dd>
              </div>
            }
            @if (d.pay_codes?.length) {
              <div class="pay-info-row">
                <dt>Kod rozliczeń</dt>
                <dd>
                  @for (code of d.pay_codes; track code) {
                    <span class="pay-code-chip" [class.individual]="code === 9999">
                      {{ code === 9999 ? '9999 · indywidualny' : code }}
                    </span>
                  }
                </dd>
              </div>
            }
            @if (d.pay_refs?.length) {
              <div class="pay-info-row">
                <dt>Numer referencyjny</dt>
                <dd>
                  @for (r of d.pay_refs; track r.course_id) {
                    <div class="pay-ref-item">
                      <span class="pay-account">{{ r.ref }}</span>
                      @if (d.pay_refs.length > 1) {
                        <span class="text-muted text-sm"> — {{ r.course_name }}</span>
                      }
                    </div>
                  }
                </dd>
              </div>
            }
          </dl>
          @if (!d.pay_account && !d.pay_title) {
            <p class="text-muted text-sm mb-0">Dane do wpłaty nie zostały jeszcze ustawione — skontaktuj się z placówką.</p>
          }
        </section>
      }

      <!-- History table -->
      <section class="k-card" aria-labelledby="billing-history-heading">
        <h2 class="k-card-title" id="billing-history-heading">
          <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
          Historia transakcji
        </h2>

        @if (d.entries.length === 0) {
          <div class="empty-state">
            <span class="material-symbols-outlined empty-icon" aria-hidden="true">receipt</span>
            <p>Brak historii transakcji.</p>
          </div>
        } @else {
          <div class="k-table-wrap">
            <table class="k-table" aria-label="Historia płatności">
              <thead>
                <tr>
                  <th scope="col">Data</th>
                  <th scope="col">Opis</th>
                  <th scope="col">Kwota</th>
                  <th scope="col">Status</th>
                  <th scope="col"><span class="sr-only">Faktura</span></th>
                </tr>
              </thead>
              <tbody>
                @for (entry of d.entries; track entry.id) {
                  <tr>
                    <td>{{ entry.date | date:'d MMM yyyy':'':\'pl\' }}</td>
                    <td>{{ entry.description }}</td>
                    <td class="amount" [class.positive]="entry.type === 'payment'" [class.negative]="entry.type === 'charge'">
                      {{ entry.type === 'payment' ? '+' : '-' }}{{ entry.amount | currency:'PLN':'symbol':'1.2-2':'pl' }}
                    </td>
                    <td>
                      <span class="status-badge" [class]="entry.status === 'paid' ? 'held' : 'planned'">
                        {{ entry.status === 'paid' ? 'Opłacone' : entry.status }}
                      </span>
                    </td>
                    <td>
                      @if (entry.invoice_url) {
                        <a [href]="entry.invoice_url"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="invoice-link"
                           [attr.aria-label]="'Pobierz fakturę za: ' + entry.description + ', nowa karta'">
                          <span class="material-symbols-outlined" aria-hidden="true">download</span>
                          Faktura
                        </a>
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
    .pay-info-list { margin: 0; }

    .pay-info-row {
      display: flex;
      gap: 1rem;
      padding: .4rem 0;
      border-bottom: 1px solid #f3f4f6;
      font-size: .9rem;

      &:last-child { border-bottom: none; }

      dt { flex: 0 0 9rem; color: #6b7280; font-weight: normal; }
      dd { margin: 0; flex: 1; min-width: 0; word-break: break-word; }
    }

    .pay-account { font-family: monospace; font-weight: 600; letter-spacing: .02em; }

    .pay-ref-item { margin-bottom: .25rem; &:last-child { margin-bottom: 0; } }

    .pay-code-chip {
      display: inline-flex;
      align-items: center;
      padding: .1rem .5rem;
      border-radius: 1rem;
      background: #f3f4f6;
      color: #374151;
      font-size: .8rem;
      margin-right: .35rem;

      &.individual { background: #fffbeb; color: #92400e; }
    }

    .balance-card { text-align: center; padding: 2rem; }

    .balance-label {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: .5rem;
      font-size: .85rem;
      text-transform: uppercase;
      letter-spacing: .07em;
      color: #6b7280;
      margin: 0 0 .5rem;

      .material-symbols-outlined { font-size: 1rem; }
    }

    .balance-value {
      font-size: 2.75rem;
      font-weight: 700;
      margin: 0;

      &.positive { color: #15803d; }
      &.negative { color: #b91c1c; }
    }

    .amount {
      font-weight: 600;
      &.positive { color: #15803d; }
      &.negative { color: #b91c1c; }
    }

    .invoice-link {
      display: inline-flex;
      align-items: center;
      gap: .25rem;
      color: #1d4ed8;
      text-decoration: none;
      font-size: .875rem;

      .material-symbols-outlined { font-size: .95rem; }
      &:hover { text-decoration: underline; }
    }
  `],
})
export class RozliczeniaComponent implements OnInit {
  private api    = inject(KursantApiService);
  private route  = inject(ActivatedRoute);
  private snack  = inject(MatSnackBar);

  loading = signal(true);
  data    = signal<BillingData | null>(null);

  yearEnd            = signal<YearEndOverpayInfo | null>(null);
  yearEndAmount       = 0;
  yearEndProvider     = '';
  yearEndSubmitting   = signal(false);

  yearEndDeclareAmount     = 0;
  yearEndDeclareNote       = '';
  yearEndDeclareSubmitting = signal(false);

  gatewayLabel(gw: string): string { return GATEWAY_LABELS[gw] ?? gw; }

  ngOnInit(): void {
    this.api.getBilling().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.data.set(res.data);
      },
      error: () => this.loading.set(false),
    });

    this.api.getYearEndOverpayInfo().subscribe({
      next: res => {
        if (res.success && res.data) {
          this.yearEnd.set(res.data);
          this.yearEndAmount = res.data.suggested_amount || 0;
          this.yearEndDeclareAmount = res.data.suggested_amount || 0;
          this.yearEndProvider = res.data.gateways?.[0] ?? '';
        }
      },
      error: () => {},
    });

    // Powrót z bramki płatności (Stripe/PayU/Przelewy24) — komunikat o statusie.
    const params = this.route.snapshot.queryParamMap;
    const wpay = params.get('wpay');
    if (wpay) {
      this.snack.open('Płatność jest przetwarzana. Środki pojawią się w portfelu po potwierdzeniu.', 'OK', { duration: 6000 });
    } else if (params.get('wcancel')) {
      this.snack.open('Płatność została przerwana. Możesz spróbować ponownie.', 'OK', { duration: 4000 });
    }
  }

  submitYearEnd(): void {
    if (!this.yearEndProvider || this.yearEndAmount < 1) {
      this.snack.open('Podaj kwotę i wybierz metodę płatności.', 'OK', { duration: 3000 });
      return;
    }
    this.yearEndSubmitting.set(true);
    this.api.yearEndOverpay(this.yearEndAmount, this.yearEndProvider).subscribe({
      next: res => {
        this.yearEndSubmitting.set(false);
        if (res.success && res.data?.url) {
          window.location.href = res.data.url;
        } else {
          this.snack.open(res.error || 'Nie udało się rozpocząć płatności.', 'OK', { duration: 5000 });
        }
      },
      error: err => {
        this.yearEndSubmitting.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się rozpocząć płatności.', 'OK', { duration: 5000 });
      },
    });
  }

  submitYearEndDeclare(): void {
    if (this.yearEndDeclareAmount < 1) {
      this.snack.open('Podaj kwotę przelewu.', 'OK', { duration: 3000 });
      return;
    }
    this.yearEndDeclareSubmitting.set(true);
    this.api.yearEndDeclareTransfer(this.yearEndDeclareAmount, this.yearEndDeclareNote).subscribe({
      next: res => {
        this.yearEndDeclareSubmitting.set(false);
        if (res.success) {
          this.snack.open('Zgłoszenie przyjęte — placówka zaksięguje przelew po jego zaksięgowaniu na koncie.', 'OK', { duration: 6000 });
        } else {
          this.snack.open(res.error || 'Nie udało się wysłać zgłoszenia.', 'OK', { duration: 5000 });
        }
      },
      error: err => {
        this.yearEndDeclareSubmitting.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się wysłać zgłoszenia.', 'OK', { duration: 5000 });
      },
    });
  }
}
