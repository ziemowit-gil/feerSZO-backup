import { Injectable, signal, inject } from '@angular/core';
import { InstructorApiService } from './instructor-api.service';

/**
 * Wybór "aktywnej grupy" prowadzącego — WSPÓLNY dla całego panelu (selektor w
 * topbarze, InstructorShellComponent), zamiast osobnego filtra per zakładka
 * (Lekcje/Zadania/Materiały). Wybór przenosi się wszędzie: zmiana grupy w
 * jednym miejscu filtruje dane wszystkich zakładek naraz.
 */
@Injectable({ providedIn: 'root' })
export class InstructorCourseContextService {
  private api = inject(InstructorApiService);

  courses    = signal<{ id: number; name: string }[]>([]);
  selectedId = signal<number | null>(null);
  private loaded = false;

  ensureLoaded(): void {
    if (this.loaded) return;
    this.loaded = true;
    this.api.getCourses().subscribe({
      next: res => { if (res.success && res.data) this.courses.set(res.data); },
    });
  }
}
