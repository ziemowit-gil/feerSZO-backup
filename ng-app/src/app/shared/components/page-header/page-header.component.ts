import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MatIconModule } from '@angular/material/icon';

@Component({
  selector: 'app-page-header',
  standalone: true,
  imports: [CommonModule, MatIconModule],
  template: `
    <div class="page-header">
      <div class="page-header__title-row">
        <div class="page-header__icon" *ngIf="icon" aria-hidden="true">
          <mat-icon>{{ icon }}</mat-icon>
        </div>
        <div>
          <h1 class="page-header__title">{{ title }}</h1>
          <p class="page-header__subtitle" *ngIf="subtitle">{{ subtitle }}</p>
        </div>
      </div>
      <div class="page-header__actions">
        <ng-content></ng-content>
      </div>
    </div>
  `,
  styles: [`
    .page-header {
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: .75rem; margin-bottom: 1.25rem;
    }
    .page-header__title-row { display: flex; align-items: center; gap: .75rem; }
    .page-header__icon {
      width: 40px; height: 40px; border-radius: 8px;
      background: rgba(21,101,192,.12); color: #1565C0;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .page-header__title {
      margin: 0; font-size: 1.25rem; font-weight: 700; color: #111827; line-height: 1.3;
    }
    .page-header__subtitle { margin: 0; font-size: .8rem; color: #6b7280; }
    .page-header__actions { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
  `]
})
export class PageHeaderComponent {
  @Input() title = '';
  @Input() subtitle = '';
  @Input() icon = '';
}
