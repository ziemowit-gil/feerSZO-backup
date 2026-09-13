import { Component, signal, computed, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';
import { InstructorMaterial } from '../../../core/models/kursant.models';
import { MaterialFormDialogComponent } from './material-form-dialog.component';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { ConfirmDialogComponent } from '../../../shared/components/confirm-dialog.component';

/**
 * Materiały prowadzącego — odpowiednik karty30/ti/dydaktyk/_tab_materialy.php:
 * lista (podgląd, pobranie załącznika, link) + dodawanie/edycja/usuwanie w
 * oknie modalnym (na życzenie — ten sam wzorzec co Lekcje). Wybór pliku z
 * dysku ownCloud (cloud_pick.php) zostaje na razie w klasycznym panelu.
 */
@Component({
  selector: 'app-instructor-materialy',
  standalone: true,
  imports: [CommonModule, DatePipe, MatButtonModule, MatDialogModule, MatSnackBarModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie materiałów… }</div>

    <div class="page-header">
      <h1>Materiały</h1>
      <p class="subtitle">Materiały / eLearning Twoich kursów</p>
      <button mat-flat-button type="button" class="add-btn" (click)="startAdd()">
        <span class="material-symbols-outlined" aria-hidden="true">add</span>
        Dodaj materiał
      </button>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status" aria-label="Ładowanie materiałów">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
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
              <button mat-stroked-button type="button" class="btn-small btn-edit" (click)="startEdit(m)">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">edit</span>
                Edytuj
              </button>
              <button mat-stroked-button type="button" class="btn-small btn-danger" (click)="remove(m)">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">delete</span>
                Usuń
              </button>
            </div>
          </div>
        }
      }
    }
  `,
  styles: [`
    .page-header { position: relative; }
    .add-btn { position: absolute; top: 0; right: 0; }

    .mat-card { &.mat-inactive { opacity: .6; } }
    .mat-title-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .mat-title { font-size: 1.05rem; margin: 0; }
    .type-badge { background: var(--c-surface-2); color: var(--c-text-muted); border-radius: .4rem; padding: .1rem .5rem; font-size: .75rem; text-transform: capitalize; }
    .mat-session, .mat-desc { margin: .5rem 0 0; font-size: .9rem; }
    .mat-footer { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; padding-top: .75rem; border-top: 1px solid var(--c-border); }
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; display: inline-flex !important; align-items: center; gap: .3rem; }
    .btn-edit   { color: #4f46e5 !important; border-color: #4f46e5 !important; }
    .btn-danger { color: var(--c-danger, #dc2626) !important; border-color: var(--c-danger, #dc2626) !important; }
    .status-badge.upcoming { background: var(--c-warning-bg); color: var(--c-warning); }
    .status-badge.closed { background: var(--c-border); color: var(--c-text-muted); }
  `],
})
export class InstructorMaterialyComponent implements OnInit {
  private api    = inject(InstructorApiService);
  private snack  = inject(MatSnackBar);
  private dialog = inject(MatDialog);
  courseCtx      = inject(InstructorCourseContextService);

  loading      = signal(true);
  materials    = signal<InstructorMaterial[]>([]);

  filteredMaterials = computed(() => {
    const cid = this.courseCtx.selectedId();
    const all = this.materials();
    return cid ? all.filter(m => m.course_id === cid) : all;
  });

  ngOnInit(): void {
    this.load();
    this.courseCtx.ensureLoaded();
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

  startAdd(): void {
    this.dialog.open(MaterialFormDialogComponent, {
      width: '640px', maxWidth: '95vw',
      data: { mode: 'add', material: null, courses: this.courseCtx.courses() },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }

  startEdit(m: InstructorMaterial): void {
    this.dialog.open(MaterialFormDialogComponent, {
      width: '640px', maxWidth: '95vw',
      data: { mode: 'edit', material: m, courses: this.courseCtx.courses() },
    }).afterClosed().subscribe(saved => { if (saved) this.load(); });
  }

  remove(m: InstructorMaterial): void {
    this.dialog.open(ConfirmDialogComponent, {
      width: '420px', maxWidth: '95vw',
      data: { title: 'Usuń materiał', danger: true, confirmLabel: 'Usuń', message: `Usunąć materiał „${m.title}"?` },
    }).afterClosed().subscribe(confirmed => {
      if (!confirmed) return;
      this.api.deleteMaterial(m.id).subscribe({
        next: res => {
          this.snack.open(res.message || 'Usunięto.', 'OK', { duration: 4000 });
          if (res.success) this.load();
        },
        error: err => this.snack.open(err?.error?.error || 'Nie udało się usunąć materiału.', 'OK', { duration: 5000 }),
      });
    });
  }
}
