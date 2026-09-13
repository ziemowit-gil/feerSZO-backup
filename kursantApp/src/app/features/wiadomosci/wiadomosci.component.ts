import { Component, signal, computed, inject, OnInit, ElementRef, ViewChild, AfterViewChecked } from '@angular/core';
import { CommonModule, DatePipe, SlicePipe } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { AppDataService } from '../../core/services/app-data.service';
import { Message } from '../../core/models/kursant.models';

interface Thread {
  subject: string;
  messages: Message[];
  lastAt: string;
  lastPreview: string;
  unreadCount: number;
}

/**
 * Wiadomości pogrupowane w wątki po temacie (`subject` w k30_ti_messages) —
 * wcześniej wszystkie wiadomości (niezależnie od tematu) renderowały się jako
 * jeden ciągły czat, mimo że backend od początku niósł pole `subject` per
 * wiadomość. Układ master-detail bez modali (spójny z resztą panelu), obie
 * kolumny zawsze w DOM — na wąskim ekranie po prostu piętrzą się pionowo.
 */
@Component({
  selector: 'app-wiadomosci',
  standalone: true,
  imports: [
    CommonModule, DatePipe, SlicePipe, ReactiveFormsModule,
    MatButtonModule, MatFormFieldModule, MatInputModule, MatSnackBarModule,
  ],
  template: `
    <div aria-live="polite" class="sr-only">
      @if (loading()) { Ładowanie wiadomości… }
      @if (sent()) { Wiadomość wysłana. }
      @if (liveAnnouncement()) { {{ liveAnnouncement() }} }
    </div>

    <div class="page-header">
      <h1>Wiadomości</h1>
      <p class="subtitle">Korespondencja z prowadzącym, pogrupowana w wątki</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie wiadomości">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      <div class="wm-layout">
        <!-- Lista wątków -->
        <nav class="wm-list k-card" aria-label="Lista wątków wiadomości">
          <div class="wm-list-header">
            <h2 class="k-card-title" style="margin:0">Wątki</h2>
            <button mat-stroked-button type="button" (click)="startNewThread()">
              <span class="material-symbols-outlined" aria-hidden="true">add</span>
              Nowy temat
            </button>
          </div>

          @if (threads().length === 0) {
            <p class="text-muted text-sm" style="margin-top:.75rem">Brak wiadomości — zacznij od nowego tematu.</p>
          }

          <ul role="list" class="wm-thread-list">
            @for (t of threads(); track t.subject) {
              <li role="listitem">
                <button type="button"
                        class="wm-thread-item"
                        [class.active]="mode() === 'thread' && t.subject === selectedSubject()"
                        [attr.aria-current]="mode() === 'thread' && t.subject === selectedSubject() ? 'true' : null"
                        [attr.aria-label]="t.subject + (t.unreadCount > 0 ? ', ' + t.unreadCount + ' nieprzeczytanych' : '')"
                        (click)="selectThread(t.subject)">
                  <span class="wm-thread-subject">{{ t.subject }}</span>
                  <span class="wm-thread-preview text-muted text-sm">{{ t.lastPreview | slice:0:70 }}</span>
                  <span class="wm-thread-meta text-sm text-muted">{{ t.lastAt | date:'d MMM, HH:mm':'':\'pl\' }}</span>
                  @if (t.unreadCount > 0) {
                    <span class="notif-badge" aria-hidden="true">{{ t.unreadCount }}</span>
                  }
                </button>
              </li>
            }
          </ul>
        </nav>

        <!-- Szczegóły wątku / nowy temat -->
        <section class="wm-detail k-card" aria-labelledby="wm-detail-heading">
          @if (error()) {
            <div class="k-alert danger" role="alert">{{ error() }}</div>
          }

          @if (mode() === 'new') {
            <h2 id="wm-detail-heading" class="k-card-title">
              <span class="material-symbols-outlined" aria-hidden="true">edit</span>
              Nowy temat
            </h2>

            <form [formGroup]="newForm" (ngSubmit)="sendNewThread()" novalidate>
              <mat-form-field appearance="fill" style="width:100%;margin-bottom:.75rem">
                <mat-label>Temat</mat-label>
                <input matInput formControlName="subject" id="msg-subject" maxlength="200"
                       [attr.aria-required]="true"
                       [attr.aria-describedby]="newForm.controls.subject.invalid && newForm.controls.subject.touched ? 'subject-err' : null">
                @if (newForm.controls.subject.invalid && newForm.controls.subject.touched) {
                  <mat-error id="subject-err">Temat jest wymagany</mat-error>
                }
              </mat-form-field>

              <mat-form-field appearance="fill" style="width:100%;margin-bottom:.75rem">
                <mat-label>Treść wiadomości</mat-label>
                <textarea matInput formControlName="body" id="msg-body" rows="5" maxlength="4000"
                          [attr.aria-required]="true"
                          [attr.aria-describedby]="newForm.controls.body.invalid && newForm.controls.body.touched ? 'body-err' : null"></textarea>
                <mat-hint align="end">{{ newForm.value.body?.length ?? 0 }}/4000</mat-hint>
                @if (newForm.controls.body.invalid && newForm.controls.body.touched) {
                  <mat-error id="body-err">Treść wiadomości jest wymagana</mat-error>
                }
              </mat-form-field>

              <div class="wm-form-actions">
                <button mat-flat-button type="submit" [disabled]="sending()" [attr.aria-busy]="sending()">
                  <span class="material-symbols-outlined" aria-hidden="true">send</span>
                  Wyślij
                </button>
                @if (threads().length > 0) {
                  <button mat-button type="button" (click)="cancelNewThread()">Anuluj</button>
                }
              </div>
            </form>
          } @else {
          @if (selectedThread(); as t) {
            <h2 id="wm-detail-heading" class="k-card-title">{{ t.subject }}</h2>

            <div class="message-thread"
                 #threadEl
                 role="log"
                 aria-label="Treść wątku"
                 aria-live="polite"
                 aria-relevant="additions">
              @for (msg of t.messages; track msg.id) {
                <div class="msg-bubble" [class]="msg.sender === 'student' ? 'from-student' : 'from-staff'">
                  <div class="msg-meta">
                    <strong>{{ msg.sender_name }}</strong>
                    · {{ msg.created_at | date:'d MMM yyyy, HH:mm':'':\'pl\' }}
                  </div>
                  <p class="msg-body">{{ msg.body }}</p>
                </div>
              }
            </div>

            <form [formGroup]="replyForm" (ngSubmit)="sendReply(t.subject)" novalidate class="wm-reply-form">
              <mat-form-field appearance="fill" style="width:100%">
                <mat-label>Odpowiedz w tym wątku</mat-label>
                <textarea matInput formControlName="body" id="reply-body" rows="3" maxlength="4000"
                          [attr.aria-required]="true"
                          [attr.aria-describedby]="replyForm.controls.body.invalid && replyForm.controls.body.touched ? 'reply-body-err' : null"></textarea>
                @if (replyForm.controls.body.invalid && replyForm.controls.body.touched) {
                  <mat-error id="reply-body-err">Treść odpowiedzi jest wymagana</mat-error>
                }
              </mat-form-field>
              <button mat-flat-button type="submit" [disabled]="sending()" [attr.aria-busy]="sending()">
                <span class="material-symbols-outlined" aria-hidden="true">send</span>
                Wyślij odpowiedź
              </button>
            </form>
          } @else {
            <div class="empty-state">
              <span class="material-symbols-outlined empty-icon" aria-hidden="true">mail_outline</span>
              <p>Wybierz wątek z listy albo rozpocznij nowy temat.</p>
            </div>
          }
          }
        </section>
      </div>
    }
  `,
  styles: [`
    .wm-layout {
      display: grid;
      grid-template-columns: minmax(240px, 320px) 1fr;
      gap: 1rem;
      align-items: start;

      @media (max-width: 800px) { grid-template-columns: 1fr; }
    }

    .wm-list-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .5rem;
      flex-wrap: wrap;
    }

    .wm-thread-list {
      list-style: none;
      margin: .75rem 0 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: .35rem;
      max-height: 60vh;
      overflow-y: auto;
    }

    .wm-thread-item {
      display: grid;
      grid-template-columns: 1fr auto;
      grid-template-areas: "subject badge" "preview preview" "meta meta";
      width: 100%;
      text-align: left;
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: .5rem;
      padding: .6rem .75rem;
      cursor: pointer;
      gap: .1rem .5rem;

      &:hover, &:focus-visible { border-color: #2563eb; }
      &.active { background: #eff6ff; border-color: #2563eb; }
    }

    .wm-thread-subject { grid-area: subject; font-weight: 600; font-size: .9rem; color: #111827; }
    .wm-thread-preview { grid-area: preview; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .wm-thread-meta    { grid-area: meta; }
    .wm-thread-item .notif-badge { grid-area: badge; position: static; }

    .message-thread {
      display: flex;
      flex-direction: column;
      gap: .75rem;
      max-height: 50vh;
      overflow-y: auto;
      padding: .25rem .25rem 1rem;
      margin-bottom: 1rem;
    }

    .msg-bubble {
      padding: .6rem .875rem;
      border-radius: .6rem;
      max-width: 85%;

      &.from-staff   { background: #f3f4f6; align-self: flex-start; }
      &.from-student { background: #eff6ff; align-self: flex-end; }
    }

    .msg-meta { font-size: .78rem; color: #6b7280; margin-bottom: .25rem; }
    .msg-body { margin: 0; white-space: pre-wrap; word-break: break-word; }

    .wm-form-actions { display: flex; gap: .5rem; align-items: center; }
    .wm-reply-form { border-top: 1px solid #e5e7eb; padding-top: 1rem; }
  `],
})
export class WiadomosciComponent implements OnInit, AfterViewChecked {
  @ViewChild('threadEl') threadEl?: ElementRef<HTMLDivElement>;

  private api     = inject(KursantApiService);
  private appData = inject(AppDataService);
  private snack   = inject(MatSnackBar);
  private fb      = inject(FormBuilder);

  newForm = this.fb.nonNullable.group({
    subject: ['', [Validators.required, Validators.minLength(2)]],
    body:    ['', [Validators.required, Validators.minLength(5)]],
  });
  replyForm = this.fb.nonNullable.group({
    body: ['', [Validators.required, Validators.minLength(1)]],
  });

  loading   = signal(true);
  sending   = signal(false);
  sent      = signal(false);
  error     = signal<string | null>(null);
  messages  = signal<Message[]>([]);
  mode      = signal<'thread' | 'new'>('thread');
  selectedSubject   = signal<string | null>(null);
  liveAnnouncement  = signal('');

  private shouldScroll = false;

  threads = computed<Thread[]>(() => {
    const bySubject = new Map<string, Message[]>();
    for (const m of this.messages()) {
      const key = (m.subject ?? '').trim() || '(bez tematu)';
      if (!bySubject.has(key)) bySubject.set(key, []);
      bySubject.get(key)!.push(m);
    }
    const list = Array.from(bySubject, ([subject, msgs]) => {
      const sorted = [...msgs].sort((a, b) => a.created_at.localeCompare(b.created_at));
      const last = sorted[sorted.length - 1];
      return {
        subject,
        messages: sorted,
        lastAt: last.created_at,
        lastPreview: last.body,
        unreadCount: sorted.filter(m => !m.is_read && m.sender === 'staff').length,
      };
    });
    return list.sort((a, b) => b.lastAt.localeCompare(a.lastAt));
  });

  selectedThread = computed(() => this.threads().find(t => t.subject === this.selectedSubject()) ?? null);

  ngOnInit(): void {
    this.api.getMessages().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) {
          this.messages.set(res.data);
          const threads = this.threads();
          if (threads.length > 0) {
            const withUnread = threads.find(t => t.unreadCount > 0);
            this.selectThread((withUnread ?? threads[0]).subject);
          } else {
            this.mode.set('new');
          }
        }
      },
      error: () => this.loading.set(false),
    });
  }

  ngAfterViewChecked(): void {
    if (this.shouldScroll) {
      this.shouldScroll = false;
      if (this.threadEl?.nativeElement) {
        this.threadEl.nativeElement.scrollTop = this.threadEl.nativeElement.scrollHeight;
      }
    }
  }

  selectThread(subject: string): void {
    this.mode.set('thread');
    this.selectedSubject.set(subject);
    this.replyForm.reset();
    this.shouldScroll = true;
    this.liveAnnouncement.set(`Otwarto wątek: ${subject}`);

    const thread = this.threads().find(t => t.subject === subject);
    if (thread && thread.unreadCount > 0) {
      this.api.markMessagesRead().subscribe(() => {
        this.messages.update(list =>
          list.map(m => m.sender === 'staff' ? { ...m, is_read: true } : m)
        );
        this.appData.refresh(); // odśwież licznik nieprzeczytanych w shellu
      });
    }
  }

  startNewThread(): void {
    this.mode.set('new');
    this.error.set(null);
    this.newForm.reset();
  }

  cancelNewThread(): void {
    const threads = this.threads();
    if (threads.length > 0) this.selectThread(this.selectedSubject() ?? threads[0].subject);
  }

  sendNewThread(): void {
    this.newForm.markAllAsTouched();
    if (this.newForm.invalid || this.sending()) return;

    const { subject, body } = this.newForm.getRawValue();
    this.sending.set(true);
    this.error.set(null);

    this.api.sendMessage(subject, body).subscribe({
      next: res => {
        this.sending.set(false);
        if (res.success) {
          this.appendLocalMessage(subject, body);
          this.newForm.reset();
          this.sent.set(true);
          this.snack.open('Wiadomość wysłana!', 'OK', { duration: 4000 });
          this.selectThread(subject);
          setTimeout(() => this.sent.set(false), 100);
        } else {
          this.error.set(res.error ?? 'Błąd wysyłania.');
        }
      },
      error: () => { this.sending.set(false); this.error.set('Błąd połączenia.'); },
    });
  }

  sendReply(subject: string): void {
    this.replyForm.markAllAsTouched();
    if (this.replyForm.invalid || this.sending()) return;

    const { body } = this.replyForm.getRawValue();
    this.sending.set(true);
    this.error.set(null);

    this.api.sendMessage(subject, body).subscribe({
      next: res => {
        this.sending.set(false);
        if (res.success) {
          this.appendLocalMessage(subject, body);
          this.replyForm.reset();
          this.sent.set(true);
          this.shouldScroll = true;
          this.snack.open('Wiadomość wysłana!', 'OK', { duration: 4000 });
          setTimeout(() => this.sent.set(false), 100);
        } else {
          this.error.set(res.error ?? 'Błąd wysyłania.');
        }
      },
      error: () => { this.sending.set(false); this.error.set('Błąd połączenia.'); },
    });
  }

  private appendLocalMessage(subject: string, body: string): void {
    const newMsg: Message = {
      id: Date.now(),
      sender: 'student',
      sender_name: 'Ty',
      subject,
      body,
      created_at: new Date().toISOString(),
      is_read: true,
    };
    this.messages.update(list => [...list, newMsg]);
  }
}
