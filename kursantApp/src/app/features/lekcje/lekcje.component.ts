import { Component, signal, inject, OnInit, computed } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialogModule, MatDialog } from '@angular/material/dialog';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { Lesson, LessonStatus } from '../../core/models/kursant.models';

const STATUS_LABELS: Record<LessonStatus, string> = {
  planned:        'Zaplanowana',
  held:           'Odbyta',
  cancelled:      'Odwołana',
  excused:        'Usprawiedliwiona',
  absence:        'Nieobecność',
  remote_material:'Praca własna',
};

@Component({
  selector: 'app-lekcje',
  standalone: true,
  imports: [CommonModule, DatePipe, ReactiveFormsModule, MatButtonModule, MatSnackBarModule, MatFormFieldModule, MatInputModule],
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
      <section aria-labelledby="lekcje-heading">
        <div class="k-table-wrap">
          <table class="k-table" aria-label="Lista lekcji">
            <thead>
              <tr>
                <th scope="col">Data</th>
                <th scope="col">Kurs</th>
                <th scope="col">Godzina</th>
                <th scope="col">Prowadzący</th>
                <th scope="col">Sala</th>
                <th scope="col">Status</th>
                <th scope="col">Ocena</th>
                <th scope="col"><span class="sr-only">Akcje</span></th>
              </tr>
            </thead>
            <tbody>
              @for (lesson of filtered(); track lesson.id) {
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
                  <td>{{ lesson.instructor_name }}</td>
                  <td>{{ lesson.room_name ?? '—' }}</td>
                  <td>
                    <span class="status-badge" [class]="lesson.status">
                      {{ statusLabel(lesson.status) }}
                    </span>
                  </td>
                  <td>
                    @if (lesson.status === 'held') {
                      @if (lesson.rating) {
                        <div class="stars-display" [attr.aria-label]="'Ocena: ' + lesson.rating + ' na 5'">
                          @for (i of [1,2,3,4,5]; track i) {
                            <span class="material-symbols-outlined star"
                                  [class.filled]="i <= lesson.rating"
                                  aria-hidden="true">star</span>
                          }
                        </div>
                      } @else {
                        <button mat-stroked-button
                                class="btn-small"
                                [attr.aria-label]="'Oceń lekcję z ' + lesson.date"
                                (click)="openRatingDialog(lesson)">
                          Oceń
                        </button>
                      }
                    } @else {
                      <span aria-hidden="true">—</span>
                    }
                  </td>
                  <td>
                    <div class="action-cell">
                      @if (lesson.status === 'planned') {
                        @if (!lesson.cancel_requested) {
                          <button mat-stroked-button
                                  class="btn-small btn-danger"
                                  [attr.aria-label]="'Odwołaj lekcję z ' + lesson.date"
                                  (click)="cancelLesson(lesson)">
                            Odwołaj
                          </button>
                        } @else {
                          <button mat-stroked-button
                                  class="btn-small"
                                  [attr.aria-label]="'Cofnij prośbę odwołania lekcji z ' + lesson.date"
                                  (click)="uncancelLesson(lesson)">
                            Cofnij
                          </button>
                        }
                      }
                    </div>
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      </section>
    }

    <!-- Rating dialog (inline panel) -->
    @if (ratingLesson()) {
      <div class="rating-overlay" role="dialog"
           aria-modal="true"
           [attr.aria-label]="'Oceń lekcję ' + ratingLesson()?.date">
        <div class="rating-panel k-card">
          <h2 class="k-card-title">
            <span class="material-symbols-outlined" aria-hidden="true">star</span>
            Oceń lekcję
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
      background: rgba(255,255,255,.07);
      border: 1px solid rgba(255,255,255,.15);
      border-radius: .5rem;
      color: #e8e8f0;
      padding: .5rem .875rem;
      font-size: .875rem;
      cursor: pointer;

      &:focus-visible { outline: 2px solid #e05a1e; outline-offset: 2px; }

      option { background: #1a1a2e; }
    }

    .online-badge {
      display: inline-flex;
      align-items: center;
      color: #93c5fd;
      margin-left: .4rem;
      .material-symbols-outlined { font-size: 1rem; }
    }

    .stars-display { display: flex; gap: 2px; }
    .star { font-size: 1rem; color: rgba(255,255,255,.2); }
    .star.filled { color: #fbbf24; }

    .btn-small {
      font-size: .78rem !important;
      padding: .25rem .625rem !important;
      height: auto !important;
      min-height: 0 !important;
      line-height: 1.5 !important;
    }

    .btn-danger { color: #fca5a5 !important; border-color: rgba(239,68,68,.3) !important; }

    .action-cell { display: flex; gap: .5rem; }

    .rating-overlay {
      position: fixed;
      inset: 0;
      z-index: 400;
      background: rgba(0,0,0,.6);
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
      color: rgba(255,255,255,.2);
      transition: color .15s;
      border-radius: .25rem;

      .material-symbols-outlined { font-size: 2rem; }
      &.active { color: #fbbf24; }
      &:focus-visible { outline: 2px solid #e05a1e; outline-offset: 2px; }
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

  ngOnInit(): void {
    this.api.getLessons().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.lessons.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  statusLabel(s: LessonStatus): string { return STATUS_LABELS[s] ?? s; }

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
    this.selectedRating.set(0);
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
