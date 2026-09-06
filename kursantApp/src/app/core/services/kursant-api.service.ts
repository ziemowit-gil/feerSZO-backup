import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import {
  ApiResponse, DashboardData, Lesson, DydGroup, GradesByCourse,
  CurriculumItem, TestItem, Notice, Message, BillingData,
  OnlineState, OwnCloudState, VlabServer, License, ActivityLogEntry,
  Term, AuthorizedPerson, YearEndOverpayInfo,
} from '../models/kursant.models';

const API = '/api/v1/kursant_student.php';

@Injectable({ providedIn: 'root' })
export class KursantApiService {
  private http = inject(HttpClient);

  private get<T>(action: string, params: Record<string, string> = {}): Observable<ApiResponse<T>> {
    let p = new HttpParams().set('action', action);
    for (const [k, v] of Object.entries(params)) p = p.set(k, v);
    return this.http.get<ApiResponse<T>>(API, { params: p });
  }

  private post<T>(action: string, body: unknown = {}): Observable<ApiResponse<T>> {
    return this.http.post<ApiResponse<T>>(`${API}?action=${action}`, body);
  }

  // ── Read endpoints ─────────────────────────────────────
  getDashboard()  { return this.get<DashboardData>('dashboard'); }
  getLessons(page = '1') { return this.get<Lesson[]>('lessons', { page }); }
  getHomework()   { return this.get<DydGroup[]>('homework'); }
  getGrades()     { return this.get<GradesByCourse[]>('grades'); }
  getCurriculum() { return this.get<CurriculumItem[]>('curriculum'); }
  getTests()      { return this.get<TestItem[]>('tests'); }
  getNotices()    { return this.get<Notice[]>('notices'); }
  getMessages()   { return this.get<Message[]>('messages'); }
  getBilling()    { return this.get<BillingData>('billing'); }
  getYearEndOverpayInfo() { return this.get<YearEndOverpayInfo>('year_end_overpay_info'); }
  getOnline()     { return this.get<OnlineState>('online'); }
  getVlab()       { return this.get<VlabServer[]>('vlab'); }
  getOwnCloud()   { return this.get<OwnCloudState>('owncloud'); }
  getLicenses()   { return this.get<License[]>('licenses'); }
  getActivity()   { return this.get<ActivityLogEntry[]>('activity'); }
  getTerms()      { return this.get<Term[]>('terms'); }
  getAuthorized() { return this.get<AuthorizedPerson[]>('authorized'); }

  // ── Write endpoints ────────────────────────────────────
  cancelLesson(lesson_id: number, reason?: string) {
    return this.post<void>('cancel_lesson', { lesson_id, reason });
  }
  uncancelLesson(lesson_id: number) {
    return this.post<void>('uncancel_lesson', { lesson_id });
  }
  rateLesson(lesson_id: number, rating: number, comment: string) {
    return this.post<void>('rate_lesson', { lesson_id, rating, comment });
  }
  proposeReschedule(lesson_id: number, proposed_date: string, proposed_time: string) {
    return this.post<void>('propose_reschedule', { lesson_id, proposed_date, proposed_time });
  }
  submitHomework(homework_id: number, body: string, file?: File): Observable<ApiResponse<void>> {
    const fd = new FormData();
    fd.append('homework_id', String(homework_id));
    fd.append('body', body);
    if (file) fd.append('file', file);
    return this.http.post<ApiResponse<void>>(`${API}?action=submit_homework`, fd);
  }
  sendMessage(subject: string, body: string) {
    return this.post<void>('send_message', { subject, body });
  }
  markMessagesRead() { return this.post<void>('mark_messages_read'); }
  markNoticeRead(notice_id: number) {
    return this.post<void>('mark_notice_read', { notice_id });
  }
  acceptTerm(term_id: number) { return this.post<void>('accept_term', { term_id }); }
  changePassword(current: string, newPwd: string) {
    return this.post<void>('change_password', { current_password: current, new_password: newPwd });
  }
  setAlias(alias: string) { return this.post<void>('set_alias', { alias }); }
  changeEmail(email: string) { return this.post<void>('change_email', { email }); }
  updateSettings(prefs: Record<string, number | string>) {
    return this.post<void>('update_settings', prefs);
  }
  reportIssue(subject: string, body: string) {
    return this.post<void>('report_issue', { subject, body });
  }
  resetCalendarToken() { return this.post<{ ical: string; gcal: string }>('cal_token_reset'); }
  provisionOwnCloud()  { return this.post<void>('owncloud_create'); }
  resetOwnCloud()      { return this.post<void>('owncloud_reset'); }
  yearEndOverpay(amount: number, provider: string) {
    return this.post<{ url: string }>('year_end_overpay', { amount, provider });
  }
  yearEndDeclareTransfer(amount: number, note: string) {
    return this.post<{ ok: boolean }>('year_end_declare_transfer', { amount, note });
  }
  orderVlabServer()    { return this.post<void>('order_dedicated_server'); }
  cancelVlabServer(server_id: number) {
    return this.post<void>('cancel_dedicated_server', { server_id });
  }
}
