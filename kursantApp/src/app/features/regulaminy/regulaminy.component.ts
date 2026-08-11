import { Component, signal, inject, OnInit, computed } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { Term } from '../../core/models/kursant.models';

@Component({
  selector: 'app-regulaminy',
  standalone: true,
  imports: [CommonModule, DatePipe, MatButtonModule, MatSnackBarModule],
  template: `
    <div aria-live="polite" class="sr-only">
      @if (loading()) { Ładowanie regulaminów… }
      @if (acceptedMsg()) { {{ acceptedMsg() }} }
    </div>

    <div class="page-header">
      <h1>Regulaminy</h1>
      <p class="subtitle">Dokumenty wymagające akceptacji</p>
    </div>

    @if (pendingCount() > 0) {
      <div class="k-alert warning" role="status">
        <span class="material-symbols-outlined" aria-hidden="true">warning</span>
        <strong>{{ pendingCount() }}</strong> regulamin{{ pendingCount() > 1 ? 'ów' : '' }}
        oczekuje na Twoją akceptację.
      </div>
    }

    @if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading() && terms().length === 0) {
      <div class="k-card">
        <div class="empty-state">
          <span class="material-symbols-outlined empty-icon" aria-hidden="true">gavel</span>
          <p>Brak regulaminów.</p>
        </div>
      </div>
    }

    <ul role="list" style="list-style:none;padding:0;margin:0;">
      @for (term of terms(); track term.id) {
        <li role="listitem">
          <article class="k-card term-card" [class.pending]="!term.is_accepted && term.required">
            <div class="term-header">
              <div>
                <div class="term-title-row">
                  @if (term.required && !term.is_accepted) {
                    <span class="required-dot" aria-label="Wymagane"></span>
                  }
                  <h2 class="term-title">{{ term.title }}</h2>
                </div>
                <p class="text-muted text-sm">
                  Wersja {{ term.version }}
                  @if (term.is_accepted && term.accepted_at) {
                    · Zaakceptowano {{ term.accepted_at | date:'d MMM yyyy':'':\'pl\' }}
                  }
                </p>
              </div>
              <div class="term-actions">
                @if (term.file_url) {
                  <a [href]="term.file_url"
                     target="_blank" rel="noopener noreferrer"
                     class="pdf-link"
                     [attr.aria-label]="'Pobierz regulamin ' + term.title + ' (PDF), nowa karta'">
                    <span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>
                    Pobierz PDF
                  </a>
                }
              </div>
            </div>

            @if (!term.is_accepted) {
              <div class="accept-section">
                <p class="text-sm text-muted" style="margin:0 0 .75rem">
                  Zaakceptuj regulamin, aby potwierdzić zapoznanie się z jego treścią.
                </p>
                <button mat-flat-button
                        [disabled]="accepting[term.id]"
                        [attr.aria-busy]="accepting[term.id]"
                        [attr.aria-label]="'Akceptuję regulamin: ' + term.title"
                        (click)="accept(term)">
                  <span class="material-symbols-outlined" aria-hidden="true">check</span>
                  Akceptuję
                </button>
              </div>
            } @else {
              <div class="accepted-badge">
                <span class="material-symbols-outlined" aria-hidden="true">verified</span>
                Zaakceptowano
              </div>
            }
          </article>
        </li>
      }
    </ul>
  `,
  styles: [`
    .term-card {
      border-left: 3px solid transparent;
      &.pending { border-left-color: #d97706; }
    }

    .term-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: .75rem;
      margin-bottom: .75rem;
    }

    .term-title-row { display: flex; align-items: center; gap: .5rem; }

    .required-dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      background: #d97706;
      flex-shrink: 0;
    }

    .term-title { font-size: 1rem; font-weight: 600; margin: 0 0 .2rem; color: #111827; }

    .pdf-link {
      display: inline-flex;
      align-items: center;
      gap: .3rem;
      color: #1d4ed8;
      text-decoration: none;
      font-size: .875rem;
      padding: .3rem .625rem;
      border-radius: .4rem;

      &:hover { background: #eff6ff; }
      .material-symbols-outlined { font-size: 1rem; }
    }

    .accept-section { margin-top: .5rem; }

    .accepted-badge {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      color: #15803d;
      font-size: .875rem;
      font-weight: 500;

      .material-symbols-outlined { font-size: 1.1rem; }
    }
  `],
})
export class RegulaminyComponent implements OnInit {
  private api   = inject(KursantApiService);
  private snack = inject(MatSnackBar);

  loading     = signal(true);
  terms       = signal<Term[]>([]);
  acceptedMsg = signal<string | null>(null);
  accepting: Record<number, boolean> = {};

  pendingCount = computed(() => this.terms().filter(t => !t.is_accepted && t.required).length);

  ngOnInit(): void {
    this.api.getTerms().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) {
          this.terms.set(res.data);
          res.data.forEach(t => (this.accepting[t.id] = false));
        }
      },
      error: () => this.loading.set(false),
    });
  }

  accept(term: Term): void {
    this.accepting[term.id] = true;
    this.api.acceptTerm(term.id).subscribe({
      next: res => {
        this.accepting[term.id] = false;
        if (res.success) {
          this.terms.update(list =>
            list.map(t =>
              t.id === term.id
                ? { ...t, is_accepted: true, accepted_at: new Date().toISOString() }
                : t
            )
          );
          const msg = `Zaakceptowano: ${term.title}`;
          this.acceptedMsg.set(msg);
          this.snack.open(msg, 'OK', { duration: 4000 });
        }
      },
      error: () => {
        this.accepting[term.id] = false;
        this.snack.open('Błąd akceptacji regulaminu.', 'OK', { duration: 4000 });
      },
    });
  }
}
