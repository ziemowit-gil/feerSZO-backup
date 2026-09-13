import { Component, signal, computed, effect, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';
import { InstructorCurriculumItem } from '../../../core/models/kursant.models';
import { CurriculumItemDialogComponent } from './curriculum-item-dialog.component';

/**
 * Program nauczania / sylabus kursu — odpowiednik _tab_program.php (dawniej
 * "Program zajęć"): CRUD tematów realizowanych w wybranej grupie +
 * kolejność (strzałki góra/dół). Wymaga wyboru JEDNEJ grupy (sylabus jest
 * per-kurs) — bierze aktualnie wybraną z panelu bocznego, z możliwością
 * zmiany lokalnie na tej stronie.
 *
 * Świadomie NIE przeniesione: import CSV, formularz zbiorczy wielu wierszy,
 * podgląd wymagań/kryteriów z sylabusa wzorcowego przedmiotu — drugorzędne
 * ścieżki wobec podstawowego CRUD-u, zostają w klasycznym panelu.
 */
@Component({
  selector: 'app-instructor-program',
  standalone: true,
  imports: [CommonModule, FormsModule, MatButtonModule, MatDialogModule, MatSnackBarModule],
  template: `
    <div class="page-header">
      <h1>Program nauczania</h1>
      <p class="subtitle">Sylabus — tematy realizowane w grupie</p>
      @if (courseId()) {
        <button mat-flat-button type="button" class="add-btn" (click)="startAdd()">
          <span class="material-symbols-outlined" aria-hidden="true">add</span>
          Dodaj pozycję
        </button>
      }
    </div>

    @if (courseCtx.courses().length > 1) {
      <div class="course-filter">
        <label for="course-select">Grupa</label>
        <select id="course-select" [(ngModel)]="localCourseId">
          <option [ngValue]="null">— wybierz grupę —</option>
          @for (c of courseCtx.courses(); track c.id) { <option [ngValue]="c.id">{{ c.name }}</option> }
        </select>
      </div>
    }

    @if (!courseId()) {
      <div class="k-card">
        <div class="empty-state">
          <span class="material-symbols-outlined empty-icon" aria-hidden="true">menu_book</span>
          <p>Wybierz grupę, żeby zobaczyć jej program nauczania.</p>
        </div>
      </div>
    } @else if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    } @else if (items().length === 0) {
      <div class="k-card">
        <div class="empty-state">
          <span class="material-symbols-outlined empty-icon" aria-hidden="true">menu_book</span>
          <p>Brak pozycji planu. Dodaj pierwszą.</p>
        </div>
      </div>
    } @else {
      <div class="k-card">
        <div class="items-list">
          @for (it of items(); track it.id; let i = $index) {
            <div class="item-row" [class.item-inactive]="!it.is_active">
              <span class="item-index">{{ i + 1 }}</span>
              <div class="item-body">
                <div class="item-title-row">
                  @if (it.section) { <span class="type-badge">{{ it.section }}</span> }
                  <span class="item-title">{{ it.title }}</span>
                  @if (!it.is_active) { <span class="status-badge">ukryte</span> }
                  @if (it.est_minutes > 0) { <span class="text-muted text-sm">{{ it.est_minutes }} min</span> }
                </div>
                @if (it.description) { <p class="item-desc">{{ it.description }}</p> }
              </div>
              <div class="item-actions">
                <button mat-icon-button type="button" [disabled]="i === 0" (click)="move(it, 'up')" aria-label="Przenieś w górę">
                  <span class="material-symbols-outlined" aria-hidden="true">arrow_upward</span>
                </button>
                <button mat-icon-button type="button" [disabled]="i === items().length - 1" (click)="move(it, 'down')" aria-label="Przenieś w dół">
                  <span class="material-symbols-outlined" aria-hidden="true">arrow_downward</span>
                </button>
                <button mat-stroked-button type="button" class="btn-small btn-edit" (click)="startEdit(it)">Edytuj</button>
                <button mat-stroked-button type="button" class="btn-small btn-danger" (click)="remove(it)">Usuń</button>
              </div>
            </div>
          }
        </div>
      </div>
    }
  `,
  styles: [`
    .page-header { position: relative; }
    .add-btn { position: absolute; top: 0; right: 0; }
    .course-filter { display: flex; align-items: center; gap: .5rem; margin-bottom: 1rem;
      label { font-size: .85rem; color: var(--c-text-muted); }
      select { padding: .4rem .6rem; border: 1px solid var(--c-border-2); border-radius: .5rem; font-size: .85rem; }
    }
    .items-list { display: flex; flex-direction: column; }
    .item-row {
      display: flex; align-items: flex-start; gap: .85rem; padding: .85rem 0; border-bottom: 1px solid var(--c-border);
      &:last-child { border-bottom: none; }
      &.item-inactive { opacity: .6; }
    }
    .item-index { flex-shrink: 0; width: 1.6rem; height: 1.6rem; border-radius: 50%; background: var(--c-surface-2); color: var(--c-text-muted); display: flex; align-items: center; justify-content: center; font-size: .78rem; font-weight: 600; }
    .item-body { flex: 1; min-width: 0; }
    .item-title-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .item-title { font-weight: 600; }
    .type-badge { background: var(--c-surface-2); color: var(--c-text-muted); border-radius: .4rem; padding: .1rem .5rem; font-size: .75rem; }
    .item-desc { margin: .35rem 0 0; font-size: .88rem; color: var(--c-text-muted); }
    .item-actions { display: flex; align-items: center; gap: .3rem; flex-shrink: 0; }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; }
    .btn-edit   { color: #4f46e5 !important; border-color: #4f46e5 !important; }
    .btn-danger { color: #b91c1c !important; border-color: #b91c1c !important; }
  `],
})
export class InstructorProgramComponent {
  private api    = inject(InstructorApiService);
  private snack  = inject(MatSnackBar);
  private dialog = inject(MatDialog);
  courseCtx      = inject(InstructorCourseContextService);

  localCourseId = signal<number | null>(this.courseCtx.selectedId());
  loading = signal(false);
  items   = signal<InstructorCurriculumItem[]>([]);

  courseId = computed(() => this.localCourseId());

  constructor() {
    effect(() => {
      const cid = this.courseId();
      if (cid) this.load(cid);
      else this.items.set([]);
    });
  }

  load(courseId: number): void {
    this.loading.set(true);
    this.api.getCurriculum(courseId).subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.items.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  startAdd(): void {
    const cid = this.courseId();
    if (!cid) return;
    this.dialog.open(CurriculumItemDialogComponent, {
      width: '560px', maxWidth: '95vw', data: { courseId: cid, item: null },
    }).afterClosed().subscribe(saved => { if (saved) this.load(cid); });
  }

  startEdit(item: InstructorCurriculumItem): void {
    const cid = this.courseId();
    if (!cid) return;
    this.dialog.open(CurriculumItemDialogComponent, {
      width: '560px', maxWidth: '95vw', data: { courseId: cid, item },
    }).afterClosed().subscribe(saved => { if (saved) this.load(cid); });
  }

  move(item: InstructorCurriculumItem, dir: 'up' | 'down'): void {
    const cid = this.courseId();
    if (!cid) return;
    this.api.moveCurriculumItem(cid, item.id, dir).subscribe({
      next: res => { if (res.success) this.load(cid); },
      error: err => this.snack.open(err?.error?.error || 'Nie udało się zmienić kolejności.', 'OK', { duration: 5000 }),
    });
  }

  remove(item: InstructorCurriculumItem): void {
    const cid = this.courseId();
    if (!cid) return;
    if (!confirm(`Usunąć pozycję planu "${item.title}"? Powiązania z lekcjami zostaną usunięte.`)) return;
    this.api.deleteCurriculumItem(cid, item.id).subscribe({
      next: res => {
        this.snack.open(res.message || 'Usunięto.', 'OK', { duration: 4000 });
        if (res.success) this.load(cid);
      },
      error: err => this.snack.open(err?.error?.error || 'Nie udało się usunąć pozycji.', 'OK', { duration: 5000 }),
    });
  }
}
