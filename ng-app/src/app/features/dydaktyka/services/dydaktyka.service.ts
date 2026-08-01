import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { Course, Session, Grade, Attendance, Enrollment } from '../models/dydaktyka.model';
import { AppConfigService } from '../../../core/services/app-config.service';

/** Wrapper zgodny z karty30.php (GET lista: { data: T[], meta: {...} }) */
interface K30ListResp<T> { data: T[]; meta: { total: number; page: number; per_page: number } }
/** Wrapper zgodny z karty30.php (GET id: { data: T }) */
interface K30ItemResp<T> { data: T }
/** Wrapper odpowiedzi mutacji */
interface K30WriteResp { data: { id?: number }; meta?: { affected?: number } }

export interface PageResult<T> { ok: boolean; total: number; rows: T[] }

export interface SessionFilter {
  q?: string;
  course_id?: number;
  status?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

function p(extra: Record<string, string | number | undefined> = {}): HttpParams {
  let params = new HttpParams();
  for (const [k, v] of Object.entries(extra)) {
    if (v !== undefined && v !== null && v !== '') params = params.set(k, String(v));
  }
  return params;
}

@Injectable({ providedIn: 'root' })
export class DydaktykaService {
  private http   = inject(HttpClient);
  private config = inject(AppConfigService);
  private get BASE() { return this.config.apiUrl; }

  /* ── Kursy ─────────────────────────────────────────────────────── */
  getCourses(): Observable<Course[]> {
    return this.http
      .get<K30ListResp<Course>>(this.BASE, { params: p({ resource: 'courses', per_page: 200 }) })
      .pipe(map(r => r.data));
  }

  /* ── Lekcje (sessions → k30_ti_sessions zasób "lessons") ───────── */
  getSessions(filter: SessionFilter = {}): Observable<PageResult<Session>> {
    const params = p({
      resource: 'lessons',
      q:          filter.q,
      course_id:  filter.course_id,
      status:     filter.status,
      date_from:  filter.date_from,
      date_to:    filter.date_to,
      page:       filter.page ?? 0,
      per_page:   filter.per_page ?? 20,
    });
    return this.http.get<K30ListResp<Session>>(this.BASE, { params }).pipe(
      map(r => ({ ok: true, total: r.meta.total, rows: r.data }))
    );
  }

  getSession(id: number): Observable<Session> {
    return this.http
      .get<K30ItemResp<Session>>(this.BASE, { params: p({ resource: 'lessons', id }) })
      .pipe(map(r => r.data));
  }

  createSession(data: Partial<Session>): Observable<{ ok: boolean; id: number }> {
    return this.http
      .post<K30WriteResp>(this.BASE + '?resource=lessons', data)
      .pipe(map(r => ({ ok: true, id: r.data.id ?? 0 })));
  }

  updateSession(id: number, data: Partial<Session>): Observable<{ ok: boolean }> {
    return this.http
      .patch<K30WriteResp>(this.BASE + `?resource=lessons&id=${id}`, data)
      .pipe(map(() => ({ ok: true })));
  }

  deleteSession(id: number): Observable<{ ok: boolean }> {
    return this.http
      .delete<K30WriteResp>(this.BASE + `?resource=lessons&id=${id}`)
      .pipe(map(() => ({ ok: true })));
  }

  /* ── Oceny ─────────────────────────────────────────────────────── */
  getGrades(courseId: number, clientId?: number): Observable<Grade[]> {
    return this.http
      .get<K30ListResp<Grade>>(this.BASE, {
        params: p({ resource: 'grades', course_id: courseId, client_id: clientId, per_page: 500 })
      })
      .pipe(map(r => r.data));
  }

  /* ── Obecność ───────────────────────────────────────────────────── */
  getAttendance(sessionId: number): Observable<Attendance[]> {
    return this.http
      .get<K30ListResp<Attendance>>(this.BASE, {
        params: p({ resource: 'attendance', session_id: sessionId, per_page: 200 })
      })
      .pipe(map(r => r.data));
  }

  /* ── Zapisy kursantów ───────────────────────────────────────────── */
  getEnrollments(courseId: number): Observable<Enrollment[]> {
    return this.http
      .get<K30ListResp<Enrollment>>(this.BASE, {
        params: p({ resource: 'enrollments', course_id: courseId, per_page: 200 })
      })
      .pipe(map(r => r.data));
  }

  /* ── Materiały ──────────────────────────────────────────────────── */
  getMaterials(courseId: number): Observable<any[]> {
    return this.http
      .get<K30ListResp<any>>(this.BASE, {
        params: p({ resource: 'materials', course_id: courseId, per_page: 200 })
      })
      .pipe(map(r => r.data));
  }

  /* ── Zadania domowe ─────────────────────────────────────────────── */
  getHomework(courseId: number): Observable<any[]> {
    return this.http
      .get<K30ListResp<any>>(this.BASE, {
        params: p({ resource: 'homework', course_id: courseId, per_page: 200 })
      })
      .pipe(map(r => r.data));
  }

  /* ── Testy ──────────────────────────────────────────────────────── */
  getTests(courseId: number): Observable<any[]> {
    return this.http
      .get<K30ListResp<any>>(this.BASE, {
        params: p({ resource: 'tests', course_id: courseId, per_page: 200 })
      })
      .pipe(map(r => r.data));
  }
}
