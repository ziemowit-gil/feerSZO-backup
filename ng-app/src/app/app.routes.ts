import { Routes } from '@angular/router';
import { ShellComponent } from './core/shell/shell.component';

export const routes: Routes = [
  {
    path: '',
    component: ShellComponent,
    children: [
      { path: '', redirectTo: 'dydaktyka/lekcje', pathMatch: 'full' },
      { path: 'start', loadComponent: () => import('./features/start/start.component').then(m => m.StartComponent) },
      {
        path: 'dydaktyka',
        loadChildren: () => import('./features/dydaktyka/dydaktyka.routes').then(m => m.DYDAKTYKA_ROUTES),
      },
      {
        path: 'ustawienia',
        loadComponent: () => import('./features/settings/settings.component').then(m => m.SettingsComponent),
        title: 'Ustawienia — feerSZO',
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
