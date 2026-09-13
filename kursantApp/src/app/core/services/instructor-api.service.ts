import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import {
  ApiResponse, InstructorDashboard, InstructorLessonRow, InstructorAttendanceEntry,
} from '../models/kursant.models';

const API = '/api/v1/dydaktyk_instructor.php';

@Injectable({ providedIn: 'root' })
export class InstructorApiService {
  private http = inject(HttpClient);

  private get<T>(action: string, params: Record<string, string> = {}): Observable<ApiResponse<T>> {
    let p = new HttpParams().set('action', action);
    for (const [k, v] of Object.entries(params)) p = p.set(k, v);
    return this.http.get<ApiResponse<T>>(API, { params: p });
  }

  private post<T>(action: string, body: unknown = {}): Observable<ApiResponse<T>> {
    return this.http.post<ApiResponse<T>>(`${API}?action=${action}`, body);
  }

  getDashboard() { return this.get<InstructorDashboard>('dashboard'); }

  getLessons(courseId?: number) {
    return this.get<InstructorLessonRow[]>('lessons', courseId ? { course_id: String(courseId) } : {});
  }
  getSessionAttendance(sessionId: number) {
    return this.get<InstructorAttendanceEntry[]>('session_attendance', { session_id: String(sessionId) });
  }
  markAttendance(sessionId: number, attended: number[]) {
    return this.post<void>('mark_attendance', { session_id: sessionId, attended });
  }
  cancelLesson(sessionId: number, reason: string) {
    return this.post<void>('cancel_lesson', { session_id: sessionId, reason });
  }
  uncancelLesson(sessionId: number) {
    return this.post<void>('uncancel_lesson', { session_id: sessionId });
  }
}
