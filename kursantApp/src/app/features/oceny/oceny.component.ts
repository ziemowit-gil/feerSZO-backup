import { Component, signal, inject, OnInit, computed } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { GradesByCourse, Grade } from '../../core/models/kursant.models';

@Component({
  selector: 'app-oceny',
  standalone: true,
  imports: [CommonModule, DatePipe],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie ocen… }</div>

    <div class="page-header">
      <h1>Oceny</h1>
      <p class="subtitle">Dziennik ocen ze wszystkich kursów</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie ocen">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading() && courses().length === 0) {
      <div class="k-card">
        <div class="empty-state">
          <span class="material-symbols-outlined empty-icon" aria-hidden="true">grade</span>
          <p>Brak ocen do wyświetlenia.</p>
        </div>
      </div>
    }

    @for (course of courses(); track course.course_id) {
      <section class="k-card" [attr.aria-labelledby]="'course-' + course.course_id + '-heading'">
        <div class="course-header">
          <h2 class="k-card-title" id="course-{{ course.course_id }}-heading">
            <span class="material-symbols-outlined" aria-hidden="true">school</span>
            {{ course.course_name }}
          </h2>
          @if (course.average !== null) {
            <div class="average-chip"
                 [attr.aria-label]="'Średnia: ' + (course.average | number:'1.2-2')">
              <span class="material-symbols-outlined" aria-hidden="true">calculate</span>
              Średnia: <strong>{{ course.average | number:'1.2-2' }}</strong>
            </div>
          }
        </div>

        @if (course.grades.length === 0) {
          <p class="text-muted text-sm">Brak ocen z tego kursu.</p>
        } @else {
          <div class="k-table-wrap">
            <table class="k-table" [attr.aria-label]="'Oceny z kursu ' + course.course_name">
              <thead>
                <tr>
                  <th scope="col">Data</th>
                  <th scope="col">Rodzaj</th>
                  <th scope="col">Ocena</th>
                  <th scope="col">Waga</th>
                  <th scope="col">Komentarz</th>
                </tr>
              </thead>
              <tbody>
                @for (grade of course.grades; track grade.id) {
                  <tr>
                    <td>{{ grade.date | date:'d MMM yyyy':'':\'pl\' }}</td>
                    <td>{{ grade.type }}</td>
                    <td>
                      <span class="grade-chip" [class]="gradeClass(grade.value)"
                            [attr.aria-label]="'Ocena: ' + grade.value">
                        {{ grade.value }}
                      </span>
                    </td>
                    <td>{{ grade.weight }}</td>
                    <td class="text-muted">{{ grade.comment ?? '—' }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      </section>
    }
  `,
  styles: [`
    .course-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: .75rem;
      margin-bottom: 1rem;
    }

    .average-chip {
      display: flex;
      align-items: center;
      gap: .35rem;
      background: rgba(255,255,255,.06);
      border-radius: .5rem;
      padding: .3rem .75rem;
      font-size: .875rem;
      color: rgba(255,255,255,.7);

      .material-symbols-outlined { font-size: 1rem; }
    }
  `],
})
export class OcenyComponent implements OnInit {
  private api = inject(KursantApiService);

  loading = signal(true);
  courses = signal<GradesByCourse[]>([]);

  ngOnInit(): void {
    this.api.getGrades().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.courses.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  gradeClass(value: string): string {
    const n = parseFloat(value.replace('+', '.5').replace('-', '.3'));
    if (n >= 4.5) return 'high';
    if (n >= 3)   return 'medium';
    return 'low';
  }
}
