import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatExpansionModule } from '@angular/material/expansion';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { DydGroup, Homework } from '../../core/models/kursant.models';

@Component({
  selector: 'app-zadania',
  standalone: true,
  imports: [
    CommonModule, DatePipe, ReactiveFormsModule,
    MatButtonModule, MatFormFieldModule, MatInputModule,
    MatExpansionModule, MatSnackBarModule,
  ],
  template: `
    <div aria-live="polite" class="sr-only">
      @if (loading()) { Ładowanie zadań… }
      @if (submitMsg()) { {{ submitMsg() }} }
    </div>

    <div class="page-header">
      <h1>Dydaktyka / eLearning</h1>
      <p class="subtitle">Materiały, zadania domowe i oceny</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie zadań">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading() && groups().length === 0) {
      <div class="k-card">
        <div class="empty-state">
          <span class="material-symbols-outlined empty-icon" aria-hidden="true">assignment</span>
          <p>Brak zadań i materiałów dydaktycznych.</p>
        </div>
      </div>
    }

    <mat-accordion multi>
      @for (group of groups(); track group.session_id) {
        <mat-expansion-panel class="session-panel">
          <mat-expansion-panel-header>
            <mat-panel-title>
              <span class="material-symbols-outlined" aria-hidden="true" style="margin-right:.5rem">event</span>
              {{ group.session_date | date:'d MMMM yyyy':'':\'pl\' }} — {{ group.course_name }}
            </mat-panel-title>
            <mat-panel-description>
              @if (group.materials.length > 0) {
                <span class="panel-badge">{{ group.materials.length }} mat.</span>
              }
              @if (pendingCount(group) > 0) {
                <span class="panel-badge warning">{{ pendingCount(group) }} do zrobienia</span>
              }
            </mat-panel-description>
          </mat-expansion-panel-header>

          <!-- Materials -->
          @if (group.materials.length > 0) {
            <section aria-label="Materiały z lekcji">
              <h2 class="section-label">
                <span class="material-symbols-outlined" aria-hidden="true">attachment</span>
                Materiały
              </h2>
              <ul role="list" class="material-list">
                @for (mat of group.materials; track mat.id) {
                  <li role="listitem" class="material-item">
                    <span class="material-symbols-outlined" aria-hidden="true">description</span>
                    <a [href]="mat.file_url"
                       target="_blank"
                       rel="noopener noreferrer"
                       [attr.aria-label]="mat.title + ' — pobierz plik, nowa karta'">
                      {{ mat.title }}
                    </a>
                    <span class="text-muted text-sm">{{ mat.type }}</span>
                  </li>
                }
              </ul>
            </section>
          }

          <!-- Homeworks -->
          @if (group.homeworks.length > 0) {
            <section aria-label="Zadania domowe">
              <h2 class="section-label" style="margin-top:1rem">
                <span class="material-symbols-outlined" aria-hidden="true">edit_note</span>
                Zadania domowe
              </h2>
              @for (hw of group.homeworks; track hw.id) {
                <article class="hw-card" [class.hw-done]="hw.status !== 'pending'">
                  <div class="hw-header">
                    <strong>{{ hw.title }}</strong>
                    <span class="status-badge" [class]="hw.status">{{ hwStatusLabel(hw.status) }}</span>
                  </div>
                  <p class="hw-desc text-sm text-muted">{{ hw.description }}</p>
                  @if (hw.due_date) {
                    <p class="hw-due text-sm">
                      <span class="material-symbols-outlined" aria-hidden="true" style="font-size:.95rem">schedule</span>
                      Termin: <strong>{{ hw.due_date | date:'d MMM yyyy' }}</strong>
                    </p>
                  }

                  @if (hw.status === 'graded') {
                    <div class="hw-grade k-alert success">
                      <span class="material-symbols-outlined" aria-hidden="true">grade</span>
                      <div>
                        <strong>Ocena: {{ hw.grade }}</strong>
                        @if (hw.feedback) { <p class="mb-0 text-sm">{{ hw.feedback }}</p> }
                      </div>
                    </div>
                  }

                  @if (hw.status === 'submitted') {
                    <div class="k-alert info">
                      <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
                      Oddano {{ hw.submitted_at | date:'d MMM yyyy, HH:mm' }}
                    </div>
                  }

                  @if (hw.status === 'pending') {
                    <details class="hw-submit-details">
                      <summary class="hw-submit-toggle" role="button">
                        <span class="material-symbols-outlined" aria-hidden="true">upload</span>
                        Oddaj zadanie
                      </summary>
                      <form class="hw-submit-form"
                            [formGroup]="submitForms[hw.id]"
                            (ngSubmit)="submitHomework(hw)">
                        <mat-form-field appearance="fill" style="width:100%">
                          <mat-label>Treść odpowiedzi</mat-label>
                          <textarea matInput
                                    formControlName="body"
                                    rows="4"
                                    [attr.aria-required]="true"
                                    [id]="'hw-body-' + hw.id"
                                    [attr.aria-describedby]="'hw-body-hint-' + hw.id"></textarea>
                          <mat-hint [id]="'hw-body-hint-' + hw.id">
                            Możesz też dołączyć plik poniżej.
                          </mat-hint>
                        </mat-form-field>

                        <div class="file-input-wrap">
                          <label [for]="'hw-file-' + hw.id" class="file-label">
                            <span class="material-symbols-outlined" aria-hidden="true">attach_file</span>
                            Załącz plik (opcjonalnie)
                          </label>
                          <input type="file"
                                 [id]="'hw-file-' + hw.id"
                                 (change)="onFileChange($event, hw.id)"
                                 class="file-input"
                                 accept=".pdf,.doc,.docx,.txt,.zip">
                          @if (selectedFiles[hw.id]) {
                            <span class="text-sm" aria-live="polite">
                              Plik: {{ selectedFiles[hw.id].name }}
                            </span>
                          }
                        </div>

                        <button mat-flat-button
                                type="submit"
                                [disabled]="submitting[hw.id]"
                                [attr.aria-busy]="submitting[hw.id]">
                          Wyślij zadanie
                        </button>
                      </form>
                    </details>
                  }
                </article>
              }
            </section>
          }
        </mat-expansion-panel>
      }
    </mat-accordion>
  `,
  styles: [`
    .session-panel { background: #f8fafc !important; margin-bottom: .5rem !important; }

    .panel-badge {
      display: inline-flex;
      align-items: center;
      padding: .15rem .5rem;
      border-radius: 2rem;
      font-size: .75rem;
      background: #f3f4f6;
      color: #374151;
      margin-right: .4rem;

      &.warning { background: #fffbeb; color: #92400e; }
    }

    .section-label {
      display: flex;
      align-items: center;
      gap: .4rem;
      font-size: .85rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #374151;
      margin-bottom: .75rem;
      font-weight: 600;
    }

    .material-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: .5rem; }

    .material-item {
      display: flex;
      align-items: center;
      gap: .5rem;
      font-size: .9rem;

      .material-symbols-outlined { font-size: 1rem; color: #9ca3af; }
      a { color: #1d4ed8; }
    }

    .hw-card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: .5rem;
      padding: 1rem;
      margin-bottom: .75rem;

      &.hw-done { opacity: .75; }
    }

    .hw-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: .5rem; }
    .hw-desc { margin: .25rem 0; }
    .hw-due  { display: flex; align-items: center; gap: .25rem; margin: .5rem 0; }
    .hw-grade { margin-top: .75rem; }

    .hw-submit-details { margin-top: 1rem; }
    .hw-submit-toggle {
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: .4rem;
      color: #2563eb;
      font-size: .875rem;
      font-weight: 500;
      list-style: none;
      padding: .25rem 0;

      &:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; border-radius: 3px; }
      &::-webkit-details-marker { display: none; }
    }

    .hw-submit-form { margin-top: 1rem; display: flex; flex-direction: column; gap: .75rem; }

    .file-input-wrap {
      display: flex;
      align-items: center;
      gap: .75rem;
      flex-wrap: wrap;
    }

    .file-label {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: .4rem .875rem;
      border: 1px solid #d1d5db;
      border-radius: .4rem;
      color: #374151;
      font-size: .875rem;
      cursor: pointer;
      transition: border-color .15s, color .15s;

      &:hover { border-color: #2563eb; color: #2563eb; }
    }

    .file-input { position: absolute; opacity: 0; width: 0; height: 0; }
  `],
})
export class ZadaniaComponent implements OnInit {
  private api   = inject(KursantApiService);
  private snack = inject(MatSnackBar);
  private fb    = inject(FormBuilder);

  loading    = signal(true);
  groups     = signal<DydGroup[]>([]);
  submitMsg  = signal<string | null>(null);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  submitForms: Record<number, FormGroup<any>> = {};
  submitting: Record<number, boolean> = {};
  selectedFiles: Record<number, File> = {};

  ngOnInit(): void {
    this.api.getHomework().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) {
          this.groups.set(res.data);
          res.data.forEach(g =>
            g.homeworks.forEach(hw => {
              this.submitForms[hw.id] = this.fb.nonNullable.group({ body: [''] });
              this.submitting[hw.id] = false;
            })
          );
        }
      },
      error: () => this.loading.set(false),
    });
  }

  pendingCount(group: DydGroup): number {
    return group.homeworks.filter(hw => hw.status === 'pending').length;
  }

  hwStatusLabel(s: string): string {
    const labels: Record<string, string> = {
      pending: 'Do zrobienia', submitted: 'Oddane', graded: 'Ocenione',
    };
    return labels[s] ?? s;
  }

  onFileChange(event: Event, hwId: number): void {
    const input = event.target as HTMLInputElement;
    const file  = input.files?.[0];
    if (file) this.selectedFiles[hwId] = file;
  }

  submitHomework(hw: Homework): void {
    const fg   = this.submitForms[hw.id];
    const body = fg.getRawValue().body;
    const file = this.selectedFiles[hw.id];

    if (!body && !file) {
      this.snack.open('Dodaj treść odpowiedzi lub plik.', 'OK', { duration: 3000 });
      return;
    }

    this.submitting[hw.id] = true;

    this.api.submitHomework(hw.id, body, file).subscribe({
      next: res => {
        this.submitting[hw.id] = false;
        if (res.success) {
          this.groups.update(gs =>
            gs.map(g => ({
              ...g,
              homeworks: g.homeworks.map(h =>
                h.id === hw.id ? { ...h, status: 'submitted' as const, submitted_at: new Date().toISOString() } : h
              ),
            }))
          );
          this.submitMsg.set('Zadanie zostało wysłane.');
          this.snack.open('Zadanie wysłane!', 'OK', { duration: 4000 });
        }
      },
      error: () => {
        this.submitting[hw.id] = false;
        this.snack.open('Błąd wysyłania zadania.', 'OK', { duration: 4000 });
      },
    });
  }
}
