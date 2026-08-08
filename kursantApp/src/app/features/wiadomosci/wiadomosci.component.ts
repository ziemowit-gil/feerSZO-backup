import { Component, signal, inject, OnInit, ElementRef, ViewChild, AfterViewInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { Message } from '../../core/models/kursant.models';

@Component({
  selector: 'app-wiadomosci',
  standalone: true,
  imports: [
    CommonModule, DatePipe, ReactiveFormsModule,
    MatButtonModule, MatFormFieldModule, MatInputModule, MatSnackBarModule,
  ],
  template: `
    <div aria-live="polite" class="sr-only">
      @if (loading()) { Ładowanie wiadomości… }
      @if (sent()) { Wiadomość wysłana. }
    </div>

    <div class="page-header">
      <h1>Wiadomości</h1>
      <p class="subtitle">Korespondencja z prowadzącym</p>
    </div>

    <!-- Compose form -->
    <section class="k-card" aria-labelledby="compose-heading">
      <h2 class="k-card-title" id="compose-heading">
        <span class="material-symbols-outlined" aria-hidden="true">edit</span>
        Nowa wiadomość
      </h2>

      @if (error()) {
        <div class="k-alert danger" role="alert">{{ error() }}</div>
      }

      <form [formGroup]="form" (ngSubmit)="sendMessage()" novalidate>
        <mat-form-field appearance="fill" style="width:100%;margin-bottom:.75rem">
          <mat-label>Temat</mat-label>
          <input matInput
                 formControlName="subject"
                 id="msg-subject"
                 maxlength="200"
                 [attr.aria-required]="true"
                 [attr.aria-describedby]="form.controls.subject.invalid && form.controls.subject.touched ? 'subject-err' : null">
          @if (form.controls.subject.invalid && form.controls.subject.touched) {
            <mat-error id="subject-err">Temat jest wymagany</mat-error>
          }
        </mat-form-field>

        <mat-form-field appearance="fill" style="width:100%;margin-bottom:.75rem">
          <mat-label>Treść wiadomości</mat-label>
          <textarea matInput
                    formControlName="body"
                    id="msg-body"
                    rows="5"
                    maxlength="4000"
                    [attr.aria-required]="true"
                    [attr.aria-describedby]="form.controls.body.invalid && form.controls.body.touched ? 'body-err' : null"></textarea>
          <mat-hint align="end">{{ form.value.body?.length ?? 0 }}/4000</mat-hint>
          @if (form.controls.body.invalid && form.controls.body.touched) {
            <mat-error id="body-err">Treść wiadomości jest wymagana</mat-error>
          }
        </mat-form-field>

        <button mat-flat-button
                type="submit"
                [disabled]="sending()"
                [attr.aria-busy]="sending()">
          <span class="material-symbols-outlined" aria-hidden="true">send</span>
          Wyślij
        </button>
      </form>
    </section>

    <!-- Thread -->
    <section aria-labelledby="thread-heading">
      <h2 id="thread-heading" class="page-header" style="margin-bottom:1rem">
        <span style="font-size:1.1rem;font-weight:600">Historia korespondencji</span>
      </h2>

      @if (loading()) {
        <div class="loading-overlay" role="status" aria-label="Ładowanie wiadomości">
          <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
          <span>Ładowanie…</span>
        </div>
      }

      @if (!loading() && messages().length === 0) {
        <div class="k-card">
          <div class="empty-state">
            <span class="material-symbols-outlined empty-icon" aria-hidden="true">mail_outline</span>
            <p>Brak wiadomości.</p>
          </div>
        </div>
      }

      @if (!loading() && messages().length > 0) {
        <div class="message-thread"
             #threadEl
             role="log"
             aria-label="Wątek wiadomości"
             aria-live="polite"
             aria-relevant="additions">
          @for (msg of messages(); track msg.id) {
            <div class="msg-bubble" [class]="msg.sender === 'student' ? 'from-student' : 'from-staff'">
              <div class="msg-meta">
                <strong>{{ msg.sender_name }}</strong>
                · {{ msg.created_at | date:'d MMM yyyy, HH:mm':'':\'pl\' }}
                @if (!msg.is_read && msg.sender === 'staff') {
                  <span class="unread-indicator" aria-label="Nieprzeczytana"></span>
                }
              </div>
              <p class="msg-subject">{{ msg.subject }}</p>
              <p class="msg-body">{{ msg.body }}</p>
            </div>
          }
        </div>
      }
    </section>
  `,
  styles: [`
    .message-thread {
      display: flex;
      flex-direction: column;
      gap: .75rem;
      padding-bottom: 2rem;
    }

    .msg-subject {
      font-weight: 600;
      font-size: .875rem;
      margin: 0 0 .35rem;
      opacity: .8;
    }

    .msg-body {
      margin: 0;
      white-space: pre-wrap;
      word-break: break-word;
    }

    .unread-indicator {
      display: inline-block;
      width: 7px; height: 7px;
      border-radius: 50%;
      background: #e05a1e;
      margin-left: .35rem;
      vertical-align: middle;
    }
  `],
})
export class WiadomosciComponent implements OnInit, AfterViewInit {
  @ViewChild('threadEl') threadEl?: ElementRef<HTMLDivElement>;

  private api   = inject(KursantApiService);
  private snack = inject(MatSnackBar);
  private fb    = inject(FormBuilder);

  form = this.fb.nonNullable.group({
    subject: ['', [Validators.required, Validators.minLength(2)]],
    body:    ['', [Validators.required, Validators.minLength(5)]],
  });

  loading  = signal(true);
  sending  = signal(false);
  sent     = signal(false);
  error    = signal<string | null>(null);
  messages = signal<Message[]>([]);

  ngOnInit(): void {
    this.api.getMessages().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) {
          this.messages.set(res.data);
          this.api.markMessagesRead().subscribe();
        }
      },
      error: () => this.loading.set(false),
    });
  }

  ngAfterViewInit(): void {
    this.scrollToBottom();
  }

  private scrollToBottom(): void {
    setTimeout(() => {
      if (this.threadEl?.nativeElement) {
        this.threadEl.nativeElement.scrollTop = this.threadEl.nativeElement.scrollHeight;
      }
    }, 100);
  }

  sendMessage(): void {
    this.form.markAllAsTouched();
    if (this.form.invalid || this.sending()) return;

    const { subject, body } = this.form.getRawValue();
    this.sending.set(true);
    this.error.set(null);

    this.api.sendMessage(subject, body).subscribe({
      next: res => {
        this.sending.set(false);
        if (res.success) {
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
          this.form.reset();
          this.sent.set(true);
          this.snack.open('Wiadomość wysłana!', 'OK', { duration: 4000 });
          setTimeout(() => { this.sent.set(false); this.scrollToBottom(); }, 100);
        } else {
          this.error.set(res.error ?? 'Błąd wysyłania.');
        }
      },
      error: () => {
        this.sending.set(false);
        this.error.set('Błąd połączenia.');
      },
    });
  }
}
