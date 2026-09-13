import { Injectable, inject, signal, computed } from '@angular/core';
import { KursantApiService } from './kursant-api.service';
import { DashboardData, Course } from '../models/kursant.models';

/**
 * Magazyn danych ładowanych raz po zalogowaniu (przez ShellComponent.ngOnInit,
 * który montuje się dokładnie raz na sesję — po loginie i po odświeżeniu strony
 * z ważnym tokenem), a potem współdzielonych reaktywnie między shellem i
 * poszczególnymi zakładkami — zamiast każdego widoku niezależnie odpytującego
 * ?action=dashboard przy każdym wejściu. Komponenty wywołują `refresh()` po
 * akcjach, które zmieniają liczniki (przeczytanie wiadomości/komunikatu,
 * akceptacja regulaminu), żeby baner/badge zaktualizował się bez przeładowania.
 */
@Injectable({ providedIn: 'root' })
export class AppDataService {
  private api = inject(KursantApiService);

  private _dashboard = signal<DashboardData | null>(null);
  private _loading   = signal(false);
  private _loaded    = signal(false);
  private _error     = signal<string | null>(null);

  readonly dashboard = this._dashboard.asReadonly();
  readonly loading   = this._loading.asReadonly();
  readonly loaded    = this._loaded.asReadonly();
  readonly error     = this._error.asReadonly();

  readonly badges = computed(() => {
    const d = this._dashboard();
    return { msg: d?.msg_unread ?? 0, notices: d?.notices_unread ?? 0, terms: d?.terms_pending ?? 0 };
  });

  readonly courses = computed<Course[]>(() => this._dashboard()?.active_courses ?? []);

  /** Wybrana grupa do zawężenia widoków Zadania/Oceny (przełącznik w shellu) — null = wszystkie grupy naraz. */
  readonly selectedCourseId = signal<number | null>(null);

  /** Ładuje dashboard od zera — wywoływane raz przez shell po zamontowaniu. */
  load(): void {
    this._loading.set(true);
    this._error.set(null);
    this.api.getDashboard().subscribe({
      next: res => {
        this._loading.set(false);
        this._loaded.set(true);
        if (res.success && res.data) this._dashboard.set(res.data);
        else this._error.set(res.error ?? 'Nie udało się załadować danych.');
      },
      error: () => {
        this._loading.set(false);
        this._loaded.set(true);
        this._error.set('Błąd połączenia z serwerem.');
      },
    });
  }

  /** Odświeża dashboard w tle (bez czyszczenia obecnych danych) — po akcjach zmieniających liczniki. */
  refresh(): void {
    this.api.getDashboard().subscribe({
      next: res => { if (res.success && res.data) this._dashboard.set(res.data); },
      error: () => {},
    });
  }

  /** Czyści magazyn przy wylogowaniu, żeby dane poprzedniego użytkownika/roli nie „przeciekły" na następne logowanie w tej samej karcie. */
  reset(): void {
    this._dashboard.set(null);
    this._loaded.set(false);
    this._error.set(null);
    this.selectedCourseId.set(null);
  }
}
