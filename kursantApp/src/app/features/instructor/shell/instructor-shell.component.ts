import { Component, signal, computed, HostListener, OnInit } from '@angular/core';
import { inject } from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive } from '@angular/router';
import { CommonModule } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatTooltipModule } from '@angular/material/tooltip';
import { InstructorAuthService } from '../../../core/auth/instructor-auth.service';
import { InstructorCourseContextService } from '../../../core/services/instructor-course-context.service';
import { CoursePickerDialogComponent } from './course-picker-dialog.component';

interface NavItem { path: string; label: string; icon: string; }

/**
 * Layout panelu prowadzącego — osobny od ShellComponent (kursant), bo to
 * zupełnie inna domena (nawigacja/role). Lista zakładek rośnie wraz z kolejnymi
 * commitami migracji (patrz plan: jedna zakładka PHP = jeden endpoint +
 * komponent + wpis tutaj).
 */
@Component({
  selector: 'app-instructor-shell',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive, CommonModule, MatButtonModule, MatDialogModule, MatTooltipModule],
  template: `
    <a href="#main-content" class="skip-link">Przejdź do treści głównej</a>
    <div class="app-shell">
      <header class="topbar" role="banner">
        <button class="topbar-hamburger" mat-icon-button aria-label="Otwórz menu nawigacji"
                [attr.aria-expanded]="sidebarOpen()" (click)="toggleSidebar()">
          <span class="material-symbols-outlined">menu</span>
        </button>
        <a routerLink="/prowadzacy/pulpit" class="topbar-brand" aria-label="Panel prowadzącego — strona główna">
          <span class="brand-icon material-symbols-outlined" aria-hidden="true">person_book</span>
          <span class="brand-name">Panel prowadzącego</span>
        </a>
        <div class="topbar-spacer" aria-hidden="true"></div>
        <div class="topbar-user">
          <span class="user-name">{{ instructorName() }}</span>
          <button mat-icon-button aria-label="Wyloguj się" matTooltip="Wyloguj" (click)="logout()">
            <span class="material-symbols-outlined" aria-hidden="true">logout</span>
          </button>
        </div>
      </header>

      <div class="app-body">
        @if (sidebarOpen()) {
          <div class="sidebar-backdrop" role="presentation" aria-hidden="true" (click)="closeSidebar()"></div>
        }
        <nav class="sidebar" [class.open]="sidebarOpen()" aria-label="Nawigacja panelu prowadzącego" id="sidebar-nav">
          <div class="sidebar-user-card">
            <div class="sidebar-avatar" aria-hidden="true">{{ instructorInitials() }}</div>
            <div class="sidebar-user-info">
              <span class="sidebar-user-name">{{ instructorName() }}</span>
            </div>
          </div>
          @if (courseCtx.courses().length > 1) {
            <button type="button" class="course-picker-btn" (click)="openCoursePicker()">
              <span class="material-symbols-outlined" aria-hidden="true">groups</span>
              <span class="course-picker-label">{{ activeCourseLabel() }}</span>
              <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
            </button>
          }
          <hr class="k-divider" aria-hidden="true">
          <ul role="list" style="margin:0;padding:0;list-style:none;">
            @for (item of NAV_ITEMS; track item.path) {
              <li role="presentation">
                <a [routerLink]="'/prowadzacy/' + item.path" routerLinkActive="active" class="nav-item"
                   [attr.aria-label]="item.label" (click)="closeSidebar()">
                  <span class="material-symbols-outlined" aria-hidden="true">{{ item.icon }}</span>
                  <span>{{ item.label }}</span>
                </a>
              </li>
            }
          </ul>
          <div class="sidebar-footer">
            <a class="nav-item nav-item--switch" href="/karty30/ti/dydaktyk/login.php"
               aria-label="Zmień interfejs — przejdź do klasycznego panelu">
              <span class="material-symbols-outlined" aria-hidden="true">swap_horiz</span>
              <span>Klasyczny panel</span>
            </a>
            <button class="nav-item" (click)="logout()" aria-label="Wyloguj się z panelu">
              <span class="material-symbols-outlined" aria-hidden="true">logout</span>
              <span>Wyloguj</span>
            </button>
          </div>
        </nav>

        <main id="main-content" tabindex="-1">
          <router-outlet />
        </main>
      </div>
    </div>
  `,
  styles: [`
    .topbar {
      display: flex; align-items: center; gap: .5rem; height: 56px; min-height: 56px;
      padding: 0 1rem; background: #ffffff; border-bottom: 1px solid #e5e7eb; z-index: 100;
    }
    .topbar-hamburger { @media (min-width: 901px) { display: none; } }
    .topbar-brand {
      display: flex; align-items: center; gap: .5rem; text-decoration: none;
      color: #111827; font-weight: 600; font-size: 1rem; flex-shrink: 0;
      .brand-icon { color: #2563eb; font-size: 1.4rem; }
    }
    .topbar-spacer { flex: 1; }
    .topbar-user {
      display: flex; align-items: center; gap: .5rem; padding-left: .5rem; border-left: 1px solid #e5e7eb;
      .user-name { font-size: .85rem; color: #6b7280; @media (max-width: 600px) { display: none; } }
    }
    .sidebar-user-card { display: flex; align-items: center; gap: .875rem; padding: 1.25rem 1rem 1rem; }
    .sidebar-avatar {
      width: 2.5rem; height: 2.5rem; border-radius: 50%;
      background: linear-gradient(135deg, #2563eb, #3b82f6); color: #fff;
      display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .9rem; flex-shrink: 0;
    }
    .sidebar-user-name { font-weight: 600; font-size: .9rem; display: block; color: #111827; }
    .course-picker-btn {
      display: flex; align-items: center; gap: .5rem; width: calc(100% - 2rem); margin: 0 1rem .75rem;
      padding: .5rem .65rem; border: 1px solid #e5e7eb; border-radius: .6rem; background: #f9fafb;
      color: #374151; font: inherit; font-size: .85rem; cursor: pointer;
      .material-symbols-outlined:first-child { color: #6b7280; font-size: 1.1rem; }
      .material-symbols-outlined:last-child { margin-left: auto; color: #9ca3af; font-size: 1.1rem; }
      &:hover { background: #f3f4f6; }
    }
    .course-picker-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; text-align: left; }
    .sidebar-footer { margin-top: auto; padding: .5rem 0 1rem; border-top: 1px solid #e5e7eb; }
    main#main-content { flex: 1; overflow-y: auto; padding: 1.75rem 2rem; }
    @media (max-width: 768px) { main#main-content { padding: 1rem; } }
  `],
})
export class InstructorShellComponent implements OnInit {
  private auth   = inject(InstructorAuthService);
  private dialog = inject(MatDialog);
  courseCtx      = inject(InstructorCourseContextService);

  instructor  = this.auth.instructor;
  sidebarOpen = signal(false);

  ngOnInit(): void {
    this.courseCtx.ensureLoaded();
  }

  activeCourseLabel = computed(() => {
    const id = this.courseCtx.selectedId();
    if (id === null) return '— wszystkie grupy —';
    return this.courseCtx.courses().find(c => c.id === id)?.name ?? '— wszystkie grupy —';
  });

  openCoursePicker(): void {
    this.dialog.open(CoursePickerDialogComponent, {
      width: '360px', maxWidth: '95vw',
      data: { courses: this.courseCtx.courses(), selectedId: this.courseCtx.selectedId() },
    }).afterClosed().subscribe((result?: { picked: boolean; id: number | null }) => {
      if (result?.picked) this.courseCtx.selectedId.set(result.id);
    });
  }

  readonly NAV_ITEMS: NavItem[] = [
    { path: 'pulpit', label: 'Pulpit', icon: 'home' },
    { path: 'lekcje', label: 'Lekcje', icon: 'calendar_month' },
    { path: 'zadania', label: 'Zadania', icon: 'assignment' },
    { path: 'materialy', label: 'Materiały', icon: 'collections_bookmark' },
    { path: 'wiadomosci', label: 'Wiadomości', icon: 'forum' },
    { path: 'formalnosci', label: 'Formalności', icon: 'badge' },
  ];

  instructorName = computed(() => this.instructor()?.name ?? '');

  instructorInitials = computed(() => {
    const name = this.instructorName().trim();
    const parts = name.split(/\s+/);
    return parts.length >= 2
      ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
      : name.slice(0, 2).toUpperCase();
  });

  toggleSidebar() { this.sidebarOpen.update(v => !v); }
  closeSidebar()  { this.sidebarOpen.set(false); }

  @HostListener('document:keydown.escape')
  onEscape() { this.closeSidebar(); }

  logout() { this.auth.logout(); }
}
