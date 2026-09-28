import { Component, signal, inject, OnInit, computed } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { A11yModule } from '@angular/cdk/a11y';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { Lesson, LessonStatus } from '../../core/models/kursant.models';

// Etykiety zgodne z K30_TI_SESSION_STATUSES (includes/karty30.php) tam, gdzie
// status pochodzi wprost z k30_ti_sessions.status — ten sam status ma
// wyglądać tak samo w panelu kursanta i w panelu dydaktyka.
const STATUS_LABELS: Record<LessonStatus, string> = {
  planned:           'Zaplanowana',
  held:              'Odbyta',
  cancelled:         'Odwołana',
  excused:           'Usprawiedliwiona',
  absence:           'Nieobecność',
  remote_material:   'Praca własna',
  individual_change: 'Zajęcia indywidualne',
  reserved:          'Rezerwacja',
  draft:             'Wersja robocza',
};

@Component({
  selector: 'app-lekcje',
  standalone: true,
  imports: [CommonModule, DatePipe, ReactiveFormsModule, MatButtonModule, MatSnackBarModule, MatFormFieldModule, MatInputModule, A11yModule],
  template: `
    <div aria-live="polite" class="sr-only">
      @if (loading()) { Ładowanie lekcji… }
    </div>

    <div class="page-header">
      <h1 id="lekcje-heading">Moje lekcje</h1>
      <p class="subtitle">Historia i nadchodzące zajęcia</p>
    </div>

    <!-- Filters -->
    <div class="k-card" role="search" aria-label="Filtruj lekcje">
      <div class="filter-row">
        <label for="filter-status" class="sr-only">Status lekcji</label>
        <select id="filter-status"
                class="k-select"
                [value]="filterStatus()"
                (change)="filterStatus.set($any($event.target).value)">
          <option value="">Wszystkie statusy</option>
          <option value="planned">Zaplanowane</option>
          <option value="held">Odbyte</option>
          <option value="cancelled">Odwołane</option>
          <option value="excused">Usprawiedliwione</option>
          <option value="absence">Nieobecności</option>
          <option value="remote_material">Praca własna</option>
        </select>
        <span class="filter-count text-muted text-sm" aria-live="polite" aria-atomic="true">
          {{ filtered().length }} lekcji
        </span>
      </div>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie lekcji">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading() && filtered().length === 0) {
      <div class="k-card" role="status">
        <div class="empty-state">
          <span class="material-symbols-outlined empty-icon" aria-hidden="true">calendar_today</span>
          <p>Brak lekcji spełniających kryteria.</p>
        </div>
      </div>
    }

    @if (!loading() && filtered().length > 0) {
      @if (upcoming().length > 0) {
        <section aria-labelledby="lekcje-upcoming-heading">
          <h2 id="lekcje-upcoming-heading" class="section-heading">Nadchodzące zajęcia</h2>
          <div class="k-table-wrap k-table-card">
            <table class="k-table" aria-label="Nadchodzące zajęcia">
              <thead><ng-container *ngTemplateOutlet="headRowTpl"></ng-container></thead>
              <tbody>
                @for (lesson of upcoming(); track lesson.id) {
                  <ng-container *ngTemplateOutlet="rowTpl; context: { $implicit: lesson }"></ng-container>
                }
              </tbody>
            </table>
          </div>
        </section>
      }
      @if (history().length > 0) {
        <section aria-labelledby="lekcje-history-heading" [style.margin-top]="upcoming().length > 0 ? '1.75rem' : '0'">
          <h2 id="lekcje-history-heading" class="section-heading">Historia</h2>
          <div class="k-table-wrap k-table-card">
            <table class="k-table" aria-label="Historia lekcji">
              <thead><ng-container *ngTemplateOutlet="headRowTpl"></ng-container></thead>
              <tbody>
                @for (lesson of history(); track lesson.id) {
                  <ng-container *ngTemplateOutlet="rowTpl; context: { $implicit: lesson }"></ng-container>
                }
              </tbody>
            </table>
          </div>
        </section>
      }
    }

    <ng-template #headRowTpl>
      <tr>
        <th scope="col">Data</th>
        <th scope="col">Kurs</th>
        <th scope="col">Godzina</th>
        <th scope="col"><abbr title="Liczba godzin — każda rozpoczęta godzina liczona jako pełna">Godz.</abbr></th>
        <th scope="col">Prowadzący</th>
        <th scope="col">Sala</th>
        <th scope="col">Status</th>
        <th scope="col">Akcje</th>
      </tr>
    </ng-template>

    <ng-template #rowTpl let-lesson>
      <tr>
        <td>
          <span>{{ lesson.date | date:'d MMM yyyy':'':\'pl\' }}</span>
          @if (lesson.meeting_url) {
            <a [href]="lesson.meeting_url"
               target="_blank"
               rel="noopener noreferrer"
               class="online-badge"
               aria-label="Dołącz do lekcji online — nowa karta">
              <span class="material-symbols-outlined" aria-hidden="true">videocam</span>
            </a>
          }
        </td>
        <td>{{ lesson.course_name }}</td>
        <td>{{ lesson.time_from }}–{{ lesson.time_to }}</td>
        <td>
          {{ lesson.hours ?? '—' }}
          @if (lesson.hours_counted === 0 && isPastHeld(lesson)) {
            <span class="text-muted text-sm" title="Nie wliczona do rozliczenia (nieobecność lub odwołanie)">(0)</span>
          }
        </td>
        <td>{{ lesson.instructor_name }}</td>
        <td>{{ lesson.room_name ?? '—' }}</td>
        <td>
          <span class="status-badge" [class]="lesson.status">
            {{ statusLabel(lesson.status) }}
          </span>
        </td>
        <td>
          <div class="action-cell">
            @if (lesson.status === 'held') {
              <button mat-stroked-button
                      class="btn-small stars-btn"
                      [class.rated]="!!lesson.rating"
                      [attr.aria-label]="lesson.rating
                        ? ('Zmień ocenę lekcji z ' + lesson.date + ', obecna ocena: ' + lesson.rating + ' na 5')
                        : ('Oceń lekcję z ' + lesson.date)"
                      (click)="openRatingDialog(lesson)">
                @if (lesson.rating) {
                  <span class="stars-display" aria-hidden="true">
                    @for (i of [1,2,3,4,5]; track i) {
                      <span class="material-symbols-outlined star" [class.filled]="i <= lesson.rating">star</span>
                    }
                  </span>
                } @else {
                  Oceń
                }
              </button>
            }
            @if (canCancel(lesson)) {
              <button mat-stroked-button
                      class="btn-small btn-danger"
                      [attr.aria-label]="'Odwołaj lekcję z ' + lesson.date"
                      (click)="cancelLesson(lesson)">
                Odwołaj
              </button>
            } @else if (lesson.status === 'planned' && lesson.cancel_requested) {
              <button mat-stroked-button
                      class="btn-small"
                      [attr.aria-label]="'Cofnij prośbę odwołania lekcji z ' + lesson.date"
                      (click)="uncancelLesson(lesson)">
                Cofnij
              </button>
            } @else if (lesson.status !== 'held') {
              <span aria-hidden="true">—</span>
            }
          </div>
        </td>
      </tr>
    </ng-template>

    <!-- Rating dialog (inline panel) -->
    @if (ratingLesson()) {
      <div class="rating-overlay" role="dialog"
           aria-modal="true"
           [attr.aria-label]="'Oceń lekcję ' + ratingLesson()?.date"
           (keydown.escape)="closeRating()">
        <div class="rating-panel k-card" cdkTrapFocus cdkTrapFocusAutoCapture>
          <h2 class="k-card-title">
            <span class="material-symbols-outlined" aria-hidden="true">star</span>
            {{ ratingLesson()?.rating ? 'Zmień ocenę lekcji' : 'Oceń lekcję' }}
          </h2>
          <p class="text-muted text-sm">{{ ratingLesson()?.course_name }} — {{ ratingLesson()?.date }}</p>

          <div class="stars-input" role="group" aria-label="Wybierz ocenę od 1 do 5 gwiazdek">
            @for (i of [1,2,3,4,5]; track i) {
              <button type="button"
                      class="star-btn"
                      [class.active]="i <= (hoverRating() || selectedRating())"
                      [attr.aria-label]="i + ' gwiazdek'"
                      [attr.aria-pressed]="i === selectedRating()"
                      (mouseover)="hoverRating.set(i)"
                      (mouseout)="hoverRating.set(0)"
                      (click)="selectedRating.set(i)">
                <span class="material-symbols-outlined" aria-hidden="true">star</span>
              </button>
            }
          </div>

          <mat-form-field appearance="fill" style="width:100%;margin-top:.75rem">
            <mat-label>Komentarz (opcjonalnie)</mat-label>
            <textarea matInput
                      [formControl]="ratingComment"
                      rows="3"
                      maxlength="500"
                      aria-label="Komentarz do oceny lekcji"></textarea>
          </mat-form-field>

          <div style="display:flex;gap:.75rem;margin-top:.5rem">
            <button mat-flat-button
                    (click)="submitRating()"
                    [disabled]="!selectedRating()"
                    aria-label="Zapisz ocenę">
              Zapisz
            </button>
            <button mat-stroked-button (click)="closeRating()">Anuluj</button>
          </div>
        </div>
      </div>
    }
  `,
  styles: [`
    .filter-row {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .k-select {
      background: #f8fafc;
      border: 1px solid #d1d5db;
      border-radius: .5rem;
      color: #111827;
      padding: .5rem .875rem;
      font-size: .875rem;
      cursor: pointer;

      &:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }

      option { background: #fff; }
    }

    .online-badge {
      display: inline-flex;
      align-items: center;
      color: #1d4ed8;
      margin-left: .4rem;
      .material-symbols-outlined { font-size: 1rem; }
    }

    .stars-display { display: inline-flex; gap: 2px; }
    .star { font-size: 1rem; color: #d1d5db; }
    .star.filled { color: #f59e0b; }

    .btn-small {
      font-size: .78rem !important;
      padding: .25rem .625rem !important;
      height: auto !important;
      min-height: 0 !important;
      line-height: 1.5 !important;
    }

    .btn-danger { color: #b91c1c !important; border-color: #fca5a5 !important; }

    .stars-btn.rated { padding: .25rem .5rem !important; }

    .action-cell { display: flex; align-items: center; gap: .5rem; flex-wrap: nowrap; white-space: nowrap; }

    .section-heading {
      font-size: 1.05rem;
      font-weight: 600;
      margin: 0 0 .75rem;
      color: var(--c-text, #111827);
    }

    .k-table-card {
      background: var(--c-surface, #fff);
      border: 1px solid var(--c-border, #e5e7eb);
      border-radius: .875rem;
    }

    .rating-overlay {
      position: fixed;
      inset: 0;
      z-index: 400;
      background: rgba(0,0,0,.45);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }

    .rating-panel { max-width: 420px; width: 100%; }

    .stars-input {
      display: flex;
      gap: .5rem;
      margin-top: .75rem;
    }

    .star-btn {
      background: none;
      border: none;
      cursor: pointer;
      padding: .25rem;
      color: #d1d5db;
      transition: color .15s;
      border-radius: .25rem;

      .material-symbols-outlined { font-size: 2rem; }
      &.active { color: #f59e0b; }
      &:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
    }
  `],
})
export class LekcjeComponent implements OnInit {
  private api     = inject(KursantApiService);
  private snack   = inject(MatSnackBar);
  private fb      = inject(FormBuilder);

  loading      = signal(true);
  lessons      = signal<Lesson[]>([]);
  filterStatus = signal('');
  ratingLesson = signal<Lesson | null>(null);
  selectedRating = signal(0);
  hoverRating    = signal(0);
  ratingComment  = this.fb.control('');

  filtered = computed(() => {
    const s = this.filterStatus();
    const list = this.lessons();
    return s ? list.filter(l => l.status === s) : list;
  });

  // Nadchodzące — najbliższe na górze; historia — najświeższe na górze.
  upcoming = computed(() => {
    const now = Date.now();
    return this.filtered()
      .filter(l => this.lessonTime(l) >= now)
      .sort((a, b) => this.lessonTime(a) - this.lessonTime(b));
  });

  history = computed(() => {
    const now = Date.now();
    return this.filtered()
      .filter(l => this.lessonTime(l) < now)
      .sort((a, b) => this.lessonTime(b) - this.lessonTime(a));
  });

  ngOnInit(): void {
    this.api.getLessons().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.lessons.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  /** Lekcja odbyta — wtedy 0 godzin do rozliczenia jest warte pokazania. */
  isPastHeld(lesson: Lesson): boolean {
    return ['held', 'individual_change', 'remote_material'].includes(lesson.status as string);
  }

  statusLabel(s: LessonStatus): string { return STATUS_LABELS[s] ?? s; }

  private lessonTime(lesson: Lesson): number {
    return new Date(`${lesson.date}T${lesson.time_from || '00:00'}`).getTime();
  }

  canCancel(lesson: Lesson): boolean {
    return lesson.status === 'planned'
      && !lesson.cancel_requested
      && this.lessonTime(lesson) > Date.now();
  }

  cancelLesson(lesson: Lesson): void {
    this.api.cancelLesson(lesson.id).subscribe({
      next: res => {
        if (res.success) {
          this.lessons.update(list =>
            list.map(l => l.id === lesson.id ? { ...l, cancel_requested: true } : l)
          );
          this.snack.open('Prośba o odwołanie lekcji wysłana.', 'OK', { duration: 4000 });
        }
      },
    });
  }

  uncancelLesson(lesson: Lesson): void {
    this.api.uncancelLesson(lesson.id).subscribe({
      next: res => {
        if (res.success) {
          this.lessons.update(list =>
            list.map(l => l.id === lesson.id ? { ...l, cancel_requested: false } : l)
          );
          this.snack.open('Prośba o odwołanie cofnięta.', 'OK', { duration: 4000 });
        }
      },
    });
  }

  openRatingDialog(lesson: Lesson): void {
    this.ratingLesson.set(lesson);
    this.selectedRating.set(lesson.rating ?? 0);
    this.hoverRating.set(0);
    this.ratingComment.reset();
  }

  closeRating(): void { this.ratingLesson.set(null); }

  submitRating(): void {
    const lesson  = this.ratingLesson();
    const rating  = this.selectedRating();
    const comment = this.ratingComment.value ?? '';
    if (!lesson || !rating) return;

    this.api.rateLesson(lesson.id, rating, comment).subscribe({
      next: res => {
        if (res.success) {
          this.lessons.update(list =>
            list.map(l => l.id === lesson.id ? { ...l, rating } : l)
          );
          this.closeRating();
          this.snack.open('Ocena zapisana. Dziękujemy!', 'OK', { duration: 4000 });
        }
      },
    });
  }
}
