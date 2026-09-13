import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import {
  ApiResponse, InstructorDashboard, InstructorLessonRow, InstructorAttendanceEntry,
  InstructorHomework, InstructorHomeworkDetail, InstructorMaterial,
  InstructorRoom, InstructorRescheduleRequest,
  InstructorMessageThreads, InstructorAdminMessage, Message,
  InstructorFormalnosci, InstructorHelpdeskTicket,
  InstructorOwnCloudStatus, InstructorOwnCloudReveal, InstructorZoomBusy,
  InstructorProtocolPending, InstructorProtocolClosed, InstructorProtocolSummary,
  InstructorAttendanceTrendPoint,
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
  getCourses() { return this.get<{ id: number; name: string }[]>('courses'); }

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

  /** Masowe tworzenie N lekcji wg wzorca (co M tygodni / N-ty dzień tygodnia miesiąca). */
  saveLessonSeries(payload: {
    course_id: number; lesson_date: string; time_from: string; time_to: string; topic: string;
    lesson_method: '' | 'stacjonarna' | 'zdalna_zoom' | 'zdalna_inne'; meeting_url: string; room_id: number | null;
    recur_mode: 'weekly' | 'monthly'; weeks: number; recur_position: string; recur_dow: number;
    end_mode: 'count' | 'until' | 'hours'; count: number; until: string; target_hours: number;
  }) {
    return this.post<void>('save_lesson_series', payload);
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
  homeworkFileUrl(homeworkId: number): string {
    return `${API}?action=homework_file&id=${homeworkId}&token=${encodeURIComponent(this.auth.token() ?? '')}`;
  }
  /** FormData: homework_id (edycja, opcjonalnie), course_id, title, description, hint,
   *  due_at, session_id, open_at, close_at, is_active, notify, attach (plik, opcjonalnie). */
  saveHomework(fd: FormData) {
    return this.post<void>('save_homework', fd);
  }
  deleteHomework(homeworkId: number) {
    return this.post<void>('delete_homework', { homework_id: homeworkId });
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

  getMessageThreads() { return this.get<InstructorMessageThreads>('message_threads'); }
  getStudentThread(accountId: number) {
    return this.get<Message[]>('message_thread', { kind: 'student', id: String(accountId) });
  }
  getAdminThread(toAdminId: number) {
    return this.get<InstructorAdminMessage[]>('message_thread', { kind: 'admin', id: String(toAdminId) });
  }
  sendStudentMessage(accountId: number, body: string, subject = '') {
    return this.post<void>('send_message', { kind: 'student', account_id: accountId, body, subject });
  }
  sendAdminMessage(toAdminId: number, body: string, subject = '') {
    return this.post<void>('send_message', { kind: 'admin', to_admin_id: toAdminId, body, subject });
  }

  getFormalnosci() { return this.get<InstructorFormalnosci>('formalnosci'); }
  updateContact(phoneNumber: string, altEmail: string, shareContact: boolean) {
    return this.post<void>('update_contact', { phone_number: phoneNumber, alt_email: altEmail, share_contact: shareContact });
  }

  private downloadUrl(action: string, params: Record<string, string>): string {
    const p = new URLSearchParams({ action, token: this.auth.token() ?? '', ...params });
    return `${API}?${p.toString()}`;
  }
  /** Plan zajęć (siatka) — PDF, własny plan prowadzącego. */
  planPdfUrl(weeks: number): string {
    return this.downloadUrl('plan_pdf', { weeks: String(weeks) });
  }
  /** Eksport CSV frekwencji za dany miesiąc (opcjonalnie jedna grupa). */
  attendanceCsvUrl(month: string, courseId: number | null): string {
    return this.downloadUrl('attendance_csv', courseId ? { month, course_id: String(courseId) } : { month });
  }

  getHelpdeskTickets() { return this.get<InstructorHelpdeskTicket[]>('helpdesk_tickets'); }
  createHelpdeskTicket(fd: FormData) {
    return this.post<void>('helpdesk_create', fd);
  }

  getOwnCloudStatus() { return this.get<InstructorOwnCloudStatus>('owncloud_status'); }
  ownCloudCreate()   { return this.post<InstructorOwnCloudReveal>('owncloud_create'); }
  ownCloudReset()    { return this.post<InstructorOwnCloudReveal>('owncloud_reset'); }
  ownCloudRecreate() { return this.post<InstructorOwnCloudReveal>('owncloud_recreate'); }

  getZoomBusy(month: string) { return this.get<InstructorZoomBusy>('zoom_busy', { month }); }

  getProtocolsPending() { return this.get<InstructorProtocolPending[]>('protocols_pending'); }
  getProtocolsClosed() { return this.get<InstructorProtocolClosed[]>('protocols_closed'); }
  getProtocolSummary(courseId: number, yearMonth: string) {
    return this.get<InstructorProtocolSummary>('protocol_summary', { course_id: String(courseId), year_month: yearMonth });
  }
  approveProtocol(courseId: number, yearMonth: string) {
    return this.post<void>('protocol_approve', { course_id: courseId, year_month: yearMonth });
  }

  getAttendanceTrend(months = 6, courseId?: number | null) {
    return this.get<InstructorAttendanceTrendPoint[]>('attendance_trend', {
      months: String(months), ...(courseId ? { course_id: String(courseId) } : {}),
    });
  }

  /** Wydruk PDF protokołu — istniejący (zamknięty) po protocol_id, albo jeszcze-nie-utworzony (otwarty) po course_id+year_month. */
  protocolPdfUrlById(protocolId: number): string {
    return this.downloadUrl('protocol_pdf', { id: String(protocolId) });
  }
  protocolPdfUrlForMonth(courseId: number, yearMonth: string): string {
    return this.downloadUrl('protocol_pdf', { course_id: String(courseId), year_month: yearMonth });
  }
}
