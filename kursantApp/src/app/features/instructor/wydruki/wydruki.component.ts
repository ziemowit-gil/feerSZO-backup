import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';

/**
 * Wydruki prowadzącego — odpowiednik wydruków dostępnych zwykłemu (nie-
 * kierownikowi) prowadzącemu w klasycznym panelu: plan_librus_instructor_pdf.php
 * (plan zajęć — siatka) i attendance_csv.php (eksport frekwencji). Katalog
 * wydruków kierownika (wydruki.php/raporty.php — dyd_is_staff()) zostaje
 * wyłącznie w klasycznym panelu — to funkcje kierownika, nie prowadzącego.
 */
@Component({
  selector: 'app-instructor-wydruki',
  standalone: true,
  imports: [CommonModule, FormsModule, MatButtonModule],
  template: `
    <div class="page-header">
      <h1>Wydruki</h1>
      <p class="subtitle">Twoje plany i raporty do pobrania</p>
    </div>

    <div class="k-card report-card">
      <h2><span class="material-symbols-outlined" aria-hidden="true">calendar_view_week</span>Plan zajęć (siatka)</h2>
      <p class="text-muted text-sm">Twój plan zajęć w formie siatki tygodniowej, PDF.</p>
      <div class="report-form">
        <label for="plan-weeks">Zakres (tygodnie)</label>
        <select id="plan-weeks" [(ngModel)]="planWeeks">
          @for (w of weekOptions; track w) { <option [ngValue]="w">{{ w }} tyg.</option> }
        </select>
        <a mat-flat-button [href]="planUrl()" target="_blank" rel="noopener">
          <span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>
          Pobierz PDF
        </a>
      </div>
    </div>

    <div class="k-card report-card">
      <h2><span class="material-symbols-outlined" aria-hidden="true">fact_check</span>Frekwencja</h2>
      <p class="text-muted text-sm">Eksport CSV frekwencji (do Excela) za wybrany miesiąc — wszystkie Twoje grupy albo jedna wybrana w topbarze.</p>
      <div class="report-form">
        <label for="att-month">Miesiąc</label>
        <input id="att-month" type="month" [(ngModel)]="attMonth">
        <a mat-flat-button [href]="attendanceUrl()" target="_blank" rel="noopener">
          <span class="material-symbols-outlined" aria-hidden="true">table_view</span>
          Pobierz CSV
        </a>
      </div>
      @if (courseCtx.selectedId()) {
        <p class="text-muted text-sm mb-0">Zawęzone do grupy: {{ activeCourseName() }} (wybór w panelu bocznym).</p>
      }
    </div>
  `,
  styles: [`
    .report-card { margin-bottom: 1.25rem; }
    .report-card h2 { display: flex; align-items: center; gap: .5rem; font-size: 1.05rem; margin: 0 0 .5rem;
      .material-symbols-outlined { color: var(--c-primary, #4f46e5); }
    }
    .report-form { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; margin-top: .85rem; }
    .report-form label { font-size: .85rem; color: var(--c-text-muted); }
    .report-form select, .report-form input { padding: .4rem .6rem; border: 1px solid var(--c-border-2); border-radius: .5rem; font-size: .85rem; }
  `],
})
export class InstructorWydrukiComponent {
  private api = inject(InstructorApiService);
  courseCtx   = inject(InstructorCourseContextService);

  readonly weekOptions = [4, 8, 12, 16, 26, 52];
  planWeeks = 12;
  attMonth  = new Date().toISOString().slice(0, 7);

  planUrl(): string {
    return this.api.planPdfUrl(this.planWeeks);
  }

  attendanceUrl(): string {
    return this.api.attendanceCsvUrl(this.attMonth, this.courseCtx.selectedId());
  }

  activeCourseName(): string {
    const id = this.courseCtx.selectedId();
    return this.courseCtx.courses().find(c => c.id === id)?.name ?? '';
  }
}
