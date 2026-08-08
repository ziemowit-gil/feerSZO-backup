import { Component, signal } from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive } from '@angular/router';
import { SlicePipe } from '@angular/common';
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
    SlicePipe,
  ],
  template: `
    <mat-sidenav-container class="sidenav-container">
      <mat-sidenav class="sidenav" mode="side" [opened]="navOpen()">
        <!-- Logo -->
        <div class="nav-logo">
          <div class="logo-badge">SZO</div>
          <div class="logo-titles">
            <span class="logo-main">Planner</span>
            <span class="logo-org">FEER</span>
          </div>
        </div>

        <!-- Nav items -->
        <nav class="nav-list">
          @for (item of navItems; track item.route) {
            <a class="nav-item" [routerLink]="item.route" routerLinkActive="active-link">
              <mat-icon class="nav-icon">{{ item.icon }}</mat-icon>
              <span class="nav-label">{{ item.label }}</span>
            </a>
          }
        </nav>

        <!-- Footer -->
        <div class="nav-footer">
          <mat-icon class="footer-icon">cloud_done</mat-icon>
          <span class="footer-url" [title]="apiUrl()">{{ apiUrl() | slice:0:28 }}</span>
        </div>
      </mat-sidenav>

      <mat-sidenav-content>
        <header class="top-bar">
          <button mat-icon-button class="menu-btn" (click)="navOpen.set(!navOpen())" matTooltip="Menu">
            <mat-icon>menu</mat-icon>
          </button>
          <span class="bar-title">SZO Planner</span>
          <span class="bar-spacer"></span>
          <a mat-icon-button routerLink="/auth" matTooltip="Ustawienia API" class="bar-btn">
            <mat-icon>settings</mat-icon>
          </a>
        </header>

        <div class="content-wrap">
          <router-outlet />
        </div>
      </mat-sidenav-content>
    </mat-sidenav-container>
  `,
  styles: [`
    .sidenav-container { height: 100vh; }

    /* ── Sidenav ── */
    .sidenav {
      width: 228px;
      background: var(--nav-bg) !important;
      border-right: 1px solid var(--nav-bor) !important;
      display: flex;
      flex-direction: column;
    }

    /* Logo */
    .nav-logo {
      display: flex; align-items: center; gap: 10px;
      padding: 22px 18px 16px;
      border-bottom: 1px solid var(--nav-bor);
    }
    .logo-badge {
      width: 36px; height: 36px;
      background: var(--acc);
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: .75rem; font-weight: 900; color: #fff;
      letter-spacing: .04em; flex-shrink: 0;
    }
    .logo-titles { display: flex; flex-direction: column; line-height: 1; }
    .logo-main { font-size: .95rem; font-weight: 700; color: var(--nav-t1); }
    .logo-org  { font-size: .62rem; color: var(--nav-t2); text-transform: uppercase; letter-spacing: .12em; margin-top: 2px; }

    /* Nav list */
    .nav-list {
      flex: 1;
      display: flex; flex-direction: column;
      padding: 10px 10px 0;
      gap: 2px;
      overflow-y: auto;
    }
    .nav-item {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 12px;
      border-radius: 8px;
      color: var(--nav-t2);
      text-decoration: none;
      font-size: .84rem; font-weight: 500;
      transition: background .12s, color .12s;
      cursor: pointer;
    }
    .nav-item:hover { background: rgba(255,255,255,.07); color: var(--nav-t1); }
    .nav-item:hover .nav-icon { color: var(--nav-t1); }
    .nav-item.active-link {
      background: var(--acc-muted);
      color: var(--acc-hi) !important;
    }
    .nav-item.active-link .nav-icon { color: var(--acc-hi) !important; }
    .nav-icon { font-size: 20px; width: 20px; height: 20px; flex-shrink: 0; color: inherit; }
    .nav-label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* Footer */
    .nav-footer {
      padding: 12px 14px;
      border-top: 1px solid var(--nav-bor);
      display: flex; align-items: center; gap: 6px;
      font-size: 10.5px; color: var(--nav-t2);
    }
    .footer-icon { font-size: 14px; width: 14px; height: 14px; opacity: .6; }
    .footer-url { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* ── Top bar ── */
    .top-bar {
      display: flex; align-items: center; gap: 4px;
      height: 56px; padding: 0 16px 0 8px;
      background: var(--sur);
      border-bottom: 1px solid var(--bor);
      position: sticky; top: 0; z-index: 100;
      box-shadow: 0 1px 0 var(--bor);
    }
    .menu-btn { color: var(--t2) !important; }
    .bar-title { font-size: .9rem; font-weight: 700; color: var(--t1); margin-left: 4px; letter-spacing: -.01em; }
    .bar-spacer { flex: 1; }
    .bar-btn { color: var(--t2) !important; }

    /* ── Content ── */
    .content-wrap { padding: 28px; overflow-y: auto; height: calc(100vh - 56px); }
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
