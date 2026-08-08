import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { AuthorizedPerson } from '../../core/models/kursant.models';

@Component({
  selector: 'app-upowaznieni',
  standalone: true,
  imports: [CommonModule, DatePipe],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie upoważnionych… }</div>

    <div class="page-header">
      <h1>Upoważnieni</h1>
      <p class="subtitle">Osoby uprawnione do odbioru informacji o Twoich zajęciach</p>
    </div>

    <div class="k-alert info" role="note">
      <span class="material-symbols-outlined" aria-hidden="true">info</span>
      Aby dodać lub usunąć osobę upoważnioną, skontaktuj się bezpośrednio z organizacją.
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading() && persons().length === 0) {
      <div class="k-card">
        <div class="empty-state">
          <span class="material-symbols-outlined empty-icon" aria-hidden="true">supervisor_account</span>
          <p>Brak zarejestrowanych osób upoważnionych.</p>
        </div>
      </div>
    }

    @if (!loading() && persons().length > 0) {
      <div class="persons-grid">
        @for (person of persons(); track person.id) {
          <article class="k-card person-card" [class.inactive]="!person.is_active"
                   [attr.aria-label]="person.name + (person.is_active ? '' : ' — nieaktywna')" >
            <div class="person-header">
              <div class="person-avatar" aria-hidden="true">
                {{ initials(person.name) }}
              </div>
              <div>
                <h2 class="person-name">{{ person.name }}</h2>
                <p class="text-muted text-sm">{{ person.relation }}</p>
              </div>
              @if (!person.is_active) {
                <span class="status-badge cancelled" aria-label="Upoważnienie nieaktywne">
                  Nieaktywna
                </span>
              }
            </div>

            <dl class="person-details">
              @if (person.phone) {
                <div class="detail-row">
                  <dt>
                    <span class="material-symbols-outlined" aria-hidden="true">phone</span>
                    Telefon
                  </dt>
                  <dd>
                    <a [href]="'tel:' + person.phone" [attr.aria-label]="'Zadzwoń do ' + person.name">
                      {{ person.phone }}
                    </a>
                  </dd>
                </div>
              }
              @if (person.email) {
                <div class="detail-row">
                  <dt>
                    <span class="material-symbols-outlined" aria-hidden="true">mail</span>
                    E-mail
                  </dt>
                  <dd>
                    <a [href]="'mailto:' + person.email" [attr.aria-label]="'Napisz do ' + person.name">
                      {{ person.email }}
                    </a>
                  </dd>
                </div>
              }
              <div class="detail-row">
                <dt>
                  <span class="material-symbols-outlined" aria-hidden="true">calendar_today</span>
                  Dodano
                </dt>
                <dd>{{ person.created_at | date:'d MMM yyyy':'':\'pl\' }}</dd>
              </div>
            </dl>
          </article>
        }
      </div>
    }
  `,
  styles: [`
    .persons-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 1rem;
    }

    .person-card {
      display: flex;
      flex-direction: column;
      gap: 1rem;

      &.inactive { opacity: .6; }
    }

    .person-header { display: flex; align-items: flex-start; gap: .875rem; }

    .person-avatar {
      width: 3rem; height: 3rem;
      border-radius: 50%;
      background: linear-gradient(135deg, #3b82f6, #1d4ed8);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 1rem;
      flex-shrink: 0;
    }

    .person-name { font-size: 1rem; font-weight: 600; margin: 0 0 .15rem; }

    .person-details {
      margin: 0;
      display: flex;
      flex-direction: column;
      gap: .5rem;
    }

    .detail-row {
      display: flex;
      gap: .75rem;
      align-items: center;
      font-size: .875rem;

      dt {
        display: flex;
        align-items: center;
        gap: .3rem;
        color: rgba(255,255,255,.45);
        min-width: 80px;
        font-weight: normal;
        font-size: .8rem;

        .material-symbols-outlined { font-size: .95rem; }
      }

      dd {
        margin: 0;

        a { color: #93c5fd; text-decoration: none; }
        a:hover { text-decoration: underline; }
      }
    }
  `],
})
export class UpowaznienComponent implements OnInit {
  private api = inject(KursantApiService);

  loading = signal(true);
  persons = signal<AuthorizedPerson[]>([]);

  ngOnInit(): void {
    this.api.getAuthorized().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.persons.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  initials(name: string): string {
    const parts = name.trim().split(' ');
    return parts.length >= 2
      ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
      : name.slice(0, 2).toUpperCase();
  }
}
