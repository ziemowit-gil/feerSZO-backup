import { Component, signal, inject } from '@angular/core';
import { BreakpointObserver, Breakpoints } from '@angular/cdk/layout';
import { Router, RouterLink, RouterLinkActive, RouterOutlet, NavigationEnd } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { map, filter, startWith } from 'rxjs';
import { CommonModule } from '@angular/common';
import { MatSidenavModule } from '@angular/material/sidenav';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatIconModule } from '@angular/material/icon';
import { MatButtonModule } from '@angular/material/button';
import { MatListModule } from '@angular/material/list';
import { MatExpansionModule } from '@angular/material/expansion';
import { MatTooltipModule } from '@angular/material/tooltip';
import { MatMenuModule } from '@angular/material/menu';
import { MatDividerModule } from '@angular/material/divider';

import { NAV_TREE, NavItem } from '../nav/nav.model';
import { AppConfigService } from '../services/app-config.service';

const ALL_LEAVES = NAV_TREE.flatMap(n => n.children?.length ? n.children : [n]);

function urlToLabel(url: string): string {
  const clean = url.split('?')[0];
  return ALL_LEAVES.find(n => n.route && clean.startsWith(n.route))?.label ?? '';
}

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [
    CommonModule, RouterOutlet, RouterLink, RouterLinkActive,
    MatSidenavModule, MatToolbarModule, MatIconModule,
    MatButtonModule, MatListModule, MatExpansionModule,
    MatTooltipModule, MatMenuModule, MatDividerModule,
  ],
  templateUrl: './shell.component.html',
  styleUrl: './shell.component.scss',
})
export class ShellComponent {
  readonly nav = NAV_TREE;

  private bp     = inject(BreakpointObserver);
  private router = inject(Router);
  readonly cfg   = inject(AppConfigService);

  readonly isHandset = toSignal(
    this.bp.observe(Breakpoints.Handset).pipe(map(r => r.matches)),
    { initialValue: false }
  );

  readonly sidenavOpened = signal(true);

  readonly pageLabel = toSignal(
    this.router.events.pipe(
      filter(e => e instanceof NavigationEnd),
      map((e: NavigationEnd) => urlToLabel(e.urlAfterRedirects)),
      startWith(urlToLabel(this.router.url))
    ),
    { initialValue: urlToLabel(this.router.url) }
  );

  toggleSidenav() {
    this.sidenavOpened.update(v => !v);
  }

  isGroup(item: NavItem): boolean {
    return !!item.children?.length;
  }
}
