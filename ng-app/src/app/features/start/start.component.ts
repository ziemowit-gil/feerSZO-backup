import { Component } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';

@Component({
  selector: 'app-start',
  standalone: true,
  imports: [MatIconModule],
  template: `
    <div style="padding:2rem">
      <h1 style="font-size:1.5rem;font-weight:700;color:#111827">Panel główny</h1>
      <p style="color:#6b7280">Witaj w systemie feerSZO.</p>
    </div>
  `,
})
export class StartComponent {}
