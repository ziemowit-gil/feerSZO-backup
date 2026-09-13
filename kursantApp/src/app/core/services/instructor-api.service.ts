import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import {
  ApiResponse, InstructorDashboard, InstructorLessonRow, InstructorAttendanceEntry,
  InstructorHomework, InstructorHomeworkDetail, InstructorMaterial,
  InstructorRoom, InstructorRescheduleRequest,
} from '../models/kursant.models';
import { InstructorAuthService } from '../auth/instructor-auth.service';

const API = '/api/v1/dydaktyk_instructor.php';

@Injectable({ providedIn: 'root' })
export class InstructorApiService {
  private http = inject(HttpClient);
  private auth = inject(InstructorAuthService);

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
  cancelAttendee(sessionId: number, clientId: number, reason: string) {
    return this.post<void>('cancel_attendee', { session_id: sessionId, client_id: clientId, reason });
  }
  restoreAttendee(sessionId: number, clientId: number) {
    return this.post<void>('restore_attendee', { session_id: sessionId, client_id: clientId });
  }

  getRooms() { return this.get<InstructorRoom[]>('rooms'); }

  /** session_id=0/pominięte → nowa lekcja. */
  saveLesson(payload: {
    session_id?: number; course_id: number; lesson_date: string; time_from: string; time_to: string;
    topic: string; notes: string; has_homework: boolean; self_prep_remote: boolean;
    lesson_method: '' | 'stacjonarna' | 'zdalna_zoom' | 'zdalna_inne'; meeting_url: string;
    room_id: number | null; status?: string; notify?: boolean;
  }) {
    return this.post<void>('save_lesson', payload);
  }

  getReschedulePending(sessionId: number) {
    return this.get<InstructorRescheduleRequest[]>('reschedule_pending', { session_id: String(sessionId) });
  }
  rescheduleLesson(sessionId: number, date: string, timeFrom: string, timeTo: string, notify: boolean, notifySms: boolean) {
    return this.post<void>('reschedule_lesson', {
      session_id: sessionId, lesson_date: date, time_from: timeFrom, time_to: timeTo, notify, notify_sms: notifySms,
    });
  }
  rescheduleDecide(requestId: number, accept: boolean, note: string) {
    return this.post<void>('reschedule_decide', { request_id: requestId, accept, note });
  }

  getHomework(courseId?: number) {
    return this.get<InstructorHomework[]>('homework', courseId ? { course_id: String(courseId) } : {});
  }
  getHomeworkSubmissions(homeworkId: number) {
    return this.get<InstructorHomeworkDetail>('homework_submissions', { homework_id: String(homeworkId) });
  }
  gradeSubmission(submissionId: number, grade: string, feedback: string) {
    return this.post<void>('grade_submission', { submission_id: submissionId, grade, feedback });
  }

  getMaterials(courseId?: number) {
    return this.get<InstructorMaterial[]>('materials', courseId ? { course_id: String(courseId) } : {});
  }
  /**
   * Zwykły link `<a href>` (nie XHR) — nie może dołożyć nagłówka Authorization,
   * więc token API idzie tu jako ?token= (patrz komentarz w dydaktyk_instructor.php).
   */
  materialFileUrl(materialId: number): string {
    return `${API}?action=material_file&id=${materialId}&token=${encodeURIComponent(this.auth.token() ?? '')}`;
  }
  /** FormData: material_id (edycja, opcjonalnie), course_id, type, title, description,
   *  url, session_id, open_at, close_at, is_active, notify, attach (plik, opcjonalnie). */
  saveMaterial(fd: FormData) {
    return this.post<void>('save_material', fd);
  }
  deleteMaterial(materialId: number) {
    return this.post<void>('delete_material', { material_id: materialId });
  }
}
