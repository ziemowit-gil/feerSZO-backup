import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import {
  InstructorMessageThreads, InstructorStudentThread, InstructorAdminThread,
  InstructorAdminMessage, Message,
} from '../../../core/models/kursant.models';
import { NewMessageDialogComponent } from './new-message-dialog.component';

type ActiveThread = { kind: 'student'; thread: InstructorStudentThread } | { kind: 'admin'; thread: InstructorAdminThread };

/**
 * Wiadomości prowadzącego — odpowiednik karty30/ti/dydaktyk/_tab_wiadomosci.php:
 * wątki z kursantami (k30_ti_messages) i osobno z kierownictwem (k30_ti_admin_msgs).
 * Załączniki, blokowanie kursanta, archiwizacja wiadomości i przekierowanie do
 * Helpdesk IT zostają na razie w klasycznym panelu — kolejny krok migracji.
 */
@Component({
  selector: 'app-instructor-wiadomosci',
  standalone: true,
  imports: [CommonModule, DatePipe, FormsModule, MatButtonModule, MatDialogModule, MatSnackBarModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie wiadomości… }</div>

    <div class="page-header">
      <h1>Wiadomości</h1>
      <p class="subtitle">Rozmowy z kursantami i kierownictwem</p>
      <button mat-flat-button type="button" class="add-btn" (click)="openNewMessage()">
        <span class="material-symbols-outlined" aria-hidden="true">edit_square</span>
        Nowa wiadomość
      </button>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie wiadomości">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading() && data()) {
      <div class="msg-layout">
        <div class="k-card thread-list">
          <h2 class="group-title">
            Kierownictwo
            @if (data()!.admin_unseen_total > 0) { <span class="status-badge danger">{{ data()!.admin_unseen_total }}</span> }
          </h2>
          @if (data()!.admin_threads.length === 0) {
            <p class="text-muted text-sm empty-hint">Brak wiadomości do kierownictwa.</p>
          }
          @for (t of data()!.admin_threads; track t.to_admin_id) {
            <button type="button" class="thread-row" [class.active]="isActiveAdmin(t)" (click)="openAdminThread(t)">
              <span class="thread-name">{{ t.label }}</span>
              <span class="thread-meta">
                @if (t.last_at) { <span class="text-muted text-sm">{{ t.last_at | date:'d.MM HH:mm' }}</span> }
                @if (t.unseen > 0) { <span class="status-badge danger">{{ t.unseen }}</span> }
              </span>
            </button>
          }

          <h2 class="group-title" style="margin-top:1.25rem">Kursanci</h2>
          @if (data()!.student_threads.length === 0) {
            <p class="text-muted text-sm empty-hint">Brak wiadomości od kursantów.</p>
          }
          @for (t of data()!.student_threads; track t.account_id) {
            <button type="button" class="thread-row" [class.active]="isActiveStudent(t)" (click)="openStudentThread(t)">
              <span class="thread-name">{{ t.name }}</span>
              <span class="thread-meta">
                @if (t.last_at) { <span class="text-muted text-sm">{{ t.last_at | date:'d.MM HH:mm' }}</span> }
                @if (t.unread > 0) { <span class="status-badge danger">{{ t.unread }}</span> }
              </span>
            </button>
          }
        </div>

        <div class="k-card thread-detail">
          @if (!active()) {
            <div class="empty-state">
              <span class="material-symbols-outlined empty-icon" aria-hidden="true">forum</span>
              <p>Wybierz wątek z listy.</p>
            </div>
          } @else {
            <h2 class="thread-title">{{ activeLabel() }}</h2>

            @if (threadLoading()) {
              <p class="text-muted text-sm">Ładowanie…</p>
            } @else if (active()!.kind === 'student') {
              <div class="conversation">
                @for (m of studentMessages(); track m.id) {
                  <div class="message" [class.from-me]="m.sender === 'staff'">
                    <div class="message-meta">
                      <strong>{{ m.sender === 'staff' ? m.sender_name : m.sender_name || 'Kursant' }}</strong>
                      <span class="text-muted text-sm">{{ m.created_at | date:'d.MM.yyyy HH:mm' }}</span>
                    </div>
                    @if (m.subject) { <p class="message-subject">{{ m.subject }}</p> }
                    <p class="message-body">{{ m.body }}</p>
                  </div>
                }
              </div>
            } @else {
              <div class="conversation">
                @for (m of adminMessages(); track m.id) {
                  <div class="message from-me">
                    <div class="message-meta">
                      <strong>Ty</strong>
                      <span class="text-muted text-sm">{{ m.created_at | date:'d.MM.yyyy HH:mm' }}</span>
                    </div>
                    @if (m.subject) { <p class="message-subject">{{ m.subject }}</p> }
                    <p class="message-body">{{ m.body }}</p>
                  </div>
                  @if (m.replied_at) {
                    <div class="message">
                      <div class="message-meta">
                        <strong>{{ m.reply_by || 'Kierownictwo' }}</strong>
                        <span class="text-muted text-sm">{{ m.replied_at | date:'d.MM.yyyy HH:mm' }}</span>
                      </div>
                      <p class="message-body">{{ m.reply_body }}</p>
                    </div>
                  }
                }
              </div>
            }

            <form class="reply-form" (ngSubmit)="reply()">
              <textarea [(ngModel)]="replyBody" name="replyBody" rows="3" placeholder="Napisz odpowiedź…"></textarea>
              <button mat-flat-button type="submit" [disabled]="!replyBody.trim() || sending()">Wyślij</button>
            </form>
          }
        </div>
      </div>
    }
  `,
  styles: [`
    .page-header { position: relative; }
    .add-btn { position: absolute; top: 0; right: 0; }
    .msg-layout { display: grid; grid-template-columns: 320px 1fr; gap: 1rem; align-items: start; }
    @media (max-width: 860px) { .msg-layout { grid-template-columns: 1fr; } }

    .group-title { font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; color: var(--c-text-muted); margin: 0 0 .5rem; display: flex; align-items: center; gap: .4rem; }
    .empty-hint { margin: 0 0 .75rem; }
    .thread-row {
      display: flex; align-items: center; justify-content: space-between; gap: .5rem; width: 100%;
      padding: .55rem .6rem; border: none; background: none; border-radius: .5rem; cursor: pointer; text-align: left; font: inherit;
      &:hover { background: var(--c-surface-2); }
      &.active { background: var(--c-primary-bg, #eff6ff); }
    }
    .thread-name { font-weight: 600; font-size: .88rem; }
    .thread-meta { display: flex; align-items: center; gap: .4rem; flex-shrink: 0; }
    .status-badge.danger { background: #fee2e2; color: #b91c1c; border-radius: 1rem; padding: .05rem .5rem; font-size: .72rem; font-weight: 700; }

    .thread-title { margin: 0 0 1rem; font-size: 1.05rem; }
    .conversation { display: flex; flex-direction: column; gap: .75rem; max-height: 50vh; overflow-y: auto; margin-bottom: 1rem; }
    .message { padding: .6rem .75rem; border-radius: .6rem; background: var(--c-surface-2); max-width: 85%; }
    .message.from-me { background: #dbeafe; margin-left: auto; }
    .message-meta { display: flex; justify-content: space-between; gap: .75rem; margin-bottom: .25rem; font-size: .8rem; }
    .message-subject { font-weight: 600; margin: 0 0 .25rem; }
    .message-body { margin: 0; white-space: pre-wrap; font-size: .9rem; }
    .reply-form { display: flex; gap: .5rem; align-items: flex-end; }
    .reply-form textarea { flex: 1; padding: .5rem .6rem; border: 1px solid var(--c-border); border-radius: .5rem; font: inherit; resize: vertical; }
  `],
})
export class InstructorWiadomosciComponent implements OnInit {
  private api    = inject(InstructorApiService);
  private snack  = inject(MatSnackBar);
  private dialog = inject(MatDialog);

  loading = signal(true);
  data    = signal<InstructorMessageThreads | null>(null);

  active         = signal<ActiveThread | null>(null);
  threadLoading  = signal(false);
  studentMessages = signal<Message[]>([]);
  adminMessages    = signal<InstructorAdminMessage[]>([]);
  replyBody = '';
  sending   = signal(false);

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.getMessageThreads().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.data.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  isActiveStudent(t: InstructorStudentThread): boolean {
    const a = this.active();
    return !!a && a.kind === 'student' && a.thread.account_id === t.account_id;
  }
  isActiveAdmin(t: InstructorAdminThread): boolean {
    const a = this.active();
    return !!a && a.kind === 'admin' && a.thread.to_admin_id === t.to_admin_id;
  }

  activeLabel(): string {
    const a = this.active();
    if (!a) return '';
    return a.kind === 'student' ? a.thread.name : a.thread.label;
  }

  openStudentThread(t: InstructorStudentThread): void {
    this.active.set({ kind: 'student', thread: t });
    this.replyBody = '';
    this.threadLoading.set(true);
    this.api.getStudentThread(t.account_id).subscribe({
      next: res => {
        this.threadLoading.set(false);
        if (res.success && res.data) this.studentMessages.set(res.data);
        this.load(); // odśwież liczniki nieprzeczytanych po oznaczeniu jako przeczytane
      },
      error: () => this.threadLoading.set(false),
    });
  }

  openAdminThread(t: InstructorAdminThread): void {
    this.active.set({ kind: 'admin', thread: t });
    this.replyBody = '';
    this.threadLoading.set(true);
    this.api.getAdminThread(t.to_admin_id).subscribe({
      next: res => {
        this.threadLoading.set(false);
        if (res.success && res.data) this.adminMessages.set(res.data);
        this.load();
      },
      error: () => this.threadLoading.set(false),
    });
  }

  reply(): void {
    const a = this.active();
    const body = this.replyBody.trim();
    if (!a || !body) return;
    this.sending.set(true);
    const req$ = a.kind === 'admin'
      ? this.api.sendAdminMessage(a.thread.to_admin_id, body)
      : this.api.sendStudentMessage(a.thread.account_id, body);
    req$.subscribe({
      next: res => {
        this.sending.set(false);
        if (res.success) {
          this.replyBody = '';
          if (a.kind === 'student') this.openStudentThread(a.thread); else this.openAdminThread(a.thread);
        } else {
          this.snack.open(res.message || 'Nie udało się wysłać.', 'OK', { duration: 4000 });
        }
      },
      error: err => {
        this.sending.set(false);
        this.snack.open(err?.error?.error || 'Nie udało się wysłać wiadomości.', 'OK', { duration: 5000 });
      },
    });
  }

  openNewMessage(): void {
    const d = this.data();
    if (!d) return;
    this.dialog.open(NewMessageDialogComponent, {
      width: '520px', maxWidth: '95vw',
      data: { recipients: d.recipients, adminRecipients: d.admin_recipients },
    }).afterClosed().subscribe(sent => { if (sent) this.load(); });
  }
}
