import { Routes } from '@angular/router';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { ApiService } from './core/services/api.service';

const authGuard = () => {
  const api = inject(ApiService);
  const router = inject(Router);
  if (api.isConfigured()) return true;
  return router.createUrlTree(['/auth']);
};

export const routes: Routes = [
  { path: '', redirectTo: 'timetable', pathMatch: 'full' },
  { path: 'auth', loadComponent: () => import('./features/auth/auth.component').then(m => m.AuthComponent) },
  { path: 'timetable',    canActivate: [authGuard], loadComponent: () => import('./features/timetable/timetable.component').then(m => m.TimetableComponent) },
  { path: 'sessions',     canActivate: [authGuard], loadComponent: () => import('./features/sessions/sessions.component').then(m => m.SessionsComponent) },
  { path: 'rooms',        canActivate: [authGuard], loadComponent: () => import('./features/rooms/rooms.component').then(m => m.RoomsComponent) },
  { path: 'drafts',       canActivate: [authGuard], loadComponent: () => import('./features/drafts/drafts.component').then(m => m.DraftsComponent) },
  { path: 'tokens',       canActivate: [authGuard], loadComponent: () => import('./features/tokens/tokens.component').then(m => m.TokensComponent) },
  { path: 'tech-paths',   canActivate: [authGuard], loadComponent: () => import('./features/tech-paths/tech-paths.component').then(m => m.TechPathsComponent) },
  { path: '**', redirectTo: 'timetable' },
];
