import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { InstructorAuthService } from '../../../core/auth/instructor-auth.service';

/**
 * Odbiera jednorazowy token logowania przez Microsoft 365
 * (auth/ms365_prowadzacy.php → auth/microsoft.php → k30_imp_token_create('dyd', ...))
 * i wymienia go na sesję — odpowiednik ImpersonateComponent dla panelu kursanta,
 * ale dla prowadzącego. TOTP jest obowiązkowe dla każdego konta poza rolą
 * 'admin' (patrz dyd_require()), więc wynik wymiany bywa dwojaki:
 * pełna sesja (konto admin) albo pending_token — wtedy dokańczamy logowanie
 * na ekranie logowania prowadzącego, tak jak po kroku hasłem.
 */
@Component({
  selector: 'app-instructor-impersonate',
  standalone: true,
  imports: [CommonModule, MatProgressSpinnerModule],
  template: `
    <div class="imp-screen">
      @if (error()) {
        <div class="k-alert danger" role="alert">
          <span class="material-symbols-outlined" aria-hidden="true">error</span>
          <span>{{ error() }}</span>
        </div>
      } @else {
        <mat-progress-spinner diameter="32" mode="indeterminate" aria-label="Logowanie…"></mat-progress-spinner>
        <p>Logowanie do panelu…</p>
      }
    </div>
  `,
  styles: [`
    .imp-screen {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 1rem;
      color: #6b7280;
    }
  `],
})
export class InstructorImpersonateComponent implements OnInit {
  private route  = inject(ActivatedRoute);
  private router = inject(Router);
  private auth   = inject(InstructorAuthService);

  error = signal<string | null>(null);

  ngOnInit(): void {
    const t = this.route.snapshot.queryParamMap.get('t');
    if (!t) { this.error.set('Brak tokenu logowania.'); return; }
    this.auth.impersonate(t).subscribe({
      next: res => {
        if (!res.success) { this.error.set(res.error ?? 'Token wygasł lub jest nieprawidłowy.'); return; }
        if (res.data && 'pending_token' in res.data) {
          this.router.navigate(['/logowanie-prowadzacy'], { queryParams: { pending_token: res.data.pending_token } });
        } else {
          this.router.navigate(['/prowadzacy']);
        }
      },
      error: err => this.error.set(err?.error?.error ?? 'Token wygasł lub jest nieprawidłowy.'),
    });
  }
}
