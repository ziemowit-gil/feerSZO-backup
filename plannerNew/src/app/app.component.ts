import { Component, signal } from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive } from '@angular/router';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatSidenavModule } from '@angular/material/sidenav';
import { MatListModule } from '@angular/material/list';
import { MatIconModule } from '@angular/material/icon';
import { MatButtonModule } from '@angular/material/button';
import { MatTooltipModule } from '@angular/material/tooltip';
import { ApiService } from './core/services/api.service';

interface NavItem { icon: string; label: string; route: string; }

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [
    RouterOutlet, RouterLink, RouterLinkActive,
    MatToolbarModule, MatSidenavModule, MatListModule,
    MatIconModule, MatButtonModule, MatTooltipModule,
  ],
  template: `
    <mat-sidenav-container class="sidenav-container">
      <mat-sidenav class="sidenav" mode="side" [opened]="navOpen()">
        <div class="nav-logo">
          <span class="logo-text">SZO</span>
          <span class="logo-sub">Planner</span>
        </div>
        <mat-nav-list>
          @for (item of navItems; track item.route) {
            <a mat-list-item [routerLink]="item.route" routerLinkActive="active-link" class="nav-item">
              <mat-icon matListItemIcon>{{ item.icon }}</mat-icon>
              <span matListItemTitle>{{ item.label }}</span>
            </a>
          }
        </mat-nav-list>
        <div class="nav-footer">
          <small>API: {{ apiUrl() }}</small>
        </div>
      </mat-sidenav>

      <mat-sidenav-content>
        <mat-toolbar class="top-toolbar">
          <button mat-icon-button (click)="navOpen.set(!navOpen())" matTooltip="Menu">
            <mat-icon>menu</mat-icon>
          </button>
          <span class="toolbar-title">Planner — Szkoła Programowania</span>
          <span class="spacer"></span>
          <a mat-icon-button routerLink="/auth" matTooltip="Ustawienia API">
            <mat-icon>settings</mat-icon>
          </a>
        </mat-toolbar>

        <div class="content-wrap">
          <router-outlet />
        </div>
      </mat-sidenav-content>
    </mat-sidenav-container>
  `,
  styles: [`
    .sidenav-container { height: 100vh; }
    .sidenav { width: 220px; background: var(--nav-bg); border-right: 1px solid var(--bor); }
    .nav-logo { padding: 20px 16px 8px; display: flex; align-items: baseline; gap: 6px; }
    .logo-text { font-size: 1.3rem; font-weight: 900; color: var(--acc); letter-spacing: .04em; }
    .logo-sub  { font-size: .75rem; color: var(--t2); text-transform: uppercase; letter-spacing: .1em; }
    .nav-item  { border-radius: 6px; margin: 2px 8px; }
    .active-link { background: rgba(232,148,26,.12) !important; color: var(--acc) !important; }
    .active-link mat-icon { color: var(--acc) !important; }
    .nav-footer { padding: 12px 16px; font-size: 10px; color: var(--t3); border-top: 1px solid var(--bor); margin-top: auto; }
    .top-toolbar { background: var(--sur); border-bottom: 1px solid var(--bor); position: sticky; top: 0; z-index: 10; }
    .toolbar-title { font-size: .9rem; font-weight: 600; margin-left: 8px; }
    .spacer { flex: 1; }
    .content-wrap { padding: 24px; overflow-y: auto; }
  `],
})
export class AppComponent {
  navOpen = signal(true);

  navItems: NavItem[] = [
    { icon: 'calendar_month', label: 'Timetable',     route: '/timetable' },
    { icon: 'event',          label: 'Sesje',          route: '/sessions' },
    { icon: 'meeting_room',   label: 'Sale',           route: '/rooms' },
    { icon: 'history',        label: 'Drafty',         route: '/drafts' },
    { icon: 'token',          label: 'Żetony',         route: '/tokens' },
    { icon: 'fork_right',     label: 'Ścieżki tech',  route: '/tech-paths' },
    { icon: 'manage_accounts',label: 'Konfiguracja',   route: '/auth' },
  ];

  constructor(private apiService: ApiService) {}

  apiUrl(): string {
    return this.apiService.getConfig().baseUrl || '(nie skonfigurowano)';
  }
}
