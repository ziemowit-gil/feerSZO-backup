import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { ActivityLogEntry } from '../../core/models/kursant.models';

@Component({
  selector: 'app-aktywnosc',
  standalone: true,
  imports: [CommonModule, DatePipe],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie historii aktywności… }</div>

    <div class="page-header">
      <h1>Aktywność konta</h1>
      <p class="subtitle">Historia logowań i działań na koncie</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading() && log().length === 0) {
      <div class="k-card">
        <div class="empty-state">
          <span class="material-symbols-outlined empty-icon" aria-hidden="true">history</span>
          <p>Brak historii aktywności.</p>
        </div>
      </div>
    }

    @if (!loading() && log().length > 0) {
      <div class="k-card">
        <div class="k-table-wrap">
          <table class="k-table" aria-label="Historia aktywności konta">
            <thead>
              <tr>
                <th scope="col">Data i czas</th>
                <th scope="col">Akcja</th>
                <th scope="col">Opis</th>
                <th scope="col">Adres IP</th>
              </tr>
            </thead>
            <tbody>
              @for (entry of log(); track entry.id) {
                <tr>
                  <td class="text-sm text-muted" style="white-space:nowrap">
                    {{ entry.created_at | date:'d MMM yyyy, HH:mm':'':\'pl\' }}
                  </td>
                  <td>
                    <span class="action-chip">{{ entry.action }}</span>
                  </td>
                  <td>{{ entry.description }}</td>
                  <td class="text-sm text-muted">{{ entry.ip ?? '—' }}</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      </div>
    }
  `,
  styles: [`
    .action-chip {
      display: inline-block;
      padding: .15rem .5rem;
      background: rgba(255,255,255,.07);
      border-radius: .35rem;
      font-size: .8rem;
      font-family: monospace;
      color: rgba(255,255,255,.7);
    }
  `],
})
export class AktywnoscComponent implements OnInit {
  private api = inject(KursantApiService);

  loading = signal(true);
  log     = signal<ActivityLogEntry[]>([]);

  ngOnInit(): void {
    this.api.getActivity().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.log.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }
}
