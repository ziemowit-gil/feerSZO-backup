import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-empty-state',
  standalone: true,
  template: `
    <div class="empty-state">
      <span class="material-symbols-outlined empty-icon" aria-hidden="true">{{ icon }}</span>
      <p>{{ message }}</p>
      <ng-content />
    </div>
  `,
})
export class EmptyStateComponent {
  @Input() icon    = 'inbox';
  @Input() message = 'Brak danych.';
}
