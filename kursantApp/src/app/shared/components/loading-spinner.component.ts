import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-loading-spinner',
  standalone: true,
  template: `
    <div class="loading-overlay" role="status" [attr.aria-label]="label">
      <span class="material-symbols-outlined spin" aria-hidden="true">progress_activity</span>
      <span>{{ label }}</span>
    </div>
  `,
  styles: [`
    .loading-overlay {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: .75rem;
      padding: 3rem 1rem;
      color: rgba(255,255,255,.4);
      font-size: .9rem;
    }

    .spin {
      font-size: 2rem;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      from { transform: rotate(0deg); }
      to   { transform: rotate(360deg); }
    }
  `],
})
export class LoadingSpinnerComponent {
  @Input() label = 'Ładowanie…';
}
