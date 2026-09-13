import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorHelpdeskTicket, HD_CATEGORIES, HD_PRIORITIES, HD_STATUSES } from '../../../core/models/kursant.models';
import { HelpdeskTicketDialogComponent } from './helpdesk-ticket-dialog.component';

const ACTIVE_STATUSES = new Set(['rozwiązane', 'zamknięte']);

/**
 * Helpdesk IT — zgłoszenia własne prowadzącego, odpowiednik
 * karty30/ti/dydaktyk/api_helpdesk.php (tam: przycisk w Wiadomościach →
 * "Nowa wiadomość" do Helpdesk; tu: osobna zakładka z listą własnych
 * zgłoszeń + nowym zgłoszeniem w oknie modalnym). Zgłoszenie ląduje w tym
 * samym module Helpdesk (helpdesk_tickets, source='dydaktyk') — pełna obsługa
 * (odpowiedzi, eskalacje, SLA) zostaje w module Helpdesk/panelu operatorów.
 */
@Component({
  selector: 'app-instructor-helpdesk',
  standalone: true,
  imports: [CommonModule, DatePipe, MatButtonModule, MatDialogModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie zgłoszeń… }</div>

    <div class="page-header">
      <h1>Helpdesk</h1>
      <p class="subtitle">Twoje zgłoszenia do działu IT</p>
      <button mat-flat-button type="button" class="add-btn" (click)="startNew()">
        <span class="material-symbols-outlined" aria-hidden="true">add</span>
        Nowe zgłoszenie
      </button>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      @if (tickets().length === 0) {
        <div class="k-card">
          <div class="empty-state">
            <span class="material-symbols-outlined empty-icon" aria-hidden="true">support_agent</span>
            <p>Brak zgłoszeń.</p>
          </div>
        </div>
      } @else {
        @for (t of tickets(); track t.id) {
          <div class="k-card ticket-card" [class.ticket-closed]="isClosed(t)">
            <div class="ticket-header">
              <div>
                <span class="ticket-number">{{ t.number }}</span>
                <h2 class="ticket-title">{{ t.title }}</h2>
              </div>
              <span class="status-badge" [class.closed]="isClosed(t)">{{ statusLabels[t.status] || t.status }}</span>
            </div>
            <div class="ticket-meta text-muted text-sm">
              <span>{{ categoryLabels[t.category] || t.category }}</span>
              <span>·</span>
              <span>Priorytet: {{ priorityLabels[t.priority] || t.priority }}</span>
              <span>·</span>
              <span>Zgłoszono: {{ t.created_at | date:'d.MM.yyyy HH:mm' }}</span>
            </div>
          </div>
        }
      }
    }
  `,
  styles: [`
    .page-header { position: relative; }
    .add-btn { position: absolute; top: 0; right: 0; }
    .ticket-card { &.ticket-closed { opacity: .65; } }
    .ticket-header { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; margin-bottom: .5rem; }
    .ticket-number { font-size: .78rem; color: var(--c-text-muted); font-weight: 600; letter-spacing: .03em; }
    .ticket-title { font-size: 1.05rem; margin: .15rem 0 0; }
    .status-badge.closed { background: var(--c-success-bg, #dcfce7); color: var(--c-success, #15803d); }
    .ticket-meta { display: flex; gap: .4rem; flex-wrap: wrap; }
  `],
})
export class InstructorHelpdeskComponent implements OnInit {
  private api    = inject(InstructorApiService);
  private dialog = inject(MatDialog);

  readonly categoryLabels = HD_CATEGORIES;
  readonly priorityLabels = HD_PRIORITIES;
  readonly statusLabels   = HD_STATUSES;

  loading = signal(true);
  tickets = signal<InstructorHelpdeskTicket[]>([]);

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.getHelpdeskTickets().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.tickets.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  isClosed(t: InstructorHelpdeskTicket): boolean {
    return ACTIVE_STATUSES.has(t.status);
  }

  startNew(): void {
    this.dialog.open(HelpdeskTicketDialogComponent, {
      width: '560px', maxWidth: '95vw',
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }
}
