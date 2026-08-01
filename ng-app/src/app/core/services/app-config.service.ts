import { Injectable } from '@angular/core';

export interface AppConfig {
  apiUrl:    string;
  appTitle:  string;
  orgName:   string;
  baseHref:  string;
}

const DEFAULTS: AppConfig = {
  apiUrl:   '/api/v1/karty30.php',
  appTitle: 'feerSZO — Panel',
  orgName:  'FEER',
  baseHref: '/newUI/',
};

@Injectable({ providedIn: 'root' })
export class AppConfigService {
  private cfg: AppConfig = { ...DEFAULTS };

  /** Ładuje /app.config.json przy starcie aplikacji. */
  async load(): Promise<void> {
    try {
      const r = await fetch('app.config.json');
      if (r.ok) {
        const json = await r.json();
        this.cfg = { ...DEFAULTS, ...json };
      }
    } catch { /* zostają defaults */ }
  }

  get apiUrl():   string { return this.cfg.apiUrl; }
  get appTitle(): string { return this.cfg.appTitle; }
  get orgName():  string { return this.cfg.orgName; }
  get baseHref(): string { return this.cfg.baseHref; }

  /** Zwraca kopię całej konfiguracji (np. dla ekranu ustawień). */
  getAll(): AppConfig { return { ...this.cfg }; }
}
