import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, CurrencyPipe, DatePipe } from '@angular/common';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { BillingData } from '../../core/models/kursant.models';

@Component({
  selector: 'app-rozliczenia',
  standalone: true,
  imports: [CommonModule, CurrencyPipe, DatePipe],
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
    .balance-card { text-align: center; padding: 2rem; }

    .balance-label {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: .5rem;
      font-size: .85rem;
      text-transform: uppercase;
      letter-spacing: .07em;
      color: rgba(255,255,255,.45);
      margin: 0 0 .5rem;

      .material-symbols-outlined { font-size: 1rem; }
    }

    .balance-value {
      font-size: 2.75rem;
      font-weight: 700;
      margin: 0;

      &.positive { color: #4ade80; }
      &.negative { color: #fca5a5; }
    }

    .amount {
      font-weight: 600;
      &.positive { color: #4ade80; }
      &.negative { color: #fca5a5; }
    }

    .invoice-link {
      display: inline-flex;
      align-items: center;
      gap: .25rem;
      color: #93c5fd;
      text-decoration: none;
      font-size: .875rem;

      .material-symbols-outlined { font-size: .95rem; }
      &:hover { text-decoration: underline; }
    }
  `],
})
export class RozliczeniaComponent implements OnInit {
  private api = inject(KursantApiService);

  loading = signal(true);
  data    = signal<BillingData | null>(null);

  ngOnInit(): void {
    this.api.getBilling().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.data.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }
}
