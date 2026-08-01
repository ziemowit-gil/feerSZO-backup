import { Component } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { MatButtonModule } from '@angular/material/button';

@Component({
  selector: 'app-curriculum',
  standalone: true,
  imports: [MatIconModule, MatButtonModule, PageHeaderComponent],
  template: `
    <app-page-header title="Plan nauczania" subtitle="Dydaktyka — sylabus i curriculum" icon="list_alt">
      <button mat-flat-button color="primary" aria-label="Dodaj temat do planu">
        <mat-icon aria-hidden="true">add</mat-icon> DODAJ TEMAT
      </button>
    </app-page-header>
    <div class="empty-state" role="status">
      <mat-icon aria-hidden="true">list_alt</mat-icon>
      <p class="empty-title">Moduł Plan nauczania — w budowie</p>
      <p>Wybierz kurs, aby zobaczyć sylabus.</p>
    </div>
  `,
})
export class CurriculumComponent {}
