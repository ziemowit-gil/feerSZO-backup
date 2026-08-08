import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { ApiService } from './api.service';
import {
  Session, Room, TechPath, Draft, DraftDiff, SessionStaff, Laptop, OnlineMeeting,
  CycleTemplate, TokenWallet, TokenTransaction, TokenPrice, Basket, ConflictResult, AuditEntry,
  PaginatedResponse, ApiResponse, CheckoutResult
} from '../models/planner.models';

@Injectable({ providedIn: 'root' })
export class PlannerService {
  constructor(private api: ApiService) {}

  // ── ROOMS ─────────────────────────────────────────────────────────────
  getRooms(params: Record<string, string> = {}): Observable<Room[]> {
    return this.api.get<ApiResponse<Room[]>>('rooms', params).pipe(map(r => r.data));
  }
  getRoom(id: number): Observable<Room> {
    return this.api.get<ApiResponse<Room>>('rooms', { id: String(id) }).pipe(map(r => r.data));
  }
  createRoom(room: Partial<Room>): Observable<Room> {
    return this.api.post<ApiResponse<Room>>('rooms', room as Record<string, unknown>).pipe(map(r => r.data));
  }
  updateRoom(id: number, room: Partial<Room>): Observable<Room> {
    return this.api.put<ApiResponse<Room>>('rooms', id, room as Record<string, unknown>).pipe(map(r => r.data));
  }
  getRoomAvailability(id: number, date: string, from: string, to: string): Observable<Session[]> {
    return this.api.get<ApiResponse<Session[]>>('rooms', { id: String(id), action: 'availability', date, from, to }).pipe(map(r => r.data));
  }

  // ── LAPTOPS ───────────────────────────────────────────────────────────
  getLaptops(): Observable<Laptop[]> {
    return this.api.get<ApiResponse<Laptop[]>>('laptops').pipe(map(r => r.data));
  }
  loanLaptop(id: number, session_id: number, client_id?: number): Observable<unknown> {
    return this.api.post('laptops', { session_id, client_id }, { id: String(id), action: 'loan' });
  }
  returnLaptop(id: number): Observable<unknown> {
    return this.api.put('laptops', id, { action: 'return' });
  }

  // ── MEETINGS ──────────────────────────────────────────────────────────
  getMeeting(sessionId: number): Observable<OnlineMeeting | null> {
    return this.api.get<ApiResponse<OnlineMeeting | null>>('meetings', { id: String(sessionId) }).pipe(map(r => r.data));
  }
  saveMeeting(sessionId: number, meeting: Partial<OnlineMeeting>): Observable<unknown> {
    return this.api.post('meetings', meeting as Record<string, unknown>, { id: String(sessionId) });
  }
  deleteMeeting(sessionId: number): Observable<unknown> {
    return this.api.delete('meetings', sessionId);
  }

  // ── TECH PATHS ────────────────────────────────────────────────────────
  getTechPaths(): Observable<TechPath[]> {
    return this.api.get<ApiResponse<TechPath[]>>('tech-paths').pipe(map(r => r.data));
  }
  createTechPath(path: Partial<TechPath>): Observable<TechPath> {
    return this.api.post<ApiResponse<TechPath>>('tech-paths', path as Record<string, unknown>).pipe(map(r => r.data));
  }
  updateTechPath(id: number, path: Partial<TechPath>): Observable<TechPath> {
    return this.api.put<ApiResponse<TechPath>>('tech-paths', id, path as Record<string, unknown>).pipe(map(r => r.data));
  }

  // ── SESSIONS ──────────────────────────────────────────────────────────
  getSessions(params: Record<string, string> = {}): Observable<PaginatedResponse<Session>> {
    return this.api.get<PaginatedResponse<Session>>('sessions', params);
  }
  getSessionsForWeek(dateFrom: string, dateTo: string, draftId?: number | null, courseId?: number | null): Observable<Session[]> {
    const params: Record<string, string> = { date_from: dateFrom, date_to: dateTo, limit: '500' };
    if (draftId != null) params['draft'] = String(draftId);
    else params['draft'] = '';
    if (courseId) params['course'] = String(courseId);
    return this.getSessions(params).pipe(map(r => r.data));
  }
  createSession(session: Partial<Session>): Observable<Session> {
    return this.api.post<ApiResponse<Session>>('sessions', session as Record<string, unknown>).pipe(map(r => r.data));
  }
  updateSession(id: number, session: Partial<Session>): Observable<Session> {
    return this.api.put<ApiResponse<Session>>('sessions', id, session as Record<string, unknown>).pipe(map(r => r.data));
  }
  checkConflicts(params: Partial<Session> & { skip_id?: number; staff_ids?: number[] }): Observable<ConflictResult> {
    return this.api.post<ApiResponse<ConflictResult>>('check-conflicts', params as Record<string, unknown>).pipe(map(r => r.data));
  }
  getSessionStaff(sessionId: number): Observable<SessionStaff[]> {
    return this.api.get<ApiResponse<SessionStaff[]>>('sessions', { id: String(sessionId), action: 'conflicts' }).pipe(map(r => r.data));
  }
  addSessionStaff(sessionId: number, user_id: number, role: string): Observable<SessionStaff[]> {
    return this.api.post<ApiResponse<SessionStaff[]>>('sessions', { user_id, role }, { id: String(sessionId), action: 'staff' }).pipe(map(r => r.data));
  }

  // ── INSTRUCTORS ───────────────────────────────────────────────────────
  getInstructorWorkload(userId: number, week: string): Observable<unknown> {
    return this.api.get('instructors', { id: String(userId), action: 'workload', week });
  }
  getInstructorAvailability(userId: number, week: string): Observable<Session[]> {
    return this.api.get<ApiResponse<Session[]>>('instructors', { id: String(userId), action: 'availability', week }).pipe(map(r => r.data));
  }

  // ── DRAFTS ────────────────────────────────────────────────────────────
  getDrafts(): Observable<Draft[]> {
    return this.api.get<ApiResponse<Draft[]>>('drafts').pipe(map(r => r.data));
  }
  getDraft(id: number): Observable<Draft> {
    return this.api.get<ApiResponse<Draft>>('drafts', { id: String(id) }).pipe(map(r => r.data));
  }
  createDraft(data: { title: string }): Observable<Draft> {
    return this.api.post<ApiResponse<Draft>>('drafts', data).pipe(map(r => r.data));
  }
  updateDraft(id: number, data: Partial<Draft>): Observable<Draft> {
    return this.api.put<ApiResponse<Draft>>('drafts', id, data as Record<string, unknown>).pipe(map(r => r.data));
  }
  forkDraft(id: number, data: { title?: string } = {}): Observable<Draft> {
    return this.api.post<ApiResponse<Draft>>('drafts', data, { id: String(id), action: 'fork' }).pipe(map(r => r.data));
  }
  publishDraft(id: number): Observable<Draft> {
    return this.api.post<ApiResponse<Draft>>('drafts', {}, { id: String(id), action: 'publish' }).pipe(map(r => r.data));
  }
  diffDraft(id: number, baseId: number): Observable<DraftDiff> {
    return this.api.get<ApiResponse<DraftDiff>>('drafts', { id: String(id), action: 'diff', base_id: String(baseId) }).pipe(map(r => r.data));
  }

  // ── CYCLE TEMPLATES ───────────────────────────────────────────────────
  getCycleTemplates(): Observable<CycleTemplate[]> {
    return this.api.get<ApiResponse<CycleTemplate[]>>('cycle-templates').pipe(map(r => r.data));
  }
  createCycleTemplate(data: Partial<CycleTemplate>): Observable<unknown> {
    return this.api.post('cycle-templates', data as Record<string, unknown>);
  }
  expandCycleTemplate(id: number, course: number, start: string): Observable<Partial<Session>[]> {
    return this.api.post<ApiResponse<Partial<Session>[]>>('cycle-templates', { course, start }, { id: String(id), action: 'expand' }).pipe(map(r => r.data));
  }

  // ── AUDIT ─────────────────────────────────────────────────────────────
  getAudit(params: Record<string, string> = {}): Observable<AuditEntry[]> {
    return this.api.get<ApiResponse<AuditEntry[]>>('audit', params).pipe(map(r => r.data));
  }

  // ── TOKENS ────────────────────────────────────────────────────────────
  getWallet(clientId: number): Observable<TokenWallet> {
    return this.api.get<ApiResponse<TokenWallet>>('wallet', { client_id: String(clientId) }).pipe(map(r => r.data));
  }
  grantTokens(data: { client_id: number; amount: number; note?: string }): Observable<TokenWallet> {
    return this.api.post<ApiResponse<TokenWallet>>('wallet', data, { action: 'grant' }).pipe(map(r => r.data));
  }
  getTransactions(walletId: number, params: Record<string, string> = {}): Observable<TokenTransaction[]> {
    return this.api.get<ApiResponse<TokenTransaction[]>>('wallet', { id: String(walletId), action: 'transactions', ...params }).pipe(map(r => r.data));
  }
  getPrices(params: Record<string, string> = {}): Observable<TokenPrice[]> {
    return this.api.get<ApiResponse<TokenPrice[]>>('prices', params).pipe(map(r => r.data));
  }
  createPrice(price: Partial<TokenPrice>): Observable<TokenPrice> {
    return this.api.post<ApiResponse<TokenPrice>>('prices', price as Record<string, unknown>).pipe(map(r => r.data));
  }
  updatePrice(id: number, price: Partial<TokenPrice>): Observable<TokenPrice> {
    return this.api.put<ApiResponse<TokenPrice>>('prices', id, price as Record<string, unknown>).pipe(map(r => r.data));
  }
  deletePrice(id: number): Observable<unknown> {
    return this.api.delete('prices', id);
  }

  // ── BASKET ────────────────────────────────────────────────────────────
  getBasket(clientId: number): Observable<Basket | null> {
    return this.api.get<ApiResponse<Basket | null>>('basket', { client_id: String(clientId) }).pipe(map(r => r.data));
  }
  addToBasket(clientId: number, session_id: number, mentor_id?: number): Observable<Basket> {
    return this.api.post<ApiResponse<Basket>>('basket', { client_id: clientId, session_id, mentor_id }, { action: 'add-item' }).pipe(map(r => r.data));
  }
  removeFromBasket(clientId: number, itemId: number): Observable<Basket> {
    return this.api.delete<ApiResponse<Basket>>('basket', itemId, { client_id: String(clientId) }).pipe(map(r => r.data));
  }
  checkoutBasket(clientId: number): Observable<CheckoutResult> {
    return this.api.post<ApiResponse<CheckoutResult>>('basket', { client_id: clientId }, { action: 'checkout' }).pipe(map(r => r.data));
  }
  cancelBasket(clientId: number): Observable<unknown> {
    return this.api.post('basket', { client_id: clientId }, { action: 'cancel' });
  }
}
