import { Component, signal, inject, OnInit, computed } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { KursantApiService } from '../../core/services/kursant-api.service';
import { CurriculumItem } from '../../core/models/kursant.models';

interface CourseGroup { course_id: number; course_name: string; items: CurriculumItem[]; progress: number; }

@Component({
  selector: 'app-plan',
  standalone: true,
  imports: [CommonModule, DatePipe],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie planu nauczania… }</div>

    <div class="page-header">
      <h1>Plan nauczania</h1>
      <p class="subtitle">Syllabus i realizacja programu</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie planu">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading() && courseGroups().length === 0) {
      <div class="k-card">
        <div class="empty-state">
          <span class="material-symbols-outlined empty-icon" aria-hidden="true">list_alt</span>
          <p>Brak danych o planie nauczania.</p>
        </div>
      </div>
    }

    @for (group of courseGroups(); track group.course_id) {
      <section class="k-card" [attr.aria-labelledby]="'plan-course-' + group.course_id">
        <div class="plan-course-header">
          <h2 class="k-card-title mt-0" id="plan-course-{{ group.course_id }}">
            <span class="material-symbols-outlined" aria-hidden="true">school</span>
            {{ group.course_name }}
          </h2>
          <span class="text-muted text-sm"
                [attr.aria-label]="'Zrealizowano ' + group.progress + '%'">
            {{ group.progress }}% zrealizowane
          </span>
        </div>

        <div class="progress-wrap" style="margin-bottom:1.25rem"
             role="progressbar"
             [attr.aria-valuenow]="group.progress"
             aria-valuemin="0"
             aria-valuemax="100"
             [attr.aria-label]="group.course_name + ' – postęp ' + group.progress + '%'">
          <div class="progress-fill" [style.width.%]="group.progress"></div>
        </div>

        <ol class="curriculum-list" aria-label="Lista zagadnień">
          @for (item of group.items; track item.id) {
            <li class="curriculum-item" [class.completed]="item.is_completed">
              <span class="item-checkbox"
                    role="img"
                    [attr.aria-label]="item.is_completed ? 'Zrealizowane' : 'Niezrealizowane'">
                <span class="material-symbols-outlined" aria-hidden="true">
                  {{ item.is_completed ? 'check_circle' : 'radio_button_unchecked' }}
                </span>
              </span>
              <div class="item-content">
                <span class="item-title">{{ item.title }}</span>
                @if (item.description) {
                  <span class="item-desc text-muted text-sm">{{ item.description }}</span>
                }
                @if (item.is_completed && item.completed_at) {
                  <span class="item-date text-sm text-muted">
                    Zaliczone: {{ item.completed_at | date:'d MMM yyyy' }}
                  </span>
                }
              </div>
            </li>
          }
        </ol>
      </section>
    }
  `,
  styles: [`
    .plan-course-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: .75rem;
      flex-wrap: wrap;
      gap: .5rem;
    }

    .curriculum-list {
      list-style: none;
      padding: 0;
      margin: 0;
      counter-reset: curriculum;
    }

    .curriculum-item {
      display: flex;
      align-items: flex-start;
      gap: .875rem;
      padding: .75rem 0;
      border-bottom: 1px solid #e5e7eb;
      color: #374151;

      &:last-child { border-bottom: none; }
      &.completed { color: #111827; }
    }

    .item-checkbox {
      flex-shrink: 0;
      margin-top: .1rem;

      .material-symbols-outlined { font-size: 1.2rem; }
    }

    .completed .item-checkbox .material-symbols-outlined { color: #15803d; }

    .item-content {
      display: flex;
      flex-direction: column;
      gap: .15rem;
    }

    .item-title { font-size: .9rem; }
    .item-desc  { }
    .item-date  { }
  `],
})
export class PlanComponent implements OnInit {
  private api = inject(KursantApiService);

  loading     = signal(true);
  items       = signal<CurriculumItem[]>([]);

  courseGroups = computed<CourseGroup[]>(() => {
    const map = new Map<number, CourseGroup>();
    for (const item of this.items()) {
      if (!map.has(item.course_id)) {
        map.set(item.course_id, { course_id: item.course_id, course_name: item.course_name, items: [], progress: 0 });
      }
      map.get(item.course_id)!.items.push(item);
    }
    for (const g of map.values()) {
      const done = g.items.filter(i => i.is_completed).length;
      g.progress = g.items.length > 0 ? Math.round((done / g.items.length) * 100) : 0;
    }
    return [...map.values()];
  });

  ngOnInit(): void {
    this.api.getCurriculum().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.items.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }
}
