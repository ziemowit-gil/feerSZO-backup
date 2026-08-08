import {
  Component, signal, inject, computed, HostListener, effect,
} from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive } from '@angular/router';
import { CommonModule } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatTooltipModule } from '@angular/material/tooltip';
import { AuthService } from '../../core/auth/auth.service';

interface NavItem {
  path: string;
  label: string;
  icon: string;
  section?: string;
  badgeKey?: keyof Badges;
  hideMinor?: boolean;
}

interface Badges { msg: number; notices: number; terms: number; }

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [
    RouterOutlet, RouterLink, RouterLinkActive,
    CommonModule, MatButtonModule, MatIconModule, MatTooltipModule,
  ],
  template: `
    <div class="app-shell">
      <!-- Top bar -->
      <header class="topbar" role="banner">
        <button class="topbar-hamburger"
                mat-icon-button
                aria-label="Otwórz menu nawigacji"
                [attr.aria-expanded]="sidebarOpen()"
                (click)="toggleSidebar()">
          <span class="material-symbols-outlined">menu</span>
        </button>

        <a routerLink="/dane" class="topbar-brand" aria-label="Panel Kursanta — strona główna">
          <span class="brand-icon material-symbols-outlined" aria-hidden="true">school</span>
          <span class="brand-name">Panel Kursanta</span>
        </a>

        <div class="topbar-spacer" aria-hidden="true"></div>

        <!-- Badges row (mobile quick access) -->
        <nav aria-label="Powiadomienia" class="topbar-badges">
          @if (badges().msg > 0) {
            <a routerLink="/wiadomosci" class="topbar-badge-btn"
               [attr.aria-label]="'Wiadomości: ' + badges().msg + ' nieprzeczytanych'">
              <span class="material-symbols-outlined" aria-hidden="true">mail</span>
              <span class="notif-badge" aria-hidden="true">{{ badges().msg }}</span>
            </a>
          }
          @if (badges().notices > 0) {
            <a routerLink="/komunikaty" class="topbar-badge-btn"
               [attr.aria-label]="'Komunikaty: ' + badges().notices + ' nieprzeczytanych'">
              <span class="material-symbols-outlined" aria-hidden="true">campaign</span>
              <span class="notif-badge" aria-hidden="true">{{ badges().notices }}</span>
            </a>
          }
        </nav>

        <!-- User menu -->
        <div class="topbar-user">
          <span class="user-name" aria-hidden="true">{{ studentName() }}</span>
          <button mat-icon-button
                  aria-label="Wyloguj się"
                  matTooltip="Wyloguj"
                  (click)="logout()">
            <span class="material-symbols-outlined" aria-hidden="true">logout</span>
          </button>
        </div>
      </header>

      <div class="app-body">
        <!-- Sidebar backdrop (mobile) -->
        @if (sidebarOpen()) {
          <div class="sidebar-backdrop"
               role="presentation"
               aria-hidden="true"
               (click)="closeSidebar()"></div>
        }

        <!-- Sidebar navigation -->
        <nav class="sidebar"
             [class.open]="sidebarOpen()"
             aria-label="Nawigacja główna"
             id="sidebar-nav">

          <div class="sidebar-user-card">
            <div class="sidebar-avatar" aria-hidden="true">
              {{ studentInitials() }}
            </div>
            <div class="sidebar-user-info">
              <span class="sidebar-user-name">{{ studentName() }}</span>
              <span class="sidebar-user-login text-muted text-sm">{{ student()?.login }}</span>
            </div>
          </div>

          <hr class="k-divider" aria-hidden="true">

          <ul role="list" style="margin:0;padding:0;list-style:none;">
            @for (item of visibleNavItems(); track item.path) {
              @if (item.section) {
                <li role="presentation">
                  <span class="nav-section-label">{{ item.section }}</span>
                </li>
              }
              <li role="presentation">
                <a [routerLink]="'/' + item.path"
                   routerLinkActive="active"
                   class="nav-item"
                   [attr.aria-label]="item.label + (item.badgeKey && badges()[item.badgeKey!] > 0 ? ', ' + badges()[item.badgeKey!] + ' powiadomień' : '')"
                   (click)="closeSidebar()">
                  <span class="material-symbols-outlined" aria-hidden="true">{{ item.icon }}</span>
                  <span>{{ item.label }}</span>
                  @if (item.badgeKey && badges()[item.badgeKey!] > 0) {
                    <span class="notif-badge ms-auto" aria-hidden="true">
                      {{ badges()[item.badgeKey!] }}
                    </span>
                  }
                </a>
              </li>
            }
          </ul>

          <div class="sidebar-footer">
            <a class="nav-item nav-item--switch" href="/karty30/ti/kursant/chooser.php"
               aria-label="Zmień interfejs — przejdź do wyboru panelu">
              <span class="material-symbols-outlined" aria-hidden="true">swap_horiz</span>
              <span>Zmień interfejs</span>
            </a>
            <button class="nav-item" (click)="logout()" aria-label="Wyloguj się z panelu">
              <span class="material-symbols-outlined" aria-hidden="true">logout</span>
              <span>Wyloguj</span>
            </button>
          </div>
        </nav>

        <!-- Main content -->
        <main id="main-content" tabindex="-1">
          <!-- aria-live for route changes -->
          <div aria-live="polite" aria-atomic="true" class="sr-only" id="route-announcer"></div>
          <router-outlet />
        </main>
      </div>
    </div>
  `,
  styles: [`
    .topbar {
      display: flex;
      align-items: center;
      gap: .5rem;
      height: 56px;
      min-height: 56px;
      padding: 0 1rem;
      background: #12121f;
      border-bottom: 1px solid rgba(255,255,255,.07);
      z-index: 100;
    }

    .topbar-hamburger {
      @media (min-width: 901px) { display: none; }
    }

    .topbar-brand {
      display: flex;
      align-items: center;
      gap: .5rem;
      text-decoration: none;
      color: #fff;
      font-weight: 600;
      font-size: 1rem;
      flex-shrink: 0;

      .brand-icon { color: #e05a1e; font-size: 1.4rem; }
    }

    .topbar-spacer { flex: 1; }

    .topbar-badges {
      display: flex;
      align-items: center;
      gap: .25rem;

      .topbar-badge-btn {
        position: relative;
        display: flex;
        align-items: center;
        color: rgba(255,255,255,.6);
        padding: .4rem;
        border-radius: .5rem;
        text-decoration: none;
        &:hover { color: #fff; background: rgba(255,255,255,.07); }
        .notif-badge { position: absolute; top: 0; right: 0; transform: translate(25%, -25%); }
      }
    }

    .topbar-user {
      display: flex;
      align-items: center;
      gap: .5rem;
      padding-left: .5rem;
      border-left: 1px solid rgba(255,255,255,.1);

      .user-name {
        font-size: .85rem;
        color: rgba(255,255,255,.6);
        @media (max-width: 600px) { display: none; }
      }
    }

    .sidebar-user-card {
      display: flex;
      align-items: center;
      gap: .875rem;
      padding: 1.25rem 1rem 1rem;
    }

    .sidebar-avatar {
      width: 2.5rem; height: 2.5rem;
      border-radius: 50%;
      background: linear-gradient(135deg, #c2410c, #e05a1e);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: .9rem;
      flex-shrink: 0;
    }

    .sidebar-user-name { font-weight: 600; font-size: .9rem; display: block; }
    .sidebar-user-login { display: block; }

    .sidebar-footer {
      margin-top: auto;
      padding: .5rem 0 1rem;
      border-top: 1px solid rgba(255,255,255,.07);
    }

    .ms-auto { margin-left: auto; }

    main#main-content { flex: 1; overflow-y: auto; padding: 1.75rem 2rem; }
    @media (max-width: 768px) { main#main-content { padding: 1rem; } }
  `],
})
export class ShellComponent {
  private auth = inject(AuthService);

  student       = this.auth.student;
  sidebarOpen   = signal(false);
  badges        = signal<Badges>({ msg: 0, notices: 0, terms: 0 });

  studentName = computed(() => {
    const s = this.student();
    if (!s) return '';
    return `${(s as any).first_name ?? ''} ${(s as any).last_name ?? ''}`.trim() || s.login;
  });

  studentInitials = computed(() => {
    const name = this.studentName();
    const parts = name.split(' ');
    return parts.length >= 2
      ? (parts[0][0] + parts[1][0]).toUpperCase()
      : name.slice(0, 2).toUpperCase();
  });

  private readonly NAV_ITEMS: NavItem[] = [
    { path: 'dane',       label: 'Dane kursanta',      icon: 'person' },
    { path: 'lekcje',     label: 'Moje lekcje',        icon: 'calendar_month',  section: 'Nauka' },
    { path: 'zadania',    label: 'Dydaktyka',           icon: 'assignment' },
    { path: 'oceny',      label: 'Oceny',              icon: 'grade' },
    { path: 'plan',       label: 'Plan nauczania',      icon: 'list_alt' },
    { path: 'testy',      label: 'Testy',              icon: 'quiz' },
    { path: 'komunikaty', label: 'Komunikaty',         icon: 'campaign',        badgeKey: 'notices' },
    { path: 'wiadomosci', label: 'Wiadomości',         icon: 'mail',            badgeKey: 'msg' },
    { path: 'rozliczenia', label: 'Rozliczenia',       icon: 'receipt_long',    section: 'Konto', hideMinor: true },
    { path: 'upowaznieni', label: 'Upoważnieni',       icon: 'supervisor_account', hideMinor: true },
    { path: 'online',     label: 'Szkolenia online',   icon: 'video_call',      section: 'Dostępy' },
    { path: 'vlab',       label: 'VLab',               icon: 'terminal' },
    { path: 'dysk',       label: 'Mój dysk',           icon: 'cloud' },
    { path: 'licencje',   label: 'Licencje',           icon: 'key' },
    { path: 'pfron',      label: 'PFRON',              icon: 'accessibility' },
    { path: 'problem',    label: 'Pomoc',              icon: 'help',            section: 'Inne' },
    { path: 'aktywnosc',  label: 'Aktywność',          icon: 'history' },
    { path: 'ustawienia', label: 'Ustawienia',         icon: 'settings' },
    { path: 'regulaminy', label: 'Regulaminy',         icon: 'gavel',           badgeKey: 'terms' },
  ];

  visibleNavItems = computed(() => {
    const isMinor = this.student()?.is_minor ?? false;
    return this.NAV_ITEMS.filter(item => !(isMinor && item.hideMinor));
  });

  toggleSidebar() { this.sidebarOpen.update(v => !v); }
  closeSidebar()  { this.sidebarOpen.set(false); }

  @HostListener('document:keydown.escape')
  onEscape() { this.closeSidebar(); }

  logout() { this.auth.logout(); }
}
