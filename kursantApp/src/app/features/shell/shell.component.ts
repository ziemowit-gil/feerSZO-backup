import {
  Component, signal, inject, computed, HostListener, OnInit,
} from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive } from '@angular/router';
import { CommonModule } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatTooltipModule } from '@angular/material/tooltip';
import { AuthService } from '../../core/auth/auth.service';
import { AppDataService } from '../../core/services/app-data.service';
import { PushService } from '../../core/services/push.service';

interface NavItem {
  path: string;
  label: string;
  icon: string;
  section?: string;
  badgeKey?: keyof Badges;
  hideMinor?: boolean;
  /** Widoczne tylko dla tych ról; brak = widoczne dla wszystkich (w tym parent/authp). */
  onlyRoles?: ('student' | 'parent' | 'authp')[];
}

/**
 * Zakładki widoczne dla roli parent/authp — odpowiednik zawężonego menu
 * karty30/ti/kursant/parent.php (rozliczenia/portfel/frekwencja/oceny/licencje/
 * harmonogram/wiadomosci/vlab/dostep) i authorized_person.php (tylko
 * lekcje/rozliczenia, do odczytu — patrz gating w api/v1/kursant_student.php).
 */
const PARENT_VISIBLE = new Set(['dane', 'lekcje', 'oceny', 'plan', 'licencje', 'wiadomosci', 'rozliczenia', 'vlab', 'dostep']);
const AUTHP_VISIBLE  = new Set(['dane', 'lekcje', 'rozliczenia']);

interface Badges { msg: number; notices: number; terms: number; hw: number; }

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [
    RouterOutlet, RouterLink, RouterLinkActive,
    CommonModule, MatButtonModule, MatIconModule, MatTooltipModule,
  ],
  template: `
    <a href="#main-content" class="skip-link">Przejdź do treści głównej</a>
    <div aria-live="polite" aria-atomic="true" class="sr-only">
      @if (appData.loading()) { Ładowanie danych panelu… }
      @if (appData.error()) { Błąd ładowania danych: {{ appData.error() }} }
    </div>
    <div class="app-shell">
      @if (roleBannerText()) {
        <div class="role-banner" [class.role-banner--imp]="role() === 'impersonation'" role="status">
          <span class="material-symbols-outlined" aria-hidden="true">
            {{ role() === 'impersonation' ? 'visibility' : (role() === 'authp' ? 'lock' : 'family_restroom') }}
          </span>
          <span>{{ roleBannerText() }}</span>
          @if (role() === 'impersonation') {
            <button class="role-banner-exit" (click)="logout()">Zakończ impersonację</button>
          }
        </div>
      }
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
          <span class="user-name">{{ studentName() }}</span>
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

          @if (appData.courses().length > 1) {
            <div class="sidebar-group-switch">
              <label for="group-switch">Grupa</label>
              <select id="group-switch" (change)="onGroupChange($event)">
                <option value="" [selected]="appData.selectedCourseId() === null">Wszystkie grupy</option>
                @for (c of appData.courses(); track c.id) {
                  <option [value]="c.id" [selected]="appData.selectedCourseId() === c.id">{{ c.name }}</option>
                }
              </select>
            </div>
          }

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
    .role-banner {
      display: flex;
      align-items: center;
      gap: .5rem;
      padding: .5rem 1rem;
      background: #eff6ff;
      color: #1e40af;
      font-size: .85rem;
      border-bottom: 1px solid #bfdbfe;

      &.role-banner--imp { background: #fef3c7; color: #92400e; border-bottom-color: #fde68a; }

      .role-banner-exit {
        margin-left: auto;
        background: none;
        border: 1px solid currentColor;
        color: inherit;
        border-radius: .4rem;
        padding: .2rem .6rem;
        font-size: .8rem;
        cursor: pointer;
      }
    }

    .topbar {
      display: flex;
      align-items: center;
      gap: .5rem;
      height: 56px;
      min-height: 56px;
      padding: 0 1rem;
      background: #ffffff;
      border-bottom: 1px solid #e5e7eb;
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
      color: #111827;
      font-weight: 600;
      font-size: 1rem;
      flex-shrink: 0;

      .brand-icon { color: #2563eb; font-size: 1.4rem; }
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
        color: #6b7280;
        padding: .4rem;
        border-radius: .5rem;
        text-decoration: none;
        &:hover { color: #111827; background: #f3f4f6; }
        .notif-badge { position: absolute; top: 0; right: 0; transform: translate(25%, -25%); }
      }
    }

    .topbar-user {
      display: flex;
      align-items: center;
      gap: .5rem;
      padding-left: .5rem;
      border-left: 1px solid #e5e7eb;

      .user-name {
        font-size: .85rem;
        color: #6b7280;
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
      background: linear-gradient(135deg, #2563eb, #3b82f6);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: .9rem;
      flex-shrink: 0;
    }

    .sidebar-user-name { font-weight: 600; font-size: .9rem; display: block; color: #111827; }
    .sidebar-user-login { display: block; color: #6b7280; font-size: .8rem; }

    .sidebar-group-switch {
      padding: 0 1rem .75rem;
      display: flex;
      flex-direction: column;
      gap: .3rem;

      label { font-size: .75rem; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }

      select {
        padding: .4rem .5rem;
        border: 1px solid #d1d5db;
        border-radius: .4rem;
        font-size: .85rem;
        background: #fff;
      }
    }

    .sidebar-footer {
      margin-top: auto;
      padding: .5rem 0 1rem;
      border-top: 1px solid #e5e7eb;
    }

    .ms-auto { margin-left: auto; }

    main#main-content { flex: 1; overflow-y: auto; padding: 1.75rem 2rem; }
    @media (max-width: 768px) { main#main-content { padding: 1rem; } }
  `],
})
export class ShellComponent implements OnInit {
  private auth = inject(AuthService);
  appData      = inject(AppDataService);

  student       = this.auth.student;
  role          = this.auth.role;
  actorName     = this.auth.actorName;
  sidebarOpen   = signal(false);
  badges        = this.appData.badges;

  roleBannerText = computed(() => {
    const name = this.actorName();
    switch (this.role()) {
      case 'parent':        return `Widok opiekuna${name ? ' — ' + name : ''}. Zmiany dotyczą konta dziecka.`;
      case 'authp':         return `Wgląd osoby upoważnionej${name ? ' — ' + name : ''} — tylko do odczytu.`;
      case 'impersonation': return `Impersonacja administratora (${name || 'admin'}) — oglądasz konto: ${this.studentName()}.`;
      default:              return '';
    }
  });

  studentName = computed(() => {
    const s = this.student();
    if (!s) return '';
    return s.name || s.login;
  });

  studentInitials = computed(() => {
    const name = this.studentName().trim();
    const parts = name.split(/\s+/);
    return parts.length >= 2
      ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
      : name.slice(0, 2).toUpperCase();
  });

  private readonly NAV_ITEMS: NavItem[] = [
    { path: 'dane',       label: 'Dane kursanta',      icon: 'person' },
    { path: 'lekcje',     label: 'Moje lekcje',        icon: 'calendar_month',  section: 'Nauka' },
    { path: 'zadania',    label: 'Dydaktyka',           icon: 'assignment',      badgeKey: 'hw' },
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
    { path: 'problem',    label: 'Pomoc',              icon: 'help',            section: 'Inne', onlyRoles: ['student'] },
    { path: 'aktywnosc',  label: 'Aktywność',          icon: 'history',         onlyRoles: ['student'] },
    { path: 'ustawienia', label: 'Ustawienia',         icon: 'settings',        onlyRoles: ['student'] },
    { path: 'regulaminy', label: 'Regulaminy',         icon: 'gavel',           badgeKey: 'terms', onlyRoles: ['student'] },
    { path: 'dostep',     label: 'Dostęp opiekuna',    icon: 'shield_person',   onlyRoles: ['parent'] },
  ];

  visibleNavItems = computed(() => {
    const isMinor = this.student()?.is_minor ?? false;
    const role = this.auth.role();
    return this.NAV_ITEMS.filter(item => {
      if (isMinor && item.hideMinor) return false;
      if (item.onlyRoles && !item.onlyRoles.includes(role as 'student' | 'parent' | 'authp')) return false;
      if (role === 'parent' && !PARENT_VISIBLE.has(item.path) && !item.onlyRoles) return false;
      if (role === 'authp' && !AUTHP_VISIBLE.has(item.path)) return false;
      return true;
    });
  });

  private push = inject(PushService);

  ngOnInit(): void {
    this.push.init();
    // Shell montuje się raz na sesję (login lub odświeżenie strony z ważnym
    // tokenem) — to jest jedyne miejsce, w którym dashboard jest pobierany
    // od zera; dalsza nawigacja między zakładkami czyta już z pamięci.
    this.appData.load();
  }

  onGroupChange(event: Event): void {
    const val = (event.target as HTMLSelectElement).value;
    this.appData.selectedCourseId.set(val === '' ? null : Number(val));
  }

  toggleSidebar() { this.sidebarOpen.update(v => !v); }
  closeSidebar()  { this.sidebarOpen.set(false); }

  @HostListener('document:keydown.escape')
  onEscape() { this.closeSidebar(); }

  logout() { this.auth.logout(); }
}
