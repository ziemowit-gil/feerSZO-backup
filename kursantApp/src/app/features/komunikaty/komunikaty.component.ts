import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { AppDataService } from '../../core/services/app-data.service';
import { Notice } from '../../core/models/kursant.models';

@Component({
  selector: 'app-komunikaty',
  standalone: true,
  imports: [CommonModule, DatePipe, MatButtonModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie komunikatów… }</div>

    <div class="page-header">
      <h1>Komunikaty</h1>
      <p class="subtitle">Ogłoszenia od organizacji</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie komunikatów">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading() && notices().length === 0) {
      <div class="k-card">
        <div class="empty-state">
          <span class="material-symbols-outlined empty-icon" aria-hidden="true">campaign</span>
          <p>Brak komunikatów.</p>
        </div>
      </div>
    }

    <ul role="list" style="list-style:none;padding:0;margin:0;" aria-label="Lista komunikatów">
      @for (notice of notices(); track notice.id) {
        <li role="listitem">
          <article class="k-card notice-card" [class.unread]="!notice.is_read">
            <div class="notice-header">
              <div>
                @if (!notice.is_read) {
                  <span class="unread-dot" role="img" aria-label="Nieprzeczytane"></span>
                }
                <h2 class="notice-title">{{ notice.title }}</h2>
                <p class="text-muted text-sm">
                  {{ notice.created_at | date:'d MMM yyyy, HH:mm':'':\'pl\' }}
                  @if (notice.category) {
                    · <span>{{ notice.category }}</span>
                  }
                </p>
              </div>
              @if (!notice.is_read) {
                <button mat-stroked-button
                        class="btn-read"
                        [attr.aria-label]="'Oznacz jako przeczytane: ' + notice.title"
                        (click)="markRead(notice)">
                  Przeczytano
                </button>
              }
            </div>
            <div class="notice-body" [innerHTML]="safeBody(notice.body)"></div>
          </article>
        </li>
      }
    </ul>
  `,
  styles: [`
    .notice-card {
      position: relative;
      border-left: 3px solid transparent;

      &.unread { border-left-color: #2563eb; }
    }

    .notice-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: .75rem;
      margin-bottom: .75rem;
    }

    .unread-dot {
      display: inline-block;
      width: 8px; height: 8px;
      border-radius: 50%;
      background: #2563eb;
      margin-right: .5rem;
      vertical-align: middle;
    }

    .notice-title { font-size: 1rem; font-weight: 600; margin: 0 0 .2rem; display: inline; color: #111827; }

    .notice-body {
      font-size: .9rem;
      line-height: 1.6;
      color: #374151;

      p { margin: 0 0 .5rem; }
      a { color: #1d4ed8; }
    }

    .btn-read { font-size: .78rem !important; padding: .2rem .6rem !important; height: auto !important; }
  `],
})
export class KomunikatyComponent implements OnInit {
  private api     = inject(KursantApiService);
  private appData = inject(AppDataService);

  loading = signal(true);
  notices = signal<Notice[]>([]);

  ngOnInit(): void {
    this.api.getNotices().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.notices.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  markRead(notice: Notice): void {
    this.api.markNoticeRead(notice.id).subscribe({
      next: () => {
        this.notices.update(list =>
          list.map(n => n.id === notice.id ? { ...n, is_read: true } : n)
        );
        this.appData.refresh(); // odśwież licznik nieprzeczytanych w shellu
      },
    });
  }

  safeBody(body: string): string {
    // Strip dangerous tags — server should also sanitize
    return body.replace(/<script[\s\S]*?<\/script>/gi, '').replace(/on\w+="[^"]*"/g, '');
  }
}
