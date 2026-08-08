import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';

export interface ApiConfig {
  baseUrl: string;
  apiKey: string;
}

const STORAGE_KEY = 'planner_api_config';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private config: ApiConfig = { baseUrl: '', apiKey: '' };

  constructor(private http: HttpClient) {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved) this.config = JSON.parse(saved);
  }

  getConfig(): ApiConfig { return { ...this.config }; }
  isConfigured(): boolean { return !!this.config.apiKey && !!this.config.baseUrl; }

  saveConfig(config: ApiConfig): void {
    this.config = { ...config };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(this.config));
  }

  private headers(): HttpHeaders {
    return new HttpHeaders({ Authorization: `Bearer ${this.config.apiKey}` });
  }

  private url(resource: string, params: Record<string, string> = {}): string {
    const base = this.config.baseUrl.replace(/\/$/, '');
    let p = new HttpParams().set('r', resource);
    Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') p = p.set(k, v); });
    return `${base}?${p.toString()}`;
  }

  get<T>(resource: string, params: Record<string, string> = {}): Observable<T> {
    return this.http.get<T>(this.url(resource, params), { headers: this.headers() }).pipe(catchError(this.handleError));
  }

  post<T>(resource: string, body: Record<string, unknown>, params: Record<string, string> = {}): Observable<T> {
    return this.http.post<T>(this.url(resource, params), body, { headers: this.headers() }).pipe(catchError(this.handleError));
  }

  put<T>(resource: string, id: number, body: Record<string, unknown>): Observable<T> {
    return this.http.put<T>(this.url(resource, { id: String(id) }), body, { headers: this.headers() }).pipe(catchError(this.handleError));
  }

  delete<T>(resource: string, id: number, params: Record<string, string> = {}): Observable<T> {
    return this.http.delete<T>(this.url(resource, { id: String(id), ...params }), { headers: this.headers() }).pipe(catchError(this.handleError));
  }

  private handleError(error: { status: number; error: { error?: string; msg?: string } }) {
    const msg = error?.error?.msg ?? error?.error?.error ?? 'Błąd API';
    return throwError(() => ({ status: error.status, message: msg, raw: error.error }));
  }
}
