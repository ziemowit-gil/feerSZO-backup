import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * Zaślepka dla "Biblioteki materiałów" (ext/index.php?as=dyd) — osobny,
 * sesyjny moduł (klasyczny panel), którego nie da się bezpiecznie otworzyć
 * z tokenowej sesji Angulara (osobne uwierzytelnianie, wylądowałoby na
 * ekranie logowania klasycznego panelu). Zamiast mylącego linku zewnętrznego —
 * jasny komunikat, dopóki moduł nie zostanie zmigrowany.
 */
@Component({
  selector: 'app-biblioteka-niedostepna',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="page-header">
      <h1>Biblioteka materiałów</h1>
    </div>
    <div class="k-card empty-state">
      <span class="material-symbols-outlined empty-icon" aria-hidden="true">construction</span>
      <p>Ta sekcja nie jest jeszcze dostępna w nowym panelu.</p>
      <p class="text-muted text-sm">Biblioteka materiałów działa na razie tylko w klasycznym panelu prowadzącego — skorzystaj z niego, dopóki ten moduł nie zostanie tu przeniesiony.</p>
    </div>
  `,
})
export class BibliotekaNiedostepnaComponent {}
