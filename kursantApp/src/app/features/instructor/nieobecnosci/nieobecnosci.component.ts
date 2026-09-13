import { Component, signal, computed, inject, OnInit } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { InstructorApiService } from '../../../core/services/instructor-api.service';
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';
import { InstructorAbsence } from '../../../core/models/kursant.models';

type AbsenceState = 'unexcused' | 'excused' | 'no_show';

/**
 * Nieobecności (prowadzący) — odpowiednik karty30/ti/dydaktyk/_tab_nieobecnosci.php,
 * ale ZBIORCZO dla wszystkich własnych kursów naraz (klasyczny panel pokazuje
 * jeden kurs na raz) — filtrowanie po grupie przez wspólny
 * InstructorCourseContextService, jak w Lekcjach/Zadaniach/Materiałach.
 * Usprawiedliw/Cofnij reużywają action=cancel_attendee/restore_attendee z
 * Lekcji — to te same funkcje co excuse_absence/unexcuse_absence w klasyku.
 * Oznaczanie "nie pojawił się" (wymaga uploadu zrzutu ekranu jako dowodu)
 * zostaje na razie w klasycznym panelu — tu tylko podgląd i cofnięcie.
 */
@Component({
  selector: 'app-instructor-nieobecnosci',
  standalone: true,
  imports: [CommonModule, DatePipe, MatButtonModule, MatSnackBarModule],
  template: `
    <div aria-live="polite" class="sr-only">@if (loading()) { Ładowanie nieobecności… }</div>

    <div class="page-header">
      <h1>Nieobecności</h1>
      <p class="subtitle">Usprawiedliwiona nieobecność nie jest liczona do ceny.</p>
    </div>

    @if (loading()) {
      <div class="loading-overlay" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2.5rem;opacity:.3">hourglass_top</span>
        <span>Ładowanie…</span>
      </div>
    }

    @if (!loading()) {
      @if (filtered().length === 0) {
        <div class="k-card">
          <div class="empty-state">
            <span class="material-symbols-outlined empty-icon" aria-hidden="true">check_circle</span>
            <p>Brak nieobecności na odbytych lekcjach.</p>
          </div>
        </div>
      } @else {
        <div class="k-card">
          <div class="k-table-wrap">
            <table class="k-table" aria-label="Lista nieobecności">
              <thead>
                <tr>
                  <th scope="col">Data</th><th scope="col">Kursant</th><th scope="col">Grupa</th>
                  <th scope="col">Temat</th><th scope="col">Status</th><th scope="col">Powód / kto</th><th scope="col"></th>
                </tr>
              </thead>
              <tbody>
                @for (a of filtered(); track a.session_id + '-' + a.client_id) {
                  <tr>
                    <td class="text-nowrap">
                      {{ a.lesson_date | date:'d.MM.yyyy' }}
                      @if (a.time_from) { <span class="text-muted"> {{ a.time_from | slice:0:5 }}</span> }
                    </td>
                    <td class="fw-semibold">{{ a.client_name }}</td>
                    <td>{{ a.course_name }}</td>
                    <td>{{ a.topic || '—' }}</td>
                    <td>
                      @switch (stateOf(a)) {
                        @case ('no_show') { <span class="status-badge warn">nie pojawił się</span> }
                        @case ('excused') { <span class="status-badge active">usprawiedliwiona</span> }
                        @default { <span class="status-badge danger">nieusprawiedliwiona</span> }
                      }
                    </td>
                    <td class="text-sm text-muted">
                      @if (stateOf(a) === 'no_show') {
                        Rozliczenie: {{ a.no_show_billing === '1h' ? '1 godz.' : 'cała lekcja' }}
                        @if (a.no_show_reason) { <div>{{ a.no_show_reason }}</div> }
                      } @else if (stateOf(a) === 'excused') {
                        {{ a.cancel_reason || '—' }}
                      } @else { — }
                      @if (a.cancelled_by) { <div class="text-muted">{{ a.cancelled_by }}</div> }
                    </td>
                    <td class="text-end">
                      @if (stateOf(a) === 'unexcused') {
                        <button mat-stroked-button type="button" class="btn-small btn-success" (click)="excuse(a)">Usprawiedliw</button>
                      } @else {
                        <button mat-stroked-button type="button" class="btn-small" (click)="restore(a)">Cofnij</button>
                      }
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        </div>
      }
    }
  `,
  styles: [`
    .btn-small { font-size: .78rem !important; padding: .2rem .625rem !important; height: auto !important; }
    .btn-success { color: #15803d !important; border-color: #15803d !important; }
    .status-badge.danger { background: #fee2e2; color: #b91c1c; }
    .status-badge.warn { background: var(--c-warning-bg); color: var(--c-warning); }
    .status-badge.active { background: var(--c-success-bg, #dcfce7); color: var(--c-success, #15803d); }
  `],
})
export class InstructorNieobecnosciComponent implements OnInit {
  private api    = inject(InstructorApiService);
  private snack  = inject(MatSnackBar);
  courseCtx      = inject(InstructorCourseContextService);

  loading   = signal(true);
  absences  = signal<InstructorAbsence[]>([]);

  filtered = computed(() => {
    const cid = this.courseCtx.selectedId();
    const all = this.absences();
    return cid ? all.filter(a => a.course_id === cid) : all;
  });

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.getAbsences().subscribe({
      next: res => {
        this.loading.set(false);
        if (res.success && res.data) this.absences.set(res.data);
      },
      error: () => this.loading.set(false),
    });
  }

  stateOf(a: InstructorAbsence): AbsenceState {
    if (a.cancelled) return 'excused';
    if (a.no_show) return 'no_show';
    return 'unexcused';
  }

  excuse(a: InstructorAbsence): void {
    const reason = prompt(`Powód usprawiedliwienia (${a.client_name}, opcjonalnie):`, '') ?? '';
    this.api.cancelAttendee(a.session_id, a.client_id, reason).subscribe({
      next: res => { this.snack.open(res.message || 'Nieobecność usprawiedliwiona.', 'OK', { duration: 4000 }); if (res.success) this.load(); },
      error: err => this.snack.open(err?.error?.error || 'Nie udało się usprawiedliwić nieobecności.', 'OK', { duration: 5000 }),
    });
  }

  restore(a: InstructorAbsence): void {
    const msg = this.stateOf(a) === 'no_show'
      ? 'Cofnąć oznaczenie? Udział uczestnika zostanie przywrócony.'
      : 'Cofnąć usprawiedliwienie? Nieobecność znów będzie nieusprawiedliwiona.';
    if (!confirm(msg)) return;
    this.api.restoreAttendee(a.session_id, a.client_id).subscribe({
      next: res => { this.snack.open(res.message || 'Cofnięto.', 'OK', { duration: 4000 }); if (res.success) this.load(); },
      error: err => this.snack.open(err?.error?.error || 'Nie udało się cofnąć.', 'OK', { duration: 5000 }),
    });
  }
}
