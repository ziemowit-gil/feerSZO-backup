import { Component, EventEmitter, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';

/**
 * Rząd szybkich skrótów daty ("Dzisiaj"/"Przyszły tydzień"/"Przyszły miesiąc")
 * pod polem daty w oknach modalnych — na życzenie, żeby nie trzeba było
 * zawsze klikać w kalendarzyk natywnego inputu. Emituje "YYYY-MM-DD".
 */
@Component({
  selector: 'app-quick-date-chips',
  standalone: true,
  imports: [CommonModule, MatButtonModule],
  template: `
    <div class="quick-dates">
      <button mat-stroked-button type="button" class="chip" (click)="pick(0)">Dzisiaj</button>
      <button mat-stroked-button type="button" class="chip" (click)="pick(7)">Przyszły tydzień</button>
      <button mat-stroked-button type="button" class="chip" (click)="pickMonth()">Przyszły miesiąc</button>
    </div>
  `,
  styles: [`
    .quick-dates { display: flex; gap: .4rem; flex-wrap: wrap; margin: -.35rem 0 .75rem; }
    .chip { font-size: .75rem !important; padding: .15rem .55rem !important; height: auto !important; line-height: 1.6 !important; }
  `],
})
export class QuickDateChipsComponent {
  @Output() picked = new EventEmitter<string>();

  pick(daysFromNow: number): void {
    const d = new Date();
    d.setDate(d.getDate() + daysFromNow);
    this.picked.emit(this.toIso(d));
  }

  pickMonth(): void {
    const d = new Date();
    d.setMonth(d.getMonth() + 1);
    this.picked.emit(this.toIso(d));
  }

  private toIso(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
}
