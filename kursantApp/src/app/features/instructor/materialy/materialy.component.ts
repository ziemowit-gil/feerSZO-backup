import { Component, signal, computed, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorMaterial } from '../../../core/models/kursant.models';

/**
 * Materiały prowadzącego — odpowiednik karty30/ti/dydaktyk/_tab_materialy.php,
 * wyłącznie widok listy (podgląd, pobranie załącznika, link). Dodawanie/edycja/
 * usuwanie materiału zostaje na razie w klasycznym panelu — kolejny krok migracji.
 */
@Component({
  selector: 'app-instructor-materialy',
  standalone: true,
  imports: [CommonModule, DatePipe, FormsModule, MatButtonModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie materiałów… }</div>

    <div class="page-header">
      <h1>Materiały</h1>
      <p class="subtitle">Materiały / eLearning Twoich kursów</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie materiałów">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      @if (courses().length > 1) {
        <div class="course-filter">
          <label for="course-select">Grupa</label>
          <select id="course-select" [(ngModel)]="courseFilter">
            <option [ngValue]="null">— wszystkie grupy —</option>
            @for (c of courses(); track c.id) { <option [ngValue]="c.id">{{ c.name }}</option> }
          </select>
        </div>
      }

      @if (filteredMaterials().length === 0) {
        <div class="k-card">
          <div class="empty-state">
            <span class="material-symbols-outlined empty-icon" aria-hidden="true">collections_bookmark</span>
            <p>Brak materiałów.</p>
          </div>
        </div>
      } @else {
        @for (m of filteredMaterials(); track m.id) {
          <div class="k-card mat-card" [class.mat-inactive]="!m.is_active">
            <div class="mat-header">
              <div class="mat-title-row">
                <span class="type-badge">{{ m.type }}</span>
                <h2 class="mat-title">{{ m.title }}</h2>
                @if (!m.is_active) { <span class="status-badge">ukryte</span> }
                <span class="status-badge" [class.upcoming]="m.availability.state === 'upcoming'"
                      [class.closed]="m.availability.state === 'closed'">{{ m.availability.label }}</span>
              </div>
              <span class="text-muted text-sm">{{ m.course_name }}</span>
            </div>
            @if (m.session_date) { <p class="text-muted text-sm mat-session">Lekcja: {{ m.session_date | date:'d.MM.yyyy' }}@if (m.session_topic) {<span> — {{ m.session_topic }}</span>}</p> }
            @if (m.description) { <p class="mat-desc">{{ m.description }}</p> }

            <div class="mat-footer">
              @if (m.has_file) {
                <a mat-stroked-button class="btn-small" [href]="fileUrl(m.id)" target="_blank" rel="noopener">
                  <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">download</span>
                  {{ m.attach_name || 'Pobierz' }}
                </a>
              }
              @if (m.url) {
                <a mat-stroked-button class="btn-small" [href]="m.url" target="_blank" rel="noopener">
                  <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">open_in_new</span>
                  Otwórz link
                </a>
              }
            </div>
          </div>
        }
      }
    }
  `,
  styles: [`
    .course-filter { display: flex; align-items: center; gap: .5rem; margin-bottom: 1rem;
      label { font-size: .85rem; color: var(--c-text-muted); }
      select { padding: .4rem .6rem; border: 1px solid var(--c-border-2); border-radius: .5rem; font-size: .85rem; }
    }
    .mat-card { &.mat-inactive { opacity: .6; } }
    .mat-title-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .mat-title { font-size: 1.05rem; margin: 0; }
    .type-badge { background: var(--c-surface-2); color: var(--c-text-muted); border-radius: .4rem; padding: .1rem .5rem; font-size: .75rem; text-transform: capitalize; }
    .mat-session, .mat-desc { margin: .5rem 0 0; font-size: .9rem; }
    .mat-footer { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; padding-top: .75rem; border-top: 1px solid var(--c-border); }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; display: inline-flex !important; align-items: center; gap: .3rem; }
    .status-badge.upcoming { background: var(--c-warning-bg); color: var(--c-warning); }
    .status-badge.closed { background: var(--c-border); color: var(--c-text-muted); }
  `],
})
export class InstructorMaterialyComponent implements OnInit {
  private api = inject(InstructorApiService);

  loading      = signal(true);
  materials    = signal<InstructorMaterial[]>([]);
  courseFilter = signal<number | null>(null);

  courses = computed(() => {
    const map = new Map<number, string>();
    for (const m of this.materials()) map.set(m.course_id, m.course_name);
    return Array.from(map, ([id, name]) => ({ id, name }));
  });

  filteredMaterials = computed(() => {
    const cid = this.courseFilter();
    const all = this.materials();
    return cid ? all.filter(m => m.course_id === cid) : all;
  });

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.getMaterials().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.materials.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  fileUrl(id: number): string {
    return this.api.materialFileUrl(id);
  }
}
