import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { AuthService } from '../../core/auth/auth.service';

/**
 * Odbiera jednorazowy token impersonacji wygenerowany w panelu admina
 * (karty30/admin/test_login.php → k30_imp_token_create('stu', ...)) i wymienia
 * go na token sesji API — odpowiednik karty30/ti/kursant/imp.php dla nowego panelu.
 */
@Component({
  selector: 'app-impersonate',
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
export class ImpersonateComponent implements OnInit {
  private route  = inject(ActivatedRoute);
  private router = inject(Router);
  private auth   = inject(AuthService);

  error = signal<string | null>(null);

  ngOnInit(): void {
    const t = this.route.snapshot.queryParamMap.get('t');
    if (!t) { this.error.set('Brak tokenu impersonacji.'); return; }
    this.auth.impersonate(t).subscribe({
      next: res => {
        if (res.success) this.router.navigate(['/dane']);
        else this.error.set(res.error ?? 'Token wygasł lub jest nieprawidłowy.');
      },
      error: err => this.error.set(err?.error?.error ?? 'Token wygasł lub jest nieprawidłowy.'),
    });
  }
}
