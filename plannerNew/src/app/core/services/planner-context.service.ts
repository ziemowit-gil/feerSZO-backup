import { Injectable, signal } from '@angular/core';

/** Kontekst otwarcia planera — wypełniany przez URL params z TI Dydaktyka. */
@Injectable({ providedIn: 'root' })
export class PlannerContextService {
  /** ID kursu TI przekazane z linku dydaktyka — filtruje widoki. */
  courseId = signal<number | null>(null);

  /** 'ti' gdy planer otwarty z panelu TI Dydaktyka. */
  sourceApp = signal<string | null>(null);

  /** Nazwa kursu (pobrana asynchronicznie). */
  courseName = signal<string | null>(null);
}
